<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two-person rule for the most powerful role changes (decision #31, amends #30): granting the
 * Owner role, removing it from someone else, and giving yourself System Administrator or a
 * role with document authority are held as a request until a different Owner or System
 * Administrator approves it.
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261127000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Role change requests (two-person rule for Owner and self grants)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS role_change_request (
                id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                target_user_id uuid NOT NULL REFERENCES app_user (id),
                requested_by   uuid NOT NULL REFERENCES app_user (id),
                roles_from     text[] NOT NULL,
                roles_to       text[] NOT NULL,
                reason         text NULL,
                status         text NOT NULL DEFAULT 'PENDING',
                decided_by     uuid NULL REFERENCES app_user (id),
                decided_at     timestamptz NULL,
                decision_note  text NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                expires_at     timestamptz NOT NULL DEFAULT now() + interval '7 days',
                CONSTRAINT ck_role_change_request_status CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED', 'CANCELLED')),
                CONSTRAINT ck_role_change_request_decided CHECK ((status = 'PENDING') = (decided_at IS NULL)),
                CONSTRAINT ck_role_change_request_other_approver CHECK (decided_by IS NULL OR status = 'CANCELLED' OR (decided_by <> requested_by AND decided_by <> target_user_id)),
                CONSTRAINT ck_role_change_request_reason CHECK (reason IS NULL OR char_length(reason) <= 500)
            )
            SQL);
        // One open request per person at a time.
        $this->addSql("CREATE UNIQUE INDEX IF NOT EXISTS uq_role_change_request_open ON role_change_request (target_user_id) WHERE status = 'PENDING'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS role_change_request');
    }
}
