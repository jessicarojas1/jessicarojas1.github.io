<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Identity Directory data access — the canonical person record and the
 * accounts/entitlements rolled up under it (section 7 of the build directive).
 */
final class People
{
    /**
     * @param array{search?:string, department?:string, employment_status?:string, identity_type?:string, person_ids?:int[]} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::buildWhere($filters);
        $sql = "SELECT p.*, m.display_name AS manager_name,
                       (SELECT COUNT(*) FROM system_account sa WHERE sa.person_id = p.id) AS account_count
                FROM person p
                LEFT JOIN person m ON m.id = p.manager_person_id
                WHERE $where
                ORDER BY p.last_name, p.first_name
                LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        return Db::fetchAll($sql, $params);
    }

    /** @param array<string,mixed> $filters */
    public static function count(array $filters = []): int
    {
        [$where, $params] = self::buildWhere($filters);
        return (int) Db::fetchValue("SELECT COUNT(*) FROM person p WHERE $where", $params);
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne(
            'SELECT p.*, m.display_name AS manager_name
             FROM person p LEFT JOIN person m ON m.id = p.manager_person_id
             WHERE p.id = :id',
            ['id' => $id]
        );
    }

    /** Every system_account (across all applications) linked to this person. */
    public static function accountsFor(int $personId): array
    {
        return Db::fetchAll(
            'SELECT sa.*, a.name AS application_name,
                    (SELECT COUNT(*) FROM entitlement_assignment ea WHERE ea.system_account_id = sa.id) AS entitlement_count
             FROM system_account sa
             JOIN application a ON a.id = sa.application_id
             WHERE sa.person_id = :pid
             ORDER BY a.name, sa.username',
            ['pid' => $personId]
        );
    }

    /** Direct reports only (one level), for the directory detail view. */
    public static function directReports(int $personId): array
    {
        return Db::fetchAll(
            'SELECT id, display_name, department, position_title, employment_status
             FROM person WHERE manager_person_id = :pid ORDER BY last_name, first_name',
            ['pid' => $personId]
        );
    }

    /** @return string[] distinct departments, for filter dropdowns. */
    public static function departments(): array
    {
        $rows = Db::fetchAll('SELECT DISTINCT department FROM person WHERE department IS NOT NULL ORDER BY department');
        return array_map(static fn ($r) => (string) $r['department'], $rows);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data, ?int $actorId): int
    {
        $id = Db::insert('person', self::sanitize($data));
        Audit::log('identity.create', 'person#' . $id, null, $data, null, $actorId);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?int $actorId): void
    {
        $before = self::get($id);
        Db::update('person', self::sanitize($data), ['id' => $id]);
        Audit::log('identity.update', 'person#' . $id, $before, $data, null, $actorId);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function sanitize(array $data): array
    {
        $allowed = [
            'employee_id', 'first_name', 'last_name', 'display_name', 'email',
            'department', 'business_unit', 'position_title', 'manager_person_id',
            'location', 'identity_type', 'employment_status', 'start_date',
            'termination_date', 'identity_authority',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private static function buildWhere(array $filters): array
    {
        $clauses = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $clauses[] = '(p.display_name ILIKE :search OR p.email ILIKE :search OR p.employee_id ILIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['department'])) {
            $clauses[] = 'p.department = :department';
            $params['department'] = $filters['department'];
        }
        if (!empty($filters['employment_status'])) {
            $clauses[] = 'p.employment_status = :employment_status';
            $params['employment_status'] = $filters['employment_status'];
        }
        if (!empty($filters['identity_type'])) {
            $clauses[] = 'p.identity_type = :identity_type';
            $params['identity_type'] = $filters['identity_type'];
        }
        if (isset($filters['person_ids']) && is_array($filters['person_ids'])) {
            if ($filters['person_ids'] === []) {
                $clauses[] = '1=0'; // explicit empty scope must return nothing, not everything
            } else {
                $in = [];
                foreach (array_values($filters['person_ids']) as $i => $pid) {
                    $key = 'pid' . $i;
                    $in[] = ':' . $key;
                    $params[$key] = (int) $pid;
                }
                $clauses[] = 'p.id IN (' . implode(',', $in) . ')';
            }
        }

        return [implode(' AND ', $clauses), $params];
    }
}
