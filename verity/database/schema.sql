-- =============================================================================
-- VERITY — Enterprise Identity & Access Governance
-- database/schema.sql
--
-- This is a manual-setup REFERENCE schema: a complete, idempotent script that
-- can be run against a fresh PostgreSQL database to produce a fully functional
-- schema. The authoritative installer path is the application itself reading
-- this same file (see deployments/LOCAL_DEVELOPMENT.md and docs/DEPLOYMENT.md).
--
-- Scope: this file reflects tables for the modules implemented so far —
-- Identity Directory, Access Inventory (accounts/entitlements), Application
-- Catalog, a read-model connector catalog, Dynamic Fields, Saved Views, Admin
-- IAM, Settings/Branding, and Audit. Tables for not-yet-built modules
-- (certification campaigns, workflows, remediation, access requests, risk
-- scoring, separation-of-duties, notifications) are NOT in this file yet —
-- see OPEN_ITEMS.md for the honest status. This file MUST be updated to stay
-- current whenever a migration adds/changes a table.
--
-- All tables use CREATE TABLE IF NOT EXISTS so this script is safe to re-run.
-- Table order matters (foreign keys reference earlier tables).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Module: Platform configuration
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS app_config (
    key         TEXT PRIMARY KEY,
    json_value  JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- -----------------------------------------------------------------------------
-- Module: Identity Directory
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS person (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id             TEXT UNIQUE,
    first_name              TEXT NOT NULL,
    last_name               TEXT NOT NULL,
    display_name            TEXT NOT NULL,
    email                   TEXT,
    department              TEXT,
    business_unit           TEXT,
    position_title          TEXT,
    manager_person_id       BIGINT REFERENCES person(id) ON DELETE SET NULL,
    location                TEXT,
    identity_type           TEXT NOT NULL DEFAULT 'employee'
                             CHECK (identity_type IN ('employee','contractor','guest','external_partner','service_identity','shared_account','non_human_identity')),
    employment_status       TEXT NOT NULL DEFAULT 'active'
                             CHECK (employment_status IN ('active','on_leave','terminated')),
    start_date              DATE,
    termination_date        DATE,
    identity_authority      TEXT NOT NULL DEFAULT 'manual',
    last_synced_at          TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_person_manager ON person(manager_person_id);
CREATE INDEX IF NOT EXISTS idx_person_department ON person(department);
CREATE INDEX IF NOT EXISTS idx_person_employment_status ON person(employment_status);

-- Secondary relationships (delegate reviewers, dotted-line reporting). The
-- primary manager chain used for supervisor-scope authorization is always
-- person.manager_person_id; this table is additive and not yet surfaced in
-- the UI (see ../OPEN_ITEMS.md).
CREATE TABLE IF NOT EXISTS person_relationship (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    person_id           BIGINT NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    related_person_id   BIGINT NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    relationship_type   TEXT NOT NULL CHECK (relationship_type IN ('manager','delegate')),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_person_relationship_person ON person_relationship(person_id);

-- -----------------------------------------------------------------------------
-- Module: Platform users, roles & permissions (Admin IAM)
-- Created before Application/Account tables below, which reference app_user.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS app_user (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email            TEXT NOT NULL UNIQUE,
    display_name     TEXT NOT NULL,
    password_hash    TEXT,
    status           TEXT NOT NULL DEFAULT 'invited' CHECK (status IN ('active','invited','disabled')),
    person_id        BIGINT REFERENCES person(id) ON DELETE SET NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_app_user_person ON app_user(person_id);

CREATE TABLE IF NOT EXISTS app_user_role (
    user_id     BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    role_key    TEXT NOT NULL CHECK (role_key IN ('enterprise_admin','security_admin','supervisor','system_owner','auditor')),
    PRIMARY KEY (user_id, role_key)
);

CREATE TABLE IF NOT EXISTS user_permission_grant (
    user_id             BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    permission_key      TEXT NOT NULL,
    effect               TEXT NOT NULL DEFAULT 'grant' CHECK (effect IN ('grant','deny')),
    granted_by_user_id   BIGINT REFERENCES app_user(id) ON DELETE SET NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, permission_key)
);

-- -----------------------------------------------------------------------------
-- Module: Application Catalog & Integrations (read-model / configuration only
-- in this pass — no live synchronization execution; see docs/GCC_HIGH_INTEGRATION.md)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS application (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                        TEXT NOT NULL UNIQUE,
    description                 TEXT,
    classification              TEXT NOT NULL DEFAULT 'internal'
                                 CHECK (classification IN ('public','internal','confidential','restricted')),
    system_owner_person_id      BIGINT REFERENCES person(id) ON DELETE SET NULL,
    technical_owner_person_id   BIGINT REFERENCES person(id) ON DELETE SET NULL,
    business_owner_person_id    BIGINT REFERENCES person(id) ON DELETE SET NULL,
    status                      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_application_system_owner ON application(system_owner_person_id);

CREATE TABLE IF NOT EXISTS connector (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    application_id               BIGINT NOT NULL REFERENCES application(id) ON DELETE CASCADE,
    connector_type               TEXT NOT NULL DEFAULT 'manual'
                                 CHECK (connector_type IN ('entra_gcc_high_mock','csv_import','manual','rest_api_manual')),
    auth_method                  TEXT,
    credential_reference         TEXT,  -- a reference string into a secrets manager; NEVER an actual secret
    sync_frequency                TEXT NOT NULL DEFAULT 'manual',
    default_reviewer_person_id   BIGINT REFERENCES person(id) ON DELETE SET NULL,
    default_review_frequency     TEXT NOT NULL DEFAULT 'annual',
    remediation_mode             TEXT NOT NULL DEFAULT 'manual' CHECK (remediation_mode IN ('manual','automated')),
    capability_manifest          JSONB NOT NULL DEFAULT '{}'::jsonb,
    connection_health            TEXT NOT NULL DEFAULT 'unknown'
                                 CHECK (connection_health IN ('unknown','healthy','degraded','failed')),
    created_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_connector_application ON connector(application_id);

CREATE TABLE IF NOT EXISTS connector_sync_job (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    connector_id             BIGINT NOT NULL REFERENCES connector(id) ON DELETE CASCADE,
    started_at                TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at              TIMESTAMPTZ,
    status                    TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running','succeeded','failed','partial')),
    imported_accounts         INT NOT NULL DEFAULT 0,
    imported_entitlements     INT NOT NULL DEFAULT 0,
    failure_count             INT NOT NULL DEFAULT 0,
    error_summary             TEXT,
    triggered_by              TEXT NOT NULL DEFAULT 'manual' CHECK (triggered_by IN ('manual','scheduled','seed'))
);
CREATE INDEX IF NOT EXISTS idx_sync_job_connector ON connector_sync_job(connector_id);

-- -----------------------------------------------------------------------------
-- Module: Access Inventory (accounts, entitlements, assignments)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS system_account (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    application_id        BIGINT NOT NULL REFERENCES application(id) ON DELETE CASCADE,
    person_id             BIGINT REFERENCES person(id) ON DELETE SET NULL,  -- NULL => unmatched/orphaned
    external_account_id   TEXT NOT NULL,
    username              TEXT,
    account_type          TEXT NOT NULL DEFAULT 'standard' CHECK (account_type IN ('standard','privileged','service','shared')),
    status                TEXT NOT NULL DEFAULT 'enabled' CHECK (status IN ('enabled','disabled')),
    source                TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ('connector','manual','csv_import')),
    connector_id          BIGINT REFERENCES connector(id) ON DELETE SET NULL,
    last_login_at         TIMESTAMPTZ,
    last_synced_at        TIMESTAMPTZ,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (application_id, external_account_id)
);
CREATE INDEX IF NOT EXISTS idx_system_account_person ON system_account(person_id);
CREATE INDEX IF NOT EXISTS idx_system_account_application ON system_account(application_id);
CREATE INDEX IF NOT EXISTS idx_system_account_status ON system_account(status);

-- History of correlation decisions. system_account.person_id is the live
-- pointer; this table is the auditable record of how/when it got that way.
CREATE TABLE IF NOT EXISTS identity_account_link (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    system_account_id      BIGINT NOT NULL REFERENCES system_account(id) ON DELETE CASCADE,
    person_id              BIGINT NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    link_method            TEXT NOT NULL DEFAULT 'manual' CHECK (link_method IN ('deterministic','manual')),
    linked_by_user_id       BIGINT REFERENCES app_user(id) ON DELETE SET NULL,
    linked_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    unlinked_at             TIMESTAMPTZ,
    note                    TEXT
);
CREATE INDEX IF NOT EXISTS idx_link_account ON identity_account_link(system_account_id);
CREATE INDEX IF NOT EXISTS idx_link_person ON identity_account_link(person_id);

CREATE TABLE IF NOT EXISTS entitlement (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    application_id      BIGINT NOT NULL REFERENCES application(id) ON DELETE CASCADE,
    name                TEXT NOT NULL,
    entitlement_type    TEXT NOT NULL DEFAULT 'role' CHECK (entitlement_type IN ('role','group','permission','license')),
    description         TEXT,
    is_privileged       BOOLEAN NOT NULL DEFAULT FALSE,
    risk_level          TEXT NOT NULL DEFAULT 'low' CHECK (risk_level IN ('low','medium','high','critical')),
    source              TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ('connector','manual')),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (application_id, name, entitlement_type)
);
CREATE INDEX IF NOT EXISTS idx_entitlement_application ON entitlement(application_id);
CREATE INDEX IF NOT EXISTS idx_entitlement_privileged ON entitlement(is_privileged);

CREATE TABLE IF NOT EXISTS entitlement_assignment (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    system_account_id      BIGINT NOT NULL REFERENCES system_account(id) ON DELETE CASCADE,
    entitlement_id          BIGINT NOT NULL REFERENCES entitlement(id) ON DELETE CASCADE,
    assignment_type         TEXT NOT NULL DEFAULT 'direct' CHECK (assignment_type IN ('direct','inherited','privileged','temporary','external')),
    granted_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at               TIMESTAMPTZ,
    source                   TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ('connector','manual')),
    last_certified_at        TIMESTAMPTZ,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (system_account_id, entitlement_id, assignment_type)
);
CREATE INDEX IF NOT EXISTS idx_assignment_account ON entitlement_assignment(system_account_id);
CREATE INDEX IF NOT EXISTS idx_assignment_entitlement ON entitlement_assignment(entitlement_id);
CREATE INDEX IF NOT EXISTS idx_assignment_expires ON entitlement_assignment(expires_at);

-- -----------------------------------------------------------------------------
-- Module: Dynamic Fields (structured, versioned custom attributes)
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS dynamic_field_definition (
    id                              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    field_key                       TEXT NOT NULL,
    label                           TEXT NOT NULL,
    field_type                      TEXT NOT NULL DEFAULT 'text'
                                     CHECK (field_type IN ('text','long_text','number','decimal','boolean','date','datetime','single_select','multi_select','url')),
    entity_type                     TEXT NOT NULL DEFAULT 'system_account'
                                     CHECK (entity_type IN ('system_account','entitlement_assignment','person')),
    application_id                  BIGINT REFERENCES application(id) ON DELETE CASCADE,  -- NULL = global field
    options                          JSONB,
    required                         BOOLEAN NOT NULL DEFAULT FALSE,
    active                           BOOLEAN NOT NULL DEFAULT TRUE,
    replaces_field_definition_id     BIGINT REFERENCES dynamic_field_definition(id) ON DELETE SET NULL,
    created_at                       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at                       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
-- Partial unique indexes (not a plain UNIQUE) because NULL application_id
-- must still be treated as one "global" scope, not many distinct NULLs.
CREATE UNIQUE INDEX IF NOT EXISTS uq_field_key_global ON dynamic_field_definition(field_key) WHERE application_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_field_key_app ON dynamic_field_definition(field_key, application_id) WHERE application_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_field_definition_active ON dynamic_field_definition(active);

CREATE TABLE IF NOT EXISTS dynamic_field_value (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    field_definition_id      BIGINT NOT NULL REFERENCES dynamic_field_definition(id) ON DELETE CASCADE,
    entity_type               TEXT NOT NULL CHECK (entity_type IN ('system_account','entitlement_assignment','person')),
    entity_id                 BIGINT NOT NULL,
    value                      JSONB,
    set_by_user_id             BIGINT REFERENCES app_user(id) ON DELETE SET NULL,
    set_at                     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (field_definition_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_field_value_entity ON dynamic_field_value(entity_type, entity_id);

-- -----------------------------------------------------------------------------
-- Module: Saved Views
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS saved_view (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    owner_user_id     BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    name              TEXT NOT NULL,
    view_scope        TEXT NOT NULL DEFAULT 'enterprise'
                      CHECK (view_scope IN ('enterprise','person','application','supervisor','privileged','exception')),
    is_shared         BOOLEAN NOT NULL DEFAULT FALSE,
    config            JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_saved_view_owner ON saved_view(owner_user_id);

-- -----------------------------------------------------------------------------
-- Module: Audit
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_event (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    actor_id           BIGINT REFERENCES app_user(id) ON DELETE SET NULL,
    action             TEXT NOT NULL,
    target             TEXT,
    before_value       JSONB,
    after_value        JSONB,
    justification      TEXT,
    correlation_id     TEXT,
    ip                 TEXT,
    result             TEXT NOT NULL DEFAULT 'success' CHECK (result IN ('success','denied','failure')),
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_event(actor_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_event(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_event(action);

-- No UPDATE/DELETE grants should ever be issued to the application's runtime
-- DB role on audit_event in production — insert/select only. This cannot be
-- enforced from this script (it depends on the deployment's role setup); see
-- docs/SECURITY.md.
