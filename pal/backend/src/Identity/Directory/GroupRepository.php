<?php

declare(strict_types=1);

namespace App\Identity\Directory;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

final class GroupRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trackedGroups(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM directory_group WHERE tracked ORDER BY display_name');
    }

    /**
     * @return array<string, string> directory object ID => PAL group ID
     */
    public function trackedObjectIdMap(string $tenantId): array
    {
        $map = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, idp_object_id FROM directory_group WHERE tracked AND idp_tenant_id = ?', [$tenantId]) as $row) {
            $map[Row::string($row, 'idp_object_id')] = Row::string($row, 'id');
        }

        return $map;
    }

    /**
     * @return array{id: string, newly_tracked: bool}
     */
    public function track(string $tenantId, DirectoryGroupInfo $group): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            WITH previous AS (
                SELECT tracked FROM directory_group WHERE idp_tenant_id = :tid AND idp_object_id = :oid
            )
            INSERT INTO directory_group (idp_tenant_id, idp_object_id, display_name, tracked)
            VALUES (:tid, :oid, :name, true)
            ON CONFLICT (idp_tenant_id, idp_object_id) DO UPDATE SET
                display_name = EXCLUDED.display_name, tracked = true, updated_at = now()
            RETURNING id, COALESCE((SELECT tracked FROM previous), false) AS was_tracked
            SQL, ['tid' => $tenantId, 'oid' => $group->objectId, 'name' => $group->displayName]);
        if (false === $row) {
            throw new \LogicException('Upsert returned no row.');
        }

        return ['id' => Row::string($row, 'id'), 'newly_tracked' => !Row::bool($row, 'was_tracked')];
    }

    public function findIdByObjectId(string $tenantId, string $objectId): ?string
    {
        $id = $this->connection->fetchOne('SELECT id FROM directory_group WHERE idp_tenant_id = ? AND idp_object_id = ?', [$tenantId, $objectId]);

        return false === $id ? null : Row::scalarString($id);
    }

    public function markSynced(string $groupId, string $displayName, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE directory_group SET display_name = ?, synced_at = ?, sync_error = NULL, updated_at = now() WHERE id = ?',
            [$displayName, $now->format('Y-m-d H:i:s.uP'), $groupId],
        );
    }

    public function markSyncFailed(string $groupId, string $error): void
    {
        $this->connection->executeStatement(
            'UPDATE directory_group SET sync_error = ?, updated_at = now() WHERE id = ?',
            [mb_substr($error, 0, 500), $groupId],
        );
    }

    /**
     * @return list<string> user IDs
     */
    public function openMemberIds(string $groupId): array
    {
        return Row::strings($this->connection->fetchFirstColumn(
            'SELECT user_id FROM group_membership WHERE group_id = ? AND valid_to IS NULL',
            [$groupId],
        ));
    }

    /**
     * @return list<string> group IDs
     */
    public function openGroupIdsForUser(string $userId): array
    {
        return Row::strings($this->connection->fetchFirstColumn(
            'SELECT gm.group_id FROM group_membership gm JOIN directory_group g ON g.id = gm.group_id AND g.tracked WHERE gm.user_id = ? AND gm.valid_to IS NULL',
            [$userId],
        ));
    }

    public function openMembership(string $groupId, string $userId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'INSERT INTO group_membership (group_id, user_id, valid_from) VALUES (?, ?, ?) ON CONFLICT (group_id, user_id) WHERE valid_to IS NULL DO NOTHING',
            [$groupId, $userId, $now->format('Y-m-d H:i:s.uP')],
        );
    }

    public function closeMembership(string $groupId, string $userId, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE group_membership SET valid_to = GREATEST(?::timestamptz, valid_from + interval \'1 microsecond\') WHERE group_id = ? AND user_id = ? AND valid_to IS NULL',
            [$now->format('Y-m-d H:i:s.uP'), $groupId, $userId],
        );
    }
}
