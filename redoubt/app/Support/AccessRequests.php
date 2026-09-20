<?php

declare(strict_types=1);

namespace Redoubt\Support;

use RuntimeException;

/**
 * Sponsored onboarding lifecycle: request → approve/deny → provision → offboard.
 *
 * A sponsor (access.request) submits a request for a new/existing person; an
 * approver (access.grant) approves, which provisions the app_user + program and
 * company membership (Entra B2B invite is a later Graph step — the account is
 * created 'invited' with the sponsor-attested US-person flag for the export gate).
 * Offboarding (access.revoke) removes program membership and, if the user has no
 * remaining memberships, marks the account removed. Every step is audited.
 */
final class AccessRequests
{
    public static function create(int $programId, array $d, ?int $sponsorId): int
    {
        $role = (string) ($d['requested_role'] ?? '');
        if (!isset(Roles::DEFAULTS[$role])) {
            throw new RuntimeException('Unknown role.');
        }
        $id = Db::insert('access_request', [
            'program_id'     => $programId,
            'email'          => strtolower(trim((string) ($d['email'] ?? ''))),
            'display_name'   => $d['display_name'] ?? null,
            'company_id'     => $d['company_id'] ?? null,
            'requested_role' => $role,
            'kind'           => in_array($d['kind'] ?? '', ['internal', 'external', 'customer'], true) ? $d['kind'] : 'external',
            'is_us_person'   => array_key_exists('is_us_person', $d) ? (bool) $d['is_us_person'] : null,
            'justification'  => $d['justification'] ?? null,
            'status'         => 'pending',
            'sponsor_id'     => $sponsorId,
            'expires_at'     => $d['expires_at'] ?? null,
        ]);
        Audit::log('access.request', 'request#' . $id . ' ' . ($d['email'] ?? ''), $programId, $sponsorId);
        return $id;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listForProgram(int $programId, ?string $status = null): array
    {
        $sql = 'SELECT ar.*, c.name AS company_name, s.display_name AS sponsor_name
                  FROM access_request ar
                  LEFT JOIN company c ON c.id = ar.company_id
                  LEFT JOIN app_user s ON s.id = ar.sponsor_id
                 WHERE ar.program_id = :p';
        $params = ['p' => $programId];
        if ($status !== null) {
            $sql .= ' AND ar.status = :s';
            $params['s'] = $status;
        }
        $sql .= ' ORDER BY ar.created_at DESC, ar.id DESC';
        return Db::fetchAll($sql, $params);
    }

    public static function deny(int $id, int $programId, ?int $deciderId): void
    {
        Db::update('access_request', ['status' => 'denied', 'decided_by' => $deciderId, 'decided_at' => date('c')], ['id' => $id, 'program_id' => $programId]);
        Audit::log('access.deny', 'request#' . $id, $programId, $deciderId);
    }

    /** Approve + provision the account and memberships. Returns the app_user id. */
    public static function approve(int $id, int $programId, ?int $deciderId): int
    {
        $req = Db::fetchOne('SELECT * FROM access_request WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        if ($req === null) {
            throw new RuntimeException('Request not found.');
        }
        $roleRow = Db::fetchOne('SELECT id FROM role WHERE key = :k', ['k' => $req['requested_role']]);
        if ($roleRow === null) {
            throw new RuntimeException('Role no longer exists.');
        }
        $roleId = (int) $roleRow['id'];
        $email = strtolower((string) $req['email']);
        $usPerson = $req['is_us_person'] === null ? null : (bool) $req['is_us_person'];

        // Find or create the account.
        $existing = Db::fetchOne('SELECT id FROM app_user WHERE lower(email) = :e', ['e' => $email]);
        if ($existing !== null) {
            $userId = (int) $existing['id'];
            $set = ['status' => 'active'];
            if ($usPerson !== null) {
                $set['is_us_person'] = $usPerson;
                $set['us_person_verified_at'] = date('c');
            }
            Db::update('app_user', $set, ['id' => $userId]);
        } else {
            $userId = Db::insert('app_user', [
                'display_name'          => $req['display_name'] ?: $email,
                'email'                 => $email,
                'kind'                  => $req['kind'],
                'is_us_person'          => $usPerson,
                'us_person_verified_at' => $usPerson !== null ? date('c') : null,
                'status'                => 'invited',   // pending Entra B2B acceptance
            ]);
        }

        // Program + company membership (idempotent).
        Db::query(
            'INSERT INTO program_membership (program_id, user_id, role_id, company_id, expires_at)
             VALUES (:p,:u,:r,:c,:e) ON CONFLICT (program_id, user_id, role_id) DO NOTHING',
            ['p' => $programId, 'u' => $userId, 'r' => $roleId, 'c' => $req['company_id'], 'e' => $req['expires_at']]
        );
        if ($req['company_id'] !== null) {
            Db::query(
                'INSERT INTO company_membership (company_id, user_id) VALUES (:c,:u) ON CONFLICT (company_id, user_id) DO NOTHING',
                ['c' => $req['company_id'], 'u' => $userId]
            );
        }

        Db::update('access_request', [
            'status' => 'provisioned', 'decided_by' => $deciderId, 'decided_at' => date('c'), 'provisioned_user_id' => $userId,
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('access.provision', 'request#' . $id . ' -> user#' . $userId, $programId, $deciderId);
        return $userId;
    }

    /** Current members of a program (for the roster / offboarding). @return array<int,array<string,mixed>> */
    public static function roster(int $programId): array
    {
        return Db::fetchAll(
            "SELECT u.id, u.display_name, u.email, u.kind, u.status, u.is_us_person,
                    string_agg(DISTINCT r.key, ',' ORDER BY r.key) AS roles, c.name AS company_name
               FROM program_membership pm
               JOIN app_user u ON u.id = pm.user_id
               JOIN role r ON r.id = pm.role_id
               LEFT JOIN company c ON c.id = pm.company_id
              WHERE pm.program_id = :p
              GROUP BY u.id, u.display_name, u.email, u.kind, u.status, u.is_us_person, c.name
              ORDER BY u.display_name",
            ['p' => $programId]
        );
    }

    /**
     * Offboard a user from a program: remove their program membership; if they
     * have no remaining program memberships anywhere, mark the account removed.
     * (Entra access revocation + session kill is a follow-on Graph/Entra step.)
     */
    public static function offboard(int $programId, int $userId, ?int $actorId): void
    {
        Db::query('DELETE FROM program_membership WHERE program_id = :p AND user_id = :u', ['p' => $programId, 'u' => $userId]);
        $remaining = (int) (Db::fetchOne('SELECT count(*) c FROM program_membership WHERE user_id = :u', ['u' => $userId])['c'] ?? 0);
        if ($remaining === 0) {
            Db::update('app_user', ['status' => 'removed'], ['id' => $userId]);
        }
        Audit::log('access.offboard', 'user#' . $userId . ($remaining === 0 ? ' (account removed)' : ''), $programId, $actorId);
    }
}
