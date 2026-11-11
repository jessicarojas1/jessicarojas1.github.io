<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Soft checkout for the native editor (ADR-0009): who is editing a draft right now. One row
 * per revision, renewed by the editor's heartbeat; a row older than the presence window is
 * ignored, so a closed browser never leaves a lasting claim. It is advisory only: saves
 * stay protected by optimistic concurrency (row_version).
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261110000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Native editor: who is editing a draft (soft checkout presence)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS revision_editing (
                revision_id  uuid PRIMARY KEY REFERENCES revision (id),
                user_id      uuid NOT NULL REFERENCES app_user (id),
                started_at   timestamptz NOT NULL DEFAULT now(),
                heartbeat_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_revision_editing_times CHECK (heartbeat_at >= started_at)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS revision_editing');
    }
}
