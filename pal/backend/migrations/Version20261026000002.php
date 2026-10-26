<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2: document repository and walking skeleton: reference data, numbering,
 * uploads and immutable stored files, documents with versioned revisions, document
 * grants, single-approver workflow, and publications with effectivity constraints.
 *
 * Keep pal/database/schema.sql in sync with every migration.
 */
final class Version20261026000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: documents, revisions, files, grants, approval skeleton, publications';
    }

    public function up(Schema $schema): void
    {
        // Trusted extensions: the database owner can create them (no superuser needed).
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $this->addSql(<<<'SQL'
            CREATE TABLE org_unit (
                id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code       text NOT NULL,
                name       text NOT NULL,
                is_active  boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_org_unit_code UNIQUE (code),
                CONSTRAINT ck_org_unit_code CHECK (code ~ '^[A-Z0-9]{2,10}$'),
                CONSTRAINT ck_org_unit_name CHECK (char_length(name) BETWEEN 1 AND 120)
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE document_type (
                id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                code                   text NOT NULL,
                name                   text NOT NULL,
                description            text NULL,
                review_interval_months integer NULL,
                is_active              boolean NOT NULL DEFAULT true,
                created_at             timestamptz NOT NULL DEFAULT now(),
                updated_at             timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_document_type_code UNIQUE (code),
                CONSTRAINT ck_document_type_code CHECK (code ~ '^[A-Z]{2,6}$'),
                CONSTRAINT ck_document_type_name CHECK (char_length(name) BETWEEN 1 AND 120),
                CONSTRAINT ck_document_type_review CHECK (review_interval_months IS NULL OR review_interval_months BETWEEN 1 AND 120)
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO document_type (code, name, review_interval_months) VALUES
                ('POL', 'Policy', 12), ('PRC', 'Process', 24), ('SOP', 'Procedure', 24),
                ('STD', 'Standard', 24), ('WI', 'Work Instruction', 24), ('TPL', 'Template', NULL),
                ('FRM', 'Form', NULL), ('GDL', 'Guideline', 24), ('REF', 'Reference Document', NULL)
            ON CONFLICT (code) DO NOTHING
            SQL);

        // Allocated with SELECT ... FOR UPDATE in the creating transaction: no duplicates;
        // gaps from rolled-back creations are acceptable.
        $this->addSql(<<<'SQL'
            CREATE TABLE numbering_counter (
                document_type_id uuid NOT NULL REFERENCES document_type (id),
                org_unit_id      uuid NOT NULL REFERENCES org_unit (id),
                next_value       integer NOT NULL DEFAULT 1,
                PRIMARY KEY (document_type_id, org_unit_id),
                CONSTRAINT ck_numbering_counter_value CHECK (next_value >= 1)
            )
            SQL);

        // One row per stored byte sequence (content-addressed). Only scanned-clean files exist here.
        $this->addSql(<<<'SQL'
            CREATE TABLE stored_file (
                sha256          char(64) PRIMARY KEY,
                size_bytes      bigint NOT NULL,
                detected_mime   text NOT NULL,
                scan_engine     text NOT NULL,
                scanned_at      timestamptz NOT NULL,
                storage_backend text NOT NULL,
                created_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_stored_file_sha CHECK (sha256 ~ '^[0-9a-f]{64}$'),
                CONSTRAINT ck_stored_file_size CHECK (size_bytes > 0)
            )
            SQL);

        // Every upload attempt, including rejected and infected ones (evidence).
        $this->addSql(<<<'SQL'
            CREATE TABLE upload (
                id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                uploaded_by       uuid NOT NULL REFERENCES app_user (id),
                original_filename text NOT NULL,
                size_bytes        bigint NOT NULL,
                sha256            char(64) NULL,
                detected_mime     text NULL,
                status            text NOT NULL,
                reason            text NULL,
                stored_sha256     char(64) NULL REFERENCES stored_file (sha256),
                created_at        timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_upload_status CHECK (status IN ('CLEAN', 'INFECTED', 'REJECTED', 'ERROR')),
                CONSTRAINT ck_upload_clean_stored CHECK ((status = 'CLEAN') = (stored_sha256 IS NOT NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX ix_upload_user ON upload (uploaded_by, created_at)');

        // Stable identity only. Everything a reader sees (title, description, keywords)
        // is versioned on the revision, so unapproved edits never leak into the library.
        $this->addSql(<<<'SQL'
            CREATE TABLE document (
                id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                document_number  text NOT NULL,
                document_type_id uuid NOT NULL REFERENCES document_type (id),
                org_unit_id      uuid NOT NULL REFERENCES org_unit (id),
                owner_user_id    uuid NOT NULL REFERENCES app_user (id),
                audience         text NOT NULL DEFAULT 'ALL_EMPLOYEES',
                disposition      text NOT NULL DEFAULT 'ACTIVE',
                created_by       uuid NOT NULL REFERENCES app_user (id),
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                row_version      integer NOT NULL DEFAULT 1,
                CONSTRAINT uq_document_number UNIQUE (document_number),
                CONSTRAINT ck_document_audience CHECK (audience IN ('ALL_EMPLOYEES', 'RESTRICTED')),
                CONSTRAINT ck_document_disposition CHECK (disposition IN ('ACTIVE', 'OBSOLETE', 'ARCHIVED'))
            )
            SQL);
        $this->addSql('CREATE INDEX ix_document_owner ON document (owner_user_id)');
        $this->addSql('CREATE INDEX ix_document_number_trgm ON document USING gin (document_number gin_trgm_ops)');

        // Grants to exactly one principal: a user or a tracked directory group.
        $this->addSql(<<<'SQL'
            CREATE TABLE document_grant (
                id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                document_id uuid NOT NULL REFERENCES document (id),
                user_id     uuid NULL REFERENCES app_user (id),
                group_id    uuid NULL REFERENCES directory_group (id),
                role        text NOT NULL,
                granted_by  uuid NOT NULL REFERENCES app_user (id),
                valid_from  timestamptz NOT NULL DEFAULT now(),
                valid_to    timestamptz NULL,
                CONSTRAINT ck_document_grant_principal CHECK ((user_id IS NULL) <> (group_id IS NULL)),
                CONSTRAINT ck_document_grant_role CHECK (role IN ('AUTHOR', 'APPROVER', 'READER')),
                CONSTRAINT ck_document_grant_period CHECK (valid_to IS NULL OR valid_to > valid_from)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_document_grant_user_open ON document_grant (document_id, user_id, role) WHERE valid_to IS NULL AND user_id IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_document_grant_group_open ON document_grant (document_id, group_id, role) WHERE valid_to IS NULL AND group_id IS NOT NULL');
        $this->addSql('CREATE INDEX ix_document_grant_user ON document_grant (user_id) WHERE valid_to IS NULL');
        $this->addSql('CREATE INDEX ix_document_grant_group ON document_grant (group_id) WHERE valid_to IS NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE revision (
                id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                document_id           uuid NOT NULL REFERENCES document (id),
                major                 integer NOT NULL,
                minor                 integer NOT NULL DEFAULT 0,
                label_confirmed       boolean NOT NULL DEFAULT false,
                state                 text NOT NULL DEFAULT 'DRAFT',
                title                 text NOT NULL,
                description           text NULL,
                keywords              text[] NOT NULL DEFAULT '{}',
                change_summary        text NULL,
                content_manifest_hash char(64) NULL,
                previous_revision_id  uuid NULL REFERENCES revision (id),
                search_vector         tsvector NULL,
                created_by            uuid NOT NULL REFERENCES app_user (id),
                created_at            timestamptz NOT NULL DEFAULT now(),
                updated_at            timestamptz NOT NULL DEFAULT now(),
                submitted_at          timestamptz NULL,
                approved_at           timestamptz NULL,
                row_version           integer NOT NULL DEFAULT 1,
                CONSTRAINT ck_revision_state CHECK (state IN ('DRAFT', 'IN_WORKFLOW', 'CHANGES_REQUESTED', 'REJECTED', 'APPROVED', 'WITHDRAWN')),
                CONSTRAINT ck_revision_title CHECK (char_length(title) BETWEEN 1 AND 300),
                CONSTRAINT ck_revision_version CHECK (major >= 1 AND minor >= 0),
                CONSTRAINT ck_revision_manifest CHECK (state = 'DRAFT' OR content_manifest_hash IS NOT NULL OR state = 'WITHDRAWN')
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_revision_open_draft ON revision (document_id) WHERE state IN (\'DRAFT\', \'IN_WORKFLOW\', \'CHANGES_REQUESTED\', \'REJECTED\')');
        $this->addSql('CREATE UNIQUE INDEX uq_revision_label ON revision (document_id, major, minor) WHERE label_confirmed');
        $this->addSql('CREATE INDEX ix_revision_document ON revision (document_id, created_at)');
        $this->addSql('CREATE INDEX ix_revision_search ON revision USING gin (search_vector)');

        $this->addSql(<<<'SQL'
            CREATE TABLE revision_file (
                revision_id       uuid NOT NULL REFERENCES revision (id),
                sha256            char(64) NOT NULL REFERENCES stored_file (sha256),
                role              text NOT NULL,
                original_filename text NOT NULL,
                PRIMARY KEY (revision_id, role),
                CONSTRAINT ck_revision_file_role CHECK (role IN ('PRIMARY'))
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE workflow_instance (
                id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                revision_id         uuid NOT NULL REFERENCES revision (id),
                status              text NOT NULL DEFAULT 'ACTIVE',
                bound_manifest_hash char(64) NOT NULL,
                initiated_by        uuid NOT NULL REFERENCES app_user (id),
                started_at          timestamptz NOT NULL DEFAULT now(),
                completed_at        timestamptz NULL,
                CONSTRAINT ck_workflow_instance_status CHECK (status IN ('ACTIVE', 'APPROVED', 'REJECTED', 'CHANGES_REQUESTED', 'CANCELLED'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_workflow_instance_active ON workflow_instance (revision_id) WHERE status = \'ACTIVE\'');

        $this->addSql(<<<'SQL'
            CREATE TABLE workflow_task (
                id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                instance_id      uuid NOT NULL REFERENCES workflow_instance (id),
                assignee_user_id uuid NOT NULL REFERENCES app_user (id),
                status           text NOT NULL DEFAULT 'OPEN',
                created_at       timestamptz NOT NULL DEFAULT now(),
                completed_at     timestamptz NULL,
                CONSTRAINT ck_workflow_task_status CHECK (status IN ('OPEN', 'DONE', 'CANCELLED'))
            )
            SQL);
        $this->addSql('CREATE INDEX ix_workflow_task_assignee ON workflow_task (assignee_user_id) WHERE status = \'OPEN\'');

        // One terminal decision per task: concurrent clicks collide on the constraint.
        $this->addSql(<<<'SQL'
            CREATE TABLE approval_decision (
                id                        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                task_id                   uuid NOT NULL REFERENCES workflow_task (id),
                revision_id               uuid NOT NULL REFERENCES revision (id),
                actor_user_id             uuid NOT NULL REFERENCES app_user (id),
                actor_snapshot            jsonb NOT NULL,
                decision                  text NOT NULL,
                comment                   text NULL,
                manifest_hash_at_decision char(64) NOT NULL,
                decided_at                timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_approval_decision_task UNIQUE (task_id),
                CONSTRAINT ck_approval_decision CHECK (decision IN ('APPROVE', 'REJECT', 'REQUEST_CHANGES')),
                CONSTRAINT ck_approval_decision_comment CHECK (decision = 'APPROVE' OR char_length(coalesce(comment, '')) > 0)
            )
            SQL);

        // Exactly one effective (and one visible) revision per document and scope at any instant.
        $this->addSql(<<<'SQL'
            CREATE TABLE publication (
                id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                document_id      uuid NOT NULL REFERENCES document (id),
                revision_id      uuid NOT NULL REFERENCES revision (id),
                scope_id         text NOT NULL DEFAULT 'global',
                status           text NOT NULL,
                visible_period   tstzrange NULL,
                effective_period tstzrange NULL,
                is_emergency     boolean NOT NULL DEFAULT false,
                released_by      uuid NULL REFERENCES app_user (id),
                released_at      timestamptz NULL,
                created_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_publication_revision UNIQUE (revision_id, scope_id),
                CONSTRAINT ck_publication_status CHECK (status IN ('SCHEDULED', 'RELEASED', 'CANCELLED')),
                CONSTRAINT ck_publication_released CHECK (status <> 'RELEASED' OR (visible_period IS NOT NULL AND effective_period IS NOT NULL AND released_at IS NOT NULL)),
                CONSTRAINT ex_publication_effective EXCLUDE USING gist (document_id WITH =, scope_id WITH =, effective_period WITH &&) WHERE (status = 'RELEASED'),
                CONSTRAINT ex_publication_visible EXCLUDE USING gist (document_id WITH =, scope_id WITH =, visible_period WITH &&) WHERE (status = 'RELEASED')
            )
            SQL);

        // Search index over versioned metadata plus the document number.
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

        // Content immutability (defence in depth behind the domain rules): versioned content
        // changes only while DRAFT (or on the explicit reopen of a returned revision), and an
        // APPROVED revision can never change state or content.
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
        $this->addSql('CREATE TRIGGER trg_revision_immutable BEFORE UPDATE ON revision FOR EACH ROW EXECUTE FUNCTION pal_revision_immutable()');

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION pal_revision_file_draft_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                rev_state text;
            BEGIN
                SELECT state INTO rev_state FROM revision WHERE id = COALESCE(NEW.revision_id, OLD.revision_id);
                IF rev_state IS DISTINCT FROM 'DRAFT' THEN
                    RAISE EXCEPTION 'files of revision % can only change while DRAFT', COALESCE(NEW.revision_id, OLD.revision_id)
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER trg_revision_file_draft_only BEFORE INSERT OR UPDATE OR DELETE ON revision_file FOR EACH ROW EXECUTE FUNCTION pal_revision_file_draft_only()');
    }

    public function down(Schema $schema): void
    {
        // Development only: never run down-migrations against real data.
        $this->addSql('DROP TABLE publication');
        $this->addSql('DROP TABLE approval_decision');
        $this->addSql('DROP TABLE workflow_task');
        $this->addSql('DROP TABLE workflow_instance');
        $this->addSql('DROP TABLE revision_file');
        $this->addSql('DROP TABLE revision');
        $this->addSql('DROP TABLE document_grant');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE upload');
        $this->addSql('DROP TABLE stored_file');
        $this->addSql('DROP TABLE numbering_counter');
        $this->addSql('DROP TABLE document_type');
        $this->addSql('DROP TABLE org_unit');
        $this->addSql('DROP FUNCTION pal_revision_search_vector()');
        $this->addSql('DROP FUNCTION pal_revision_immutable()');
        $this->addSql('DROP FUNCTION pal_revision_file_draft_only()');
    }
}
