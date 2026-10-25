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
-- Version20261026000001.
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
