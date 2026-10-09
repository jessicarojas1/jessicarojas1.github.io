<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Application Catalog (module 4). Connector execution (discovery,
 * synchronization, provisioning) is NOT implemented in this pass — this is
 * catalog/configuration metadata only. See docs/GCC_HIGH_INTEGRATION.md and
 * OPEN_ITEMS.md.
 */
final class Applications
{
    public static function list(array $filters = []): array
    {
        $clauses = ['1=1'];
        $params = [];
        if (!empty($filters['search'])) {
            $clauses[] = 'a.name ILIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['status'])) {
            $clauses[] = 'a.status = :status';
            $params['status'] = $filters['status'];
        }
        $where = implode(' AND ', $clauses);
        return Db::fetchAll(
            "SELECT a.*, so.display_name AS system_owner_name,
                    (SELECT COUNT(*) FROM system_account sa WHERE sa.application_id = a.id) AS account_count,
                    (SELECT COUNT(*) FROM entitlement e WHERE e.application_id = a.id) AS entitlement_count,
                    (SELECT COUNT(*) FROM connector c WHERE c.application_id = a.id) AS connector_count
             FROM application a
             LEFT JOIN person so ON so.id = a.system_owner_person_id
             WHERE $where
             ORDER BY a.name",
            $params
        );
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne(
            'SELECT a.*, so.display_name AS system_owner_name,
                    tech.display_name AS technical_owner_name,
                    biz.display_name AS business_owner_name
             FROM application a
             LEFT JOIN person so ON so.id = a.system_owner_person_id
             LEFT JOIN person tech ON tech.id = a.technical_owner_person_id
             LEFT JOIN person biz ON biz.id = a.business_owner_person_id
             WHERE a.id = :id',
            ['id' => $id]
        );
    }

    public static function create(array $data, ?int $actorId): int
    {
        $id = Db::insert('application', self::sanitize($data));
        Audit::log('application.create', 'application#' . $id, null, $data, null, $actorId);
        return $id;
    }

    public static function update(int $id, array $data, ?int $actorId): void
    {
        $before = self::get($id);
        Db::update('application', self::sanitize($data), ['id' => $id]);
        Audit::log('application.update', 'application#' . $id, $before, $data, null, $actorId);
    }

    public static function connectorsFor(int $applicationId): array
    {
        return Db::fetchAll(
            'SELECT c.*, p.display_name AS default_reviewer_name,
                    (SELECT MAX(started_at) FROM connector_sync_job j WHERE j.connector_id = c.id) AS last_sync_started_at,
                    (SELECT status FROM connector_sync_job j WHERE j.connector_id = c.id ORDER BY started_at DESC LIMIT 1) AS last_sync_status
             FROM connector c
             LEFT JOIN person p ON p.id = c.default_reviewer_person_id
             WHERE c.application_id = :aid
             ORDER BY c.id',
            ['aid' => $applicationId]
        );
    }

    public static function syncHistoryFor(int $connectorId, int $limit = 20): array
    {
        return Db::fetchAll(
            'SELECT * FROM connector_sync_job WHERE connector_id = :cid ORDER BY started_at DESC LIMIT :limit',
            ['cid' => $connectorId, 'limit' => $limit]
        );
    }

    private static function sanitize(array $data): array
    {
        $allowed = [
            'name', 'description', 'classification', 'system_owner_person_id',
            'technical_owner_person_id', 'business_owner_person_id', 'status',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
