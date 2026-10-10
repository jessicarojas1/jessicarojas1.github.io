<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Self-service access requests / approval workflow (Phase 6 — module
 * 11/section 11 of the build directive). A request always targets an
 * EXISTING system_account — this app provisions no new accounts anywhere
 * (see Connectors::defaultManifest(): no connector type claims
 * provision_access), so "request access" can only ever mean "add an
 * entitlement to an account already on record," never "create me an
 * account in this application."
 *
 * Unlike a campaign's "revoked" decision or a remediation task, APPROVING
 * a request genuinely does create the entitlement_assignment row —
 * source='manual', exactly the same meaning that value already carries
 * everywhere else in this schema ("this app's own record of who has
 * access," not "we pushed a provisioning call to the target system").
 * That is categorically different from auto-deleting an assignment on
 * revoke: a human approver deciding "yes, grant this" within Verity's own
 * inventory is Verity acting as the governance ledger it already is, not
 * Verity claiming an external system was changed.
 */
final class AccessRequests
{
    public static function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $clauses[] = 'ar.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['requested_by_user_id'])) {
            $clauses[] = 'ar.requested_by_user_id = :ruid';
            $params['ruid'] = (int) $filters['requested_by_user_id'];
        }
        // "awaiting_approval_by" — requests this specific person can act on
        // because they own the target application. Broad accessrequest.view/
        // manage holders don't need this filter; it exists for a system
        // owner who only has the scoped accessrequest.approve.owned grant.
        if (!empty($filters['approvable_by_person_id'])) {
            $clauses[] = 'a.system_owner_person_id = :apid';
            $params['apid'] = (int) $filters['approvable_by_person_id'];
        }
        $where = implode(' AND ', $clauses);
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return Db::fetchAll(
            "SELECT ar.*, sa.external_account_id, sa.username, sa.application_id,
                    a.name AS application_name, a.system_owner_person_id,
                    e.name AS entitlement_name, e.is_privileged, e.risk_level,
                    p.display_name AS person_name,
                    rb.display_name AS requested_by_name, ap.display_name AS approver_name,
                    db.display_name AS decided_by_name
             FROM access_request ar
             JOIN system_account sa ON sa.id = ar.system_account_id
             JOIN application a ON a.id = sa.application_id
             JOIN entitlement e ON e.id = ar.entitlement_id
             LEFT JOIN person p ON p.id = sa.person_id
             LEFT JOIN app_user rb ON rb.id = ar.requested_by_user_id
             LEFT JOIN person ap ON ap.id = ar.approver_person_id
             LEFT JOIN app_user db ON db.id = ar.decided_by_user_id
             WHERE $where
             ORDER BY ar.status = 'pending' DESC, ar.created_at DESC
             LIMIT :limit OFFSET :offset",
            $params
        );
    }

    public static function countAll(array $filters = []): int
    {
        $clauses = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $clauses[] = 'ar.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['requested_by_user_id'])) {
            $clauses[] = 'ar.requested_by_user_id = :ruid';
            $params['ruid'] = (int) $filters['requested_by_user_id'];
        }
        if (!empty($filters['approvable_by_person_id'])) {
            $clauses[] = 'a.system_owner_person_id = :apid';
            $params['apid'] = (int) $filters['approvable_by_person_id'];
        }
        $where = implode(' AND ', $clauses);
        return (int) Db::fetchValue(
            'SELECT COUNT(*) FROM access_request ar
             JOIN system_account sa ON sa.id = ar.system_account_id
             JOIN application a ON a.id = sa.application_id
             WHERE ' . $where,
            $params
        );
    }

    /** Accounts within one application, for the "New Request" form's second step. */
    public static function accountsForApplication(int $applicationId): array
    {
        return Db::fetchAll(
            'SELECT sa.id, sa.external_account_id, sa.username, p.display_name AS person_name
             FROM system_account sa LEFT JOIN person p ON p.id = sa.person_id
             WHERE sa.application_id = :aid ORDER BY p.display_name NULLS LAST, sa.external_account_id',
            ['aid' => $applicationId]
        );
    }

    /** Entitlements in one application that a given account does NOT already hold directly. */
    public static function requestableEntitlements(int $applicationId, int $systemAccountId): array
    {
        return Db::fetchAll(
            "SELECT e.id, e.name, e.is_privileged, e.risk_level
             FROM entitlement e
             WHERE e.application_id = :aid
               AND NOT EXISTS (
                 SELECT 1 FROM entitlement_assignment ea
                 WHERE ea.entitlement_id = e.id AND ea.system_account_id = :said AND ea.assignment_type = 'direct'
               )
             ORDER BY e.name",
            ['aid' => $applicationId, 'said' => $systemAccountId]
        );
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne(
            'SELECT ar.*, sa.application_id, sa.person_id AS account_person_id
             FROM access_request ar JOIN system_account sa ON sa.id = ar.system_account_id
             WHERE ar.id = :id',
            ['id' => $id]
        );
    }

    public static function create(array $data, int $requestedByUserId): int
    {
        $systemAccountId = (int) ($data['system_account_id'] ?? 0);
        $entitlementId = (int) ($data['entitlement_id'] ?? 0);
        $justification = trim((string) ($data['justification'] ?? ''));
        if ($systemAccountId <= 0 || $entitlementId <= 0) {
            throw new \InvalidArgumentException('An account and an entitlement are required.');
        }
        if ($justification === '') {
            throw new \InvalidArgumentException('A justification is required.');
        }

        $account = Db::fetchOne('SELECT application_id FROM system_account WHERE id = :id', ['id' => $systemAccountId]);
        $entitlement = Db::fetchOne('SELECT application_id FROM entitlement WHERE id = :id', ['id' => $entitlementId]);
        if ($account === null || $entitlement === null) {
            throw new \InvalidArgumentException('Account or entitlement not found.');
        }
        if ((int) $account['application_id'] !== (int) $entitlement['application_id']) {
            throw new \InvalidArgumentException('The entitlement must belong to the same application as the account.');
        }

        $existing = Db::fetchOne(
            "SELECT id FROM entitlement_assignment WHERE system_account_id = :sa AND entitlement_id = :e AND assignment_type = 'direct'",
            ['sa' => $systemAccountId, 'e' => $entitlementId]
        );
        if ($existing !== null) {
            throw new \InvalidArgumentException('This account already has this entitlement — nothing to request.');
        }

        $owner = Db::fetchOne('SELECT system_owner_person_id FROM application WHERE id = :id', ['id' => (int) $account['application_id']]);
        $id = Db::insert('access_request', [
            'requested_by_user_id' => $requestedByUserId,
            'system_account_id' => $systemAccountId,
            'entitlement_id' => $entitlementId,
            'justification' => $justification,
            'approver_person_id' => $owner['system_owner_person_id'] ?? null,
        ]);
        Audit::log('accessrequest.create', 'access_request#' . $id, null, $data, $justification, $requestedByUserId);
        return $id;
    }

    /**
     * $actingUserId is who clicked Approve — authorization (do they
     * actually own the application, or hold the broad manage permission)
     * is the CALLER's responsibility via Authorize::requirePermission()
     * before this runs; this method trusts that check already happened,
     * exactly like every other Support-layer write in this app.
     */
    public static function approve(int $id, ?string $note, int $actingUserId): void
    {
        $req = self::get($id);
        if ($req === null) {
            throw new \RuntimeException('Access request not found.');
        }
        if ($req['status'] !== 'pending') {
            throw new \RuntimeException('This request has already been ' . $req['status'] . '.');
        }

        $assignmentRow = Db::fetchOne(
            "INSERT INTO entitlement_assignment (system_account_id, entitlement_id, assignment_type, source)
             VALUES (:sa, :e, 'direct', 'manual')
             ON CONFLICT (system_account_id, entitlement_id, assignment_type) DO UPDATE SET updated_at = NOW()
             RETURNING id",
            ['sa' => (int) $req['system_account_id'], 'e' => (int) $req['entitlement_id']]
        );

        Db::query(
            'UPDATE access_request SET status = :status, decision_note = :note,
                decided_by_user_id = :by, decided_at = NOW(), resulting_assignment_id = :aid
             WHERE id = :id',
            ['id' => $id, 'status' => 'approved', 'note' => $note, 'by' => $actingUserId, 'aid' => (int) $assignmentRow['id']]
        );
        Audit::log('accessrequest.approve', 'access_request#' . $id, ['status' => 'pending'], ['status' => 'approved', 'assignment_id' => $assignmentRow['id']], $note, $actingUserId);
    }

    public static function deny(int $id, ?string $note, int $actingUserId): void
    {
        $req = self::get($id);
        if ($req === null) {
            throw new \RuntimeException('Access request not found.');
        }
        if ($req['status'] !== 'pending') {
            throw new \RuntimeException('This request has already been ' . $req['status'] . '.');
        }
        Db::query(
            'UPDATE access_request SET status = :status, decision_note = :note, decided_by_user_id = :by, decided_at = NOW() WHERE id = :id',
            ['id' => $id, 'status' => 'denied', 'note' => $note, 'by' => $actingUserId]
        );
        Audit::log('accessrequest.deny', 'access_request#' . $id, ['status' => 'pending'], ['status' => 'denied'], $note, $actingUserId);
    }

    /** A requester withdrawing their own still-pending request. Ownership is re-checked here, not just by the caller. */
    public static function cancel(int $id, int $requestedByUserId): void
    {
        $req = Db::fetchOne('SELECT * FROM access_request WHERE id = :id AND requested_by_user_id = :uid', ['id' => $id, 'uid' => $requestedByUserId]);
        if ($req === null) {
            throw new \RuntimeException('This request was not found, or is not yours to cancel.');
        }
        if ($req['status'] !== 'pending') {
            throw new \RuntimeException('This request has already been ' . $req['status'] . '.');
        }
        Db::query("UPDATE access_request SET status = 'cancelled', decided_at = NOW() WHERE id = :id", ['id' => $id]);
        Audit::log('accessrequest.cancel', 'access_request#' . $id, ['status' => 'pending'], ['status' => 'cancelled'], null, $requestedByUserId);
    }
}
