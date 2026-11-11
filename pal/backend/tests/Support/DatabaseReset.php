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
        $connection->executeStatement('ALTER TABLE document_template_version DISABLE TRIGGER trg_template_version_no_truncate');
        try {
            $connection->executeStatement('ALTER TABLE revision DISABLE TRIGGER trg_revision_immutable');
            $connection->executeStatement('ALTER TABLE revision_file DISABLE TRIGGER trg_revision_file_draft_only');
            $connection->executeStatement('TRUNCATE audit_event, local_credential, user_role, document_template_version, document_template, revision_editing, publication, approval_decision, workflow_task, workflow_instance, revision_file, revision, document_grant, document, upload, stored_file, numbering_counter, org_unit, global_role_mapping, group_membership, directory_group, app_user, sessions, messenger_messages RESTART IDENTITY CASCADE');
            $connection->executeStatement("DELETE FROM org_setting WHERE key <> 'default_timezone'");
        } finally {
            $connection->executeStatement('ALTER TABLE audit_event ENABLE TRIGGER trg_audit_event_no_truncate');
            $connection->executeStatement('ALTER TABLE document_template_version ENABLE TRIGGER trg_template_version_no_truncate');
            $connection->executeStatement('ALTER TABLE revision ENABLE TRIGGER trg_revision_immutable');
            $connection->executeStatement('ALTER TABLE revision_file ENABLE TRIGGER trg_revision_file_draft_only');
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
