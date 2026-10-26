-- =============================================================================
-- PAL (Enterprise Process Asset Library): complete database schema
-- =============================================================================
-- Manual-setup reference. The AUTHORITATIVE installer is Doctrine Migrations:
--
--     php bin/console doctrine:migrations:migrate --no-interaction
--
-- (run from pal/backend). This file must always equal the combined result of all
-- migrations; tests/Integration/SchemaParityTest applies it twice and compares it
-- with the migrated schema, so drift fails CI.
--
-- Idempotent: safe to run repeatedly against a fresh or existing database.
-- Requires PostgreSQL 16+ (17 in production). Covers migrations up to
-- Version20261026000002.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- Identity & directory
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS app_user (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    idp_tenant_id       uuid NOT NULL,
    idp_object_id       uuid NOT NULL,
    display_name        text NOT NULL,
    email               text NULL,
    user_principal_name text NULL,
    timezone            text NULL,
    is_active           boolean NOT NULL DEFAULT true,
    source              text NOT NULL,
    first_login_at      timestamptz NULL,
    last_login_at       timestamptz NULL,
    directory_synced_at timestamptz NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_app_user_idp UNIQUE (idp_tenant_id, idp_object_id),
    CONSTRAINT ck_app_user_display_name CHECK (char_length(display_name) BETWEEN 1 AND 256),
    CONSTRAINT ck_app_user_source CHECK (source IN ('LOGIN', 'DIRECTORY_SYNC'))
);

CREATE TABLE IF NOT EXISTS directory_group (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    idp_tenant_id uuid NOT NULL,
    idp_object_id uuid NOT NULL,
    display_name  text NOT NULL,
    tracked       boolean NOT NULL DEFAULT true,
    synced_at     timestamptz NULL,
    sync_error    text NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_directory_group_idp UNIQUE (idp_tenant_id, idp_object_id)
);

-- Membership history is kept (valid_to) so "who could read this on date X?" stays answerable.
CREATE TABLE IF NOT EXISTS group_membership (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    group_id   uuid NOT NULL REFERENCES directory_group (id),
    user_id    uuid NOT NULL REFERENCES app_user (id),
    valid_from timestamptz NOT NULL DEFAULT now(),
    valid_to   timestamptz NULL,
    CONSTRAINT ck_group_membership_period CHECK (valid_to IS NULL OR valid_to > valid_from)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_group_membership_open ON group_membership (group_id, user_id) WHERE valid_to IS NULL;
CREATE INDEX IF NOT EXISTS ix_group_membership_user_open ON group_membership (user_id) WHERE valid_to IS NULL;

-- Reader is implicit for every active user, so it is not mappable.
CREATE TABLE IF NOT EXISTS global_role_mapping (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    role       text NOT NULL,
    group_id   uuid NOT NULL REFERENCES directory_group (id),
    created_by uuid NULL REFERENCES app_user (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_global_role_mapping UNIQUE (role, group_id),
    CONSTRAINT ck_global_role_mapping_role CHECK (role IN ('SYSTEM_ADMINISTRATOR', 'PAL_ADMINISTRATOR', 'PUBLISHER', 'AUTHOR'))
);


-- -----------------------------------------------------------------------------
-- Settings
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS org_setting (
    key        text PRIMARY KEY,
    value      jsonb NOT NULL,
    updated_by uuid NULL REFERENCES app_user (id),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_org_setting_key CHECK (key ~ '^[a-z][a-z0-9_.]{1,63}$')
);

-- Seed: organisation default timezone (decision D6a).
INSERT INTO org_setting (key, value) VALUES ('default_timezone', '"America/Denver"')
ON CONFLICT (key) DO NOTHING;


-- -----------------------------------------------------------------------------
-- Audit trail (append-only, hash-chained)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_event (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id       uuid NOT NULL,
    occurred_at    timestamptz NOT NULL,
    event_type     text NOT NULL,
    actor_user_id  uuid NULL REFERENCES app_user (id),
    actor_snapshot jsonb NULL,
    correlation_id text NULL,
    subject_type   text NULL,
    subject_id     text NULL,
    from_state     text NULL,
    to_state       text NULL,
    changes        jsonb NULL,
    reason         text NULL,
    prev_hash      char(64) NOT NULL,
    hash           char(64) NOT NULL,
    CONSTRAINT uq_audit_event_event_id UNIQUE (event_id),
    CONSTRAINT uq_audit_event_hash UNIQUE (hash),
    CONSTRAINT ck_audit_event_type CHECK (event_type ~ '^[A-Z][A-Z0-9_]{2,63}$')
);
CREATE INDEX IF NOT EXISTS ix_audit_event_occurred_at ON audit_event (occurred_at);
CREATE INDEX IF NOT EXISTS ix_audit_event_actor ON audit_event (actor_user_id, occurred_at);
CREATE INDEX IF NOT EXISTS ix_audit_event_type ON audit_event (event_type, occurred_at);
CREATE INDEX IF NOT EXISTS ix_audit_event_subject ON audit_event (subject_type, subject_id);

CREATE OR REPLACE FUNCTION pal_audit_event_immutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'audit_event is append-only: % is not permitted', TG_OP
        USING ERRCODE = 'insufficient_privilege';
END
$$;

CREATE OR REPLACE TRIGGER trg_audit_event_no_update_delete
    BEFORE UPDATE OR DELETE ON audit_event
    FOR EACH ROW EXECUTE FUNCTION pal_audit_event_immutable();
CREATE OR REPLACE TRIGGER trg_audit_event_no_truncate
    BEFORE TRUNCATE ON audit_event
    FOR EACH STATEMENT EXECUTE FUNCTION pal_audit_event_immutable();


-- -----------------------------------------------------------------------------
-- Platform: HTTP sessions (Symfony PdoSessionHandler) and durable queue (Messenger)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sessions (
    sess_id       varchar(128) NOT NULL PRIMARY KEY,
    sess_data     bytea NOT NULL,
    sess_lifetime integer NOT NULL,
    sess_time     integer NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_sessions_lifetime ON sessions (sess_lifetime);

CREATE TABLE IF NOT EXISTS messenger_messages (
    id           bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    body         text NOT NULL,
    headers      text NOT NULL,
    queue_name   varchar(190) NOT NULL,
    created_at   timestamp(0) without time zone NOT NULL,
    available_at timestamp(0) without time zone NOT NULL,
    delivered_at timestamp(0) without time zone DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS ix_messenger_messages_queue ON messenger_messages (queue_name, available_at, delivered_at, id);

-- -----------------------------------------------------------------------------
-- Phase 2: document repository (Version20261026000002)
-- Reference data, numbering, uploads/stored files, documents, versioned revisions,
-- grants, single-approver workflow, publications with effectivity constraints.
-- -----------------------------------------------------------------------------

CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE TABLE IF NOT EXISTS org_unit (
    id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    code       text NOT NULL,
    name       text NOT NULL,
    is_active  boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT uq_org_unit_code UNIQUE (code),
    CONSTRAINT ck_org_unit_code CHECK (code ~ '^[A-Z0-9]{2,10}$'),
    CONSTRAINT ck_org_unit_name CHECK (char_length(name) BETWEEN 1 AND 120)
);

CREATE TABLE IF NOT EXISTS document_type (
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
);

INSERT INTO document_type (code, name, review_interval_months) VALUES
    ('POL', 'Policy', 12), ('PRC', 'Process', 24), ('SOP', 'Procedure', 24),
    ('STD', 'Standard', 24), ('WI', 'Work Instruction', 24), ('TPL', 'Template', NULL),
    ('FRM', 'Form', NULL), ('GDL', 'Guideline', 24), ('REF', 'Reference Document', NULL)
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS numbering_counter (
    document_type_id uuid NOT NULL REFERENCES document_type (id),
    org_unit_id      uuid NOT NULL REFERENCES org_unit (id),
    next_value       integer NOT NULL DEFAULT 1,
    PRIMARY KEY (document_type_id, org_unit_id),
    CONSTRAINT ck_numbering_counter_value CHECK (next_value >= 1)
);

CREATE TABLE IF NOT EXISTS stored_file (
    sha256          char(64) PRIMARY KEY,
    size_bytes      bigint NOT NULL,
    detected_mime   text NOT NULL,
    scan_engine     text NOT NULL,
    scanned_at      timestamptz NOT NULL,
    storage_backend text NOT NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT ck_stored_file_sha CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT ck_stored_file_size CHECK (size_bytes > 0)
);

CREATE TABLE IF NOT EXISTS upload (
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
);

CREATE INDEX IF NOT EXISTS ix_upload_user ON upload (uploaded_by, created_at);

CREATE TABLE IF NOT EXISTS document (
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
);

CREATE INDEX IF NOT EXISTS ix_document_owner ON document (owner_user_id);

CREATE INDEX IF NOT EXISTS ix_document_number_trgm ON document USING gin (document_number gin_trgm_ops);

CREATE TABLE IF NOT EXISTS document_grant (
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
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_document_grant_user_open ON document_grant (document_id, user_id, role) WHERE valid_to IS NULL AND user_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_document_grant_group_open ON document_grant (document_id, group_id, role) WHERE valid_to IS NULL AND group_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS ix_document_grant_user ON document_grant (user_id) WHERE valid_to IS NULL;

CREATE INDEX IF NOT EXISTS ix_document_grant_group ON document_grant (group_id) WHERE valid_to IS NULL;

CREATE TABLE IF NOT EXISTS revision (
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
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_revision_open_draft ON revision (document_id) WHERE state IN ('DRAFT', 'IN_WORKFLOW', 'CHANGES_REQUESTED', 'REJECTED');

CREATE UNIQUE INDEX IF NOT EXISTS uq_revision_label ON revision (document_id, major, minor) WHERE label_confirmed;

CREATE INDEX IF NOT EXISTS ix_revision_document ON revision (document_id, created_at);

CREATE INDEX IF NOT EXISTS ix_revision_search ON revision USING gin (search_vector);

CREATE TABLE IF NOT EXISTS revision_file (
    revision_id       uuid NOT NULL REFERENCES revision (id),
    sha256            char(64) NOT NULL REFERENCES stored_file (sha256),
    role              text NOT NULL,
    original_filename text NOT NULL,
    PRIMARY KEY (revision_id, role),
    CONSTRAINT ck_revision_file_role CHECK (role IN ('PRIMARY'))
);

CREATE TABLE IF NOT EXISTS workflow_instance (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    revision_id         uuid NOT NULL REFERENCES revision (id),
    status              text NOT NULL DEFAULT 'ACTIVE',
    bound_manifest_hash char(64) NOT NULL,
    initiated_by        uuid NOT NULL REFERENCES app_user (id),
    started_at          timestamptz NOT NULL DEFAULT now(),
    completed_at        timestamptz NULL,
    CONSTRAINT ck_workflow_instance_status CHECK (status IN ('ACTIVE', 'APPROVED', 'REJECTED', 'CHANGES_REQUESTED', 'CANCELLED'))
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_workflow_instance_active ON workflow_instance (revision_id) WHERE status = 'ACTIVE';

CREATE TABLE IF NOT EXISTS workflow_task (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    instance_id      uuid NOT NULL REFERENCES workflow_instance (id),
    assignee_user_id uuid NOT NULL REFERENCES app_user (id),
    status           text NOT NULL DEFAULT 'OPEN',
    created_at       timestamptz NOT NULL DEFAULT now(),
    completed_at     timestamptz NULL,
    CONSTRAINT ck_workflow_task_status CHECK (status IN ('OPEN', 'DONE', 'CANCELLED'))
);

CREATE INDEX IF NOT EXISTS ix_workflow_task_assignee ON workflow_task (assignee_user_id) WHERE status = 'OPEN';

CREATE TABLE IF NOT EXISTS approval_decision (
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
);

CREATE TABLE IF NOT EXISTS publication (
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
);

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
$$;

CREATE OR REPLACE TRIGGER trg_revision_search_vector BEFORE INSERT OR UPDATE OF title, description, keywords ON revision FOR EACH ROW EXECUTE FUNCTION pal_revision_search_vector();

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
$$;

CREATE OR REPLACE TRIGGER trg_revision_immutable BEFORE UPDATE ON revision FOR EACH ROW EXECUTE FUNCTION pal_revision_immutable();

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
$$;

CREATE OR REPLACE TRIGGER trg_revision_file_draft_only BEFORE INSERT OR UPDATE OR DELETE ON revision_file FOR EACH ROW EXECUTE FUNCTION pal_revision_file_draft_only();
