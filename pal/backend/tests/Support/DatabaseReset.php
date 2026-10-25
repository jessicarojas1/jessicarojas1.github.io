<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Empties all application tables between tests. The audit table's TRUNCATE guard is
 * lifted only for the duration of the reset (the test role owns the tables).
 */
trait DatabaseReset
{
    protected static function resetTables(Connection $connection): void
    {
        $connection->executeStatement('ALTER TABLE audit_event DISABLE TRIGGER trg_audit_event_no_truncate');
        try {
            $connection->executeStatement('TRUNCATE audit_event, global_role_mapping, group_membership, directory_group, app_user, sessions, messenger_messages RESTART IDENTITY CASCADE');
            $connection->executeStatement("DELETE FROM org_setting WHERE key <> 'default_timezone'");
        } finally {
            $connection->executeStatement('ALTER TABLE audit_event ENABLE TRIGGER trg_audit_event_no_truncate');
        }
    }

    /**
     * @return string the new user ID
     */
    protected static function createUser(Connection $connection, string $objectId, string $name = 'Test User', bool $active = true): string
    {
        return (string) $connection->fetchOne(
            "INSERT INTO app_user (idp_tenant_id, idp_object_id, display_name, is_active, source) VALUES (?, ?, ?, ?, 'LOGIN') RETURNING id",
            [FakeEntraId::TENANT, $objectId, $name, $active ? 'true' : 'false'],
        );
    }

    protected static function grantRole(Connection $connection, string $userId, string $role): void
    {
        $groupId = (string) $connection->fetchOne(
            "INSERT INTO directory_group (idp_tenant_id, idp_object_id, display_name) VALUES (?, gen_random_uuid(), ?) RETURNING id",
            [FakeEntraId::TENANT, 'Group for '.$role],
        );
        $connection->executeStatement('INSERT INTO group_membership (group_id, user_id) VALUES (?, ?)', [$groupId, $userId]);
        $connection->executeStatement('INSERT INTO global_role_mapping (role, group_id) VALUES (?, ?)', [$role, $groupId]);
    }
}
