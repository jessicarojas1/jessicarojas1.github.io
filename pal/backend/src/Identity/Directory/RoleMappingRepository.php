<?php

declare(strict_types=1);

namespace App\Identity\Directory;

use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;

final class RoleMappingRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT m.id, m.role, m.created_at, g.idp_object_id, g.display_name, g.synced_at, g.sync_error,
                   (SELECT count(*) FROM group_membership gm WHERE gm.group_id = g.id AND gm.valid_to IS NULL) AS member_count
            FROM global_role_mapping m
            JOIN directory_group g ON g.id = m.group_id
            ORDER BY m.role, g.display_name
            SQL);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT m.id, m.role, g.id AS group_id, g.idp_object_id, g.display_name FROM global_role_mapping m JOIN directory_group g ON g.id = m.group_id WHERE m.id = ?',
            [$id],
        );

        return false === $row ? null : $row;
    }

    /**
     * @return string|null the new mapping ID, or null if it already existed
     */
    public function add(string $role, string $groupId, ?string $createdBy): ?string
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO global_role_mapping (role, group_id, created_by) VALUES (?, ?, ?) ON CONFLICT (role, group_id) DO NOTHING RETURNING id',
            [$role, $groupId, $createdBy],
        );

        return false === $id ? null : Row::scalarString($id);
    }

    public function remove(string $id): void
    {
        $this->connection->executeStatement('DELETE FROM global_role_mapping WHERE id = ?', [$id]);
    }
}
