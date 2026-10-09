<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Enterprise Access Matrix query engine (module 8 / section 8 of the build
 * directive). Every row joins system_account -> application, with person
 * (nullable — unmatched accounts are a first-class result) and the account's
 * entitlement assignments (nullable — an account with zero assignments still
 * appears once, so "an account exists with no access" is visible, not hidden).
 *
 * Authorization is enforced by the CALLER (controller) via Authorize before
 * query() runs — this class trusts $scope exactly as given. For the
 * supervisor view, the controller must pass scope.person_ids computed from
 * Authorize::reportsOf(); this class performs NO reporting-chain logic of its
 * own, so there is exactly one place authorization can go wrong, not two.
 *
 * Pagination is server-side (LIMIT/OFFSET) — the UI never loads the full
 * enterprise inventory into the browser (section 27 performance target).
 */
final class Matrix
{
    private const SORT_COLUMNS = [
        'person_name' => 'p.display_name',
        'application_name' => 'a.name',
        'entitlement_name' => 'e.name',
        'account_status' => 'sa.status',
        'risk_level' => 'e.risk_level',
        'granted_at' => 'ea.granted_at',
        'expires_at' => 'ea.expires_at',
    ];

    /**
     * @param array{
     *   view: string,
     *   person_id?: int, application_id?: int, person_ids?: int[],
     *   search?: string, department?: string, account_status?: string,
     *   assignment_type?: string, privileged_only?: bool,
     *   sort?: string, sort_dir?: string
     * } $scope
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public static function query(array $scope, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::buildWhere($scope);
        $sort = self::SORT_COLUMNS[$scope['sort'] ?? 'person_name'] ?? 'p.display_name';
        $dir = (($scope['sort_dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

        $total = (int) Db::fetchValue(self::baseFrom(true) . " WHERE $where", $params);

        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $rows = Db::fetchAll(
            self::baseFrom(false) . " WHERE $where ORDER BY $sort $dir NULLS LAST, sa.id LIMIT :limit OFFSET :offset",
            $params
        );

        if ($rows !== []) {
            $accountIds = array_unique(array_map(static fn ($r) => (int) $r['account_id'], $rows));
            $assignmentIds = array_unique(array_filter(array_map(
                static fn ($r) => $r['assignment_id'] !== null ? (int) $r['assignment_id'] : null,
                $rows
            )));
            $accountFields = DynamicFields::valuesFor('system_account', $accountIds);
            $assignmentFields = $assignmentIds !== [] ? DynamicFields::valuesFor('entitlement_assignment', $assignmentIds) : [];
            foreach ($rows as &$row) {
                $row['dynamic'] = array_merge(
                    $accountFields[(int) $row['account_id']] ?? [],
                    $row['assignment_id'] !== null ? ($assignmentFields[(int) $row['assignment_id']] ?? []) : []
                );
            }
            unset($row);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    private static function baseFrom(bool $countOnly): string
    {
        if ($countOnly) {
            return 'SELECT COUNT(*)
                FROM system_account sa
                JOIN application a ON a.id = sa.application_id
                LEFT JOIN person p ON p.id = sa.person_id
                LEFT JOIN entitlement_assignment ea ON ea.system_account_id = sa.id
                LEFT JOIN entitlement e ON e.id = ea.entitlement_id';
        }
        return 'SELECT
                sa.id AS account_id, sa.external_account_id, sa.username, sa.account_type,
                sa.status AS account_status, sa.last_login_at, sa.last_synced_at,
                p.id AS person_id, p.display_name AS person_name, p.department, p.employment_status,
                a.id AS application_id, a.name AS application_name,
                ea.id AS assignment_id, ea.assignment_type, ea.granted_at, ea.expires_at, ea.last_certified_at,
                e.id AS entitlement_id, e.name AS entitlement_name, e.entitlement_type,
                e.is_privileged, e.risk_level
            FROM system_account sa
            JOIN application a ON a.id = sa.application_id
            LEFT JOIN person p ON p.id = sa.person_id
            LEFT JOIN entitlement_assignment ea ON ea.system_account_id = sa.id
            LEFT JOIN entitlement e ON e.id = ea.entitlement_id';
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function buildWhere(array $scope): array
    {
        $clauses = ['1=1'];
        $params = [];
        $view = $scope['view'] ?? 'enterprise';

        switch ($view) {
            case 'person':
                $clauses[] = 'p.id = :person_id';
                $params['person_id'] = (int) ($scope['person_id'] ?? 0);
                break;
            case 'application':
                $clauses[] = 'a.id = :application_id';
                $params['application_id'] = (int) ($scope['application_id'] ?? 0);
                break;
            case 'supervisor':
                $ids = $scope['person_ids'] ?? [];
                if ($ids === []) {
                    $clauses[] = '1=0';
                } else {
                    $in = [];
                    foreach (array_values($ids) as $i => $pid) {
                        $k = 'sup' . $i;
                        $in[] = ':' . $k;
                        $params[$k] = (int) $pid;
                    }
                    $clauses[] = 'p.id IN (' . implode(',', $in) . ')';
                }
                break;
            case 'privileged':
                $clauses[] = '(e.is_privileged = TRUE OR sa.account_type = \'privileged\')';
                break;
            case 'exception':
                $clauses[] = '(sa.person_id IS NULL OR (sa.status = \'disabled\' AND ea.id IS NOT NULL) OR ea.expires_at < NOW())';
                break;
            case 'enterprise':
            default:
                break;
        }

        if (!empty($scope['search'])) {
            $clauses[] = '(p.display_name ILIKE :search OR sa.username ILIKE :search
                           OR e.name ILIKE :search OR a.name ILIKE :search OR sa.external_account_id ILIKE :search)';
            $params['search'] = '%' . $scope['search'] . '%';
        }
        if (!empty($scope['department'])) {
            $clauses[] = 'p.department = :department';
            $params['department'] = $scope['department'];
        }
        if (!empty($scope['account_status'])) {
            $clauses[] = 'sa.status = :account_status';
            $params['account_status'] = $scope['account_status'];
        }
        if (!empty($scope['assignment_type'])) {
            $clauses[] = 'ea.assignment_type = :assignment_type';
            $params['assignment_type'] = $scope['assignment_type'];
        }
        if (!empty($scope['privileged_only'])) {
            $clauses[] = '(e.is_privileged = TRUE OR sa.account_type = \'privileged\')';
        }

        return [implode(' AND ', $clauses), $params];
    }
}
