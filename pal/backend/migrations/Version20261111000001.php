<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Template version history (owner decision #27): every change to a template's name,
 * description or content is kept as an immutable, numbered version, and each revision
 * records the template version it was started from, so an auditor can see the exact
 * wording a document began with. Existing templates become version 1.
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261111000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Template version history and the template version each revision started from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_template ADD COLUMN IF NOT EXISTS version integer NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE document_template ADD CONSTRAINT ck_document_template_version CHECK (version >= 1)');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_template_version (
                template_id uuid NOT NULL REFERENCES document_template (id),
                version     integer NOT NULL,
                name        text NOT NULL,
                description text NULL,
                body_json   text NOT NULL,
                created_by  uuid NOT NULL REFERENCES app_user (id),
                created_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (template_id, version),
                CONSTRAINT ck_document_template_version_number CHECK (version >= 1),
                CONSTRAINT ck_document_template_version_body CHECK (octet_length(body_json) BETWEEN 1 AND 1048576)
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO document_template_version (template_id, version, name, description, body_json, created_by, created_at)
            SELECT id, version, name, description, body_json, updated_by, updated_at FROM document_template
            ON CONFLICT DO NOTHING
            SQL);
        // History is evidence: versions are written once and never changed or removed.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION pal_template_version_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'template versions are immutable' USING ERRCODE = 'insufficient_privilege';
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER trg_template_version_immutable BEFORE UPDATE OR DELETE ON document_template_version FOR EACH ROW EXECUTE FUNCTION pal_template_version_immutable()');

        $this->addSql('ALTER TABLE revision ADD COLUMN IF NOT EXISTS template_id uuid NULL');
        $this->addSql('ALTER TABLE revision ADD COLUMN IF NOT EXISTS template_version integer NULL');
        $this->addSql('ALTER TABLE revision ADD CONSTRAINT fk_revision_template_version FOREIGN KEY (template_id, template_version) REFERENCES document_template_version (template_id, version)');
        $this->addSql('ALTER TABLE revision ADD CONSTRAINT ck_revision_template_pair CHECK ((template_id IS NULL) = (template_version IS NULL))');
    }

    public function down(Schema $schema): void
    {
        // Development only: never run down-migrations against real data.
        $this->addSql('ALTER TABLE revision DROP CONSTRAINT IF EXISTS ck_revision_template_pair');
        $this->addSql('ALTER TABLE revision DROP CONSTRAINT IF EXISTS fk_revision_template_version');
        $this->addSql('ALTER TABLE revision DROP COLUMN IF EXISTS template_version');
        $this->addSql('ALTER TABLE revision DROP COLUMN IF EXISTS template_id');
        $this->addSql('DROP TRIGGER IF EXISTS trg_template_version_immutable ON document_template_version');
        $this->addSql('DROP TABLE IF EXISTS document_template_version');
        $this->addSql('DROP FUNCTION IF EXISTS pal_template_version_immutable()');
        $this->addSql('ALTER TABLE document_template DROP CONSTRAINT IF EXISTS ck_document_template_version');
        $this->addSql('ALTER TABLE document_template DROP COLUMN IF EXISTS version');
    }
}
