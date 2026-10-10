<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * System account access + identity correlation (module 7). suggestMatch()
 * below implements deterministic matching heuristics, but it only ever
 * SUGGESTS — every link is still created through the exact same link()
 * method, with the exact same audit trail, and still requires a human to
 * submit the form. There is no code path anywhere that calls link()
 * without a request going through AccountsController — see OPEN_ITEMS.md's
 * "reviewed before auto-applying at scale" note, which is why this stays a
 * suggestion engine, not an auto-merge engine.
 */
final class Accounts
{
    /**
     * A deterministic match suggestion for one unmatched account, or null
     * when no confident candidate exists. Tries the strongest signal first
     * and stops at the first rule that yields EXACTLY one candidate — a
     * rule that matches zero or more than one person is treated as "no
     * confident match," never guessed at. This method only ever reads; it
     * never links anything itself. AccountsController::link() independently
     * re-runs this same check server-side before ever recording
     * link_method='deterministic', so the audit trail's method label is
     * always server-verified truth, never a client-supplied claim.
     * @return array{person_id:int,person_name:string,reason:string,confidence:string}|null
     */
    public static function suggestMatch(int $accountId): ?array
    {
        $account = self::get($accountId);
        if ($account === null || $account['person_id'] !== null) {
            return null;
        }
        $externalId = trim((string) $account['external_account_id']);
        $username = trim((string) ($account['username'] ?? ''));

        if ($externalId !== '') {
            $match = self::oneCandidateOrNull(
                'SELECT id, display_name FROM person WHERE employee_id IS NOT NULL AND lower(employee_id) = lower(:v)',
                ['v' => $externalId]
            );
            if ($match !== null) {
                return self::suggestion($match, "Account's external ID exactly matches this person's employee ID", 'high');
            }
        }

        if ($username !== '') {
            $match = self::oneCandidateOrNull(
                'SELECT id, display_name FROM person WHERE email IS NOT NULL AND lower(email) = lower(:v)',
                ['v' => $username]
            );
            if ($match !== null) {
                return self::suggestion($match, "Account username exactly matches this person's email address", 'high');
            }

            $localPart = strtolower(explode('@', $username, 2)[0]);
            $match = self::oneCandidateOrNull(
                "SELECT id, display_name FROM person WHERE email IS NOT NULL AND lower(split_part(email, '@', 1)) = :v",
                ['v' => $localPart]
            );
            if ($match !== null) {
                return self::suggestion($match, "Account username matches the part of this person's email before the @", 'medium');
            }
        }

        return null;
    }

    private static function oneCandidateOrNull(string $sql, array $params): ?array
    {
        $rows = Db::fetchAll($sql, $params);
        return count($rows) === 1 ? $rows[0] : null;
    }

    private static function suggestion(array $person, string $reason, string $confidence): array
    {
        return [
            'person_id' => (int) $person['id'],
            'person_name' => (string) $person['display_name'],
            'reason' => $reason,
            'confidence' => $confidence,
        ];
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne(
            'SELECT sa.*, a.name AS application_name, p.display_name AS person_name
             FROM system_account sa
             JOIN application a ON a.id = sa.application_id
             LEFT JOIN person p ON p.id = sa.person_id
             WHERE sa.id = :id',
            ['id' => $id]
        );
    }

    /** Accounts with no linked person — the Unmatched Accounts workspace. */
    public static function unmatched(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = ['sa.person_id IS NULL'];
        $params = [];
        if (!empty($filters['application_id'])) {
            $clauses[] = 'sa.application_id = :aid';
            $params['aid'] = (int) $filters['application_id'];
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(sa.username ILIKE :search OR sa.external_account_id ILIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $where = implode(' AND ', $clauses);
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return Db::fetchAll(
            "SELECT sa.*, a.name AS application_name
             FROM system_account sa JOIN application a ON a.id = sa.application_id
             WHERE $where ORDER BY sa.created_at DESC LIMIT :limit OFFSET :offset",
            $params
        );
    }

    public static function countUnmatched(array $filters = []): int
    {
        $clauses = ['sa.person_id IS NULL'];
        $params = [];
        if (!empty($filters['application_id'])) {
            $clauses[] = 'sa.application_id = :aid';
            $params['aid'] = (int) $filters['application_id'];
        }
        $where = implode(' AND ', $clauses);
        return (int) Db::fetchValue("SELECT COUNT(*) FROM system_account sa WHERE $where", $params);
    }

    /** Link an account to a person; records history in identity_account_link. */
    public static function link(int $accountId, int $personId, string $method, ?int $actorId, ?string $note = null): void
    {
        $account = self::get($accountId);
        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }
        Db::update('system_account', ['person_id' => $personId], ['id' => $accountId]);
        Db::insert('identity_account_link', [
            'system_account_id' => $accountId,
            'person_id' => $personId,
            'link_method' => $method,
            'linked_by_user_id' => $actorId,
            'note' => $note,
        ]);
        Audit::log('account.link', 'system_account#' . $accountId, ['person_id' => $account['person_id'] ?? null], ['person_id' => $personId], $note, $actorId);
    }

    public static function unlink(int $accountId, ?int $actorId, ?string $note = null): void
    {
        $account = self::get($accountId);
        if ($account === null) {
            throw new \RuntimeException('Account not found.');
        }
        Db::update('system_account', ['person_id' => null], ['id' => $accountId]);
        Db::query(
            'UPDATE identity_account_link SET unlinked_at = NOW()
             WHERE system_account_id = :aid AND unlinked_at IS NULL',
            ['aid' => $accountId]
        );
        Audit::log('account.unlink', 'system_account#' . $accountId, ['person_id' => $account['person_id'] ?? null], ['person_id' => null], $note, $actorId);
    }

    public static function linkHistory(int $accountId): array
    {
        return Db::fetchAll(
            'SELECT l.*, p.display_name AS person_name, u.display_name AS linked_by_name
             FROM identity_account_link l
             LEFT JOIN person p ON p.id = l.person_id
             LEFT JOIN app_user u ON u.id = l.linked_by_user_id
             WHERE l.system_account_id = :aid ORDER BY l.linked_at DESC',
            ['aid' => $accountId]
        );
    }

    /** Candidate people for manual linking — simple name/email contains search, never auto-merge. */
    public static function linkCandidates(string $search, int $limit = 15): array
    {
        if (trim($search) === '') {
            return [];
        }
        return Db::fetchAll(
            'SELECT id, display_name, email, department FROM person
             WHERE display_name ILIKE :s OR email ILIKE :s OR employee_id ILIKE :s
             ORDER BY display_name LIMIT :limit',
            ['s' => '%' . $search . '%', 'limit' => $limit]
        );
    }
}
