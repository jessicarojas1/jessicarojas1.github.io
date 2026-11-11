<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Native editor v1 (ADR-0009): a revision can carry content written in PAL's editor, and
 * PAL administrators maintain per-document-type templates that seed new documents.
 *
 * - revision.body_json holds canonical, schema-validated ProseMirror JSON (never HTML);
 *   body_text is its plain text for search. Both freeze with the rest of the content.
 * - The approval manifest hash covers SHA-256(body_json) when a body exists.
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261110000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Native editor v1: revision body (canonical JSON + search text) and document templates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE revision ADD COLUMN IF NOT EXISTS body_json text NULL');
        $this->addSql('ALTER TABLE revision ADD COLUMN IF NOT EXISTS body_text text NULL');
        $this->addSql('ALTER TABLE revision ADD CONSTRAINT ck_revision_body_size CHECK (body_json IS NULL OR octet_length(body_json) <= 1048576)');
        $this->addSql('ALTER TABLE revision ADD CONSTRAINT ck_revision_body_pair CHECK ((body_json IS NULL) = (body_text IS NULL))');

        // Body text joins the search index at the lowest weight; capped so a very long body
        // can never exceed PostgreSQL's tsvector size limit.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION pal_revision_search_vector() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('simple', coalesce((SELECT document_number FROM document WHERE id = NEW.document_id), '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(NEW.title, '')), 'A') ||
                    setweight(to_tsvector('english', array_to_string(NEW.keywords, ' ')), 'B') ||
                    setweight(to_tsvector('english', coalesce(NEW.description, '')), 'C') ||
                    setweight(to_tsvector('english', left(coalesce(NEW.body_text, ''), 200000)), 'D');
                RETURN NEW;
            END
            $$
            SQL);
        $this->addSql('DROP TRIGGER IF EXISTS trg_revision_search_vector ON revision');
        $this->addSql('CREATE TRIGGER trg_revision_search_vector BEFORE INSERT OR UPDATE OF title, description, keywords, body_text ON revision FOR EACH ROW EXECUTE FUNCTION pal_revision_search_vector()');

        // The body is content: same immutability rules as title/description/keywords.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION pal_revision_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.state = 'APPROVED' AND (NEW.state <> OLD.state
                    OR NEW.title IS DISTINCT FROM OLD.title OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.keywords IS DISTINCT FROM OLD.keywords OR NEW.change_summary IS DISTINCT FROM OLD.change_summary
                    OR NEW.body_json IS DISTINCT FROM OLD.body_json OR NEW.body_text IS DISTINCT FROM OLD.body_text
                    OR NEW.content_manifest_hash IS DISTINCT FROM OLD.content_manifest_hash
                    OR NEW.major <> OLD.major OR NEW.minor <> OLD.minor) THEN
                    RAISE EXCEPTION 'approved revision % is immutable', OLD.id USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF OLD.state <> 'DRAFT' AND NOT (NEW.state = 'DRAFT' AND OLD.state IN ('CHANGES_REQUESTED', 'REJECTED'))
                    AND (NEW.title IS DISTINCT FROM OLD.title OR NEW.description IS DISTINCT FROM OLD.description
                         OR NEW.keywords IS DISTINCT FROM OLD.keywords OR NEW.change_summary IS DISTINCT FROM OLD.change_summary
                         OR NEW.body_json IS DISTINCT FROM OLD.body_json OR NEW.body_text IS DISTINCT FROM OLD.body_text) THEN
                    RAISE EXCEPTION 'revision % content can only change while DRAFT', OLD.id USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END
            $$
            SQL);

        // Controlled templates: maintained by PAL administrators, one or more per document
        // type, never deleted (deactivated instead) so the audit trail always resolves.
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_template (
                id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                document_type_id uuid NOT NULL REFERENCES document_type (id),
                name             text NOT NULL,
                description      text NULL,
                body_json        text NOT NULL,
                is_active        boolean NOT NULL DEFAULT true,
                created_by       uuid NOT NULL REFERENCES app_user (id),
                updated_by       uuid NOT NULL REFERENCES app_user (id),
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                row_version      integer NOT NULL DEFAULT 1,
                CONSTRAINT uq_document_template_name UNIQUE (document_type_id, name),
                CONSTRAINT ck_document_template_name CHECK (char_length(name) BETWEEN 1 AND 120),
                CONSTRAINT ck_document_template_description CHECK (description IS NULL OR char_length(description) <= 1000),
                CONSTRAINT ck_document_template_body CHECK (octet_length(body_json) BETWEEN 1 AND 1048576)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS ix_document_template_type ON document_template (document_type_id) WHERE is_active');
    }

    public function down(Schema $schema): void
    {
        // Development only: never run down-migrations against real data.
        $this->addSql('DROP TABLE IF EXISTS document_template');
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION pal_revision_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.state = 'APPROVED' AND (NEW.state <> OLD.state
                    OR NEW.title IS DISTINCT FROM OLD.title OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.keywords IS DISTINCT FROM OLD.keywords OR NEW.change_summary IS DISTINCT FROM OLD.change_summary
                    OR NEW.content_manifest_hash IS DISTINCT FROM OLD.content_manifest_hash
                    OR NEW.major <> OLD.major OR NEW.minor <> OLD.minor) THEN
                    RAISE EXCEPTION 'approved revision % is immutable', OLD.id USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF OLD.state <> 'DRAFT' AND NOT (NEW.state = 'DRAFT' AND OLD.state IN ('CHANGES_REQUESTED', 'REJECTED'))
                    AND (NEW.title IS DISTINCT FROM OLD.title OR NEW.description IS DISTINCT FROM OLD.description
                         OR NEW.keywords IS DISTINCT FROM OLD.keywords OR NEW.change_summary IS DISTINCT FROM OLD.change_summary) THEN
                    RAISE EXCEPTION 'revision % content can only change while DRAFT', OLD.id USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        $this->addSql('DROP TRIGGER IF EXISTS trg_revision_search_vector ON revision');
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION pal_revision_search_vector() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('simple', coalesce((SELECT document_number FROM document WHERE id = NEW.document_id), '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(NEW.title, '')), 'A') ||
                    setweight(to_tsvector('english', array_to_string(NEW.keywords, ' ')), 'B') ||
                    setweight(to_tsvector('english', coalesce(NEW.description, '')), 'C');
                RETURN NEW;
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER trg_revision_search_vector BEFORE INSERT OR UPDATE OF title, description, keywords ON revision FOR EACH ROW EXECUTE FUNCTION pal_revision_search_vector()');
        $this->addSql('ALTER TABLE revision DROP CONSTRAINT IF EXISTS ck_revision_body_pair');
        $this->addSql('ALTER TABLE revision DROP CONSTRAINT IF EXISTS ck_revision_body_size');
        $this->addSql('ALTER TABLE revision DROP COLUMN IF EXISTS body_text');
        $this->addSql('ALTER TABLE revision DROP COLUMN IF EXISTS body_json');
    }
}
