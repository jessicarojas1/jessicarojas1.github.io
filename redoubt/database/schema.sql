-- REDOUBT — GMRE Program Portal Framework
-- database/schema.sql — INITIAL, IDEMPOTENT design schema (PostgreSQL).
--
-- STATUS: Discovery-phase DESIGN schema derived from the conceptual data model
-- (Discovery Package §17). It is not yet exercised by application code and WILL
-- change once the blocking decisions in OPEN_ITEMS.md are resolved. It is safe to
-- run against a fresh database. Authoritative installer for the app will be the
-- Phase 1 installer/migrations; keep this file updated to reflect the full,
-- combined schema across all migrations.
--
-- Design rules encoded here:
--   * Documents live in SharePoint (system of record). This DB stores only
--     metadata/references, portal-owned content, config, and audit.
--   * Every content row is program-scoped (program_id) and carries audience/
--     company scoping so queries can trim by (program × company × role × zone).
--   * audit_event is append-only (enforce no UPDATE/DELETE via role grants in prod).

-- ---------------------------------------------------------------------------
-- Enumerations (as CHECK constraints for portability)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS company (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name          TEXT        NOT NULL,
    kind          TEXT        NOT NULL DEFAULT 'sub'
                              CHECK (kind IN ('prime','sub','customer')),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS app_user (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    entra_oid     TEXT        UNIQUE,           -- Entra ID object id (null until provisioned)
    display_name  TEXT        NOT NULL,
    email         TEXT        NOT NULL,
    kind          TEXT        NOT NULL DEFAULT 'internal'
                              CHECK (kind IN ('internal','external','customer')),
    status        TEXT        NOT NULL DEFAULT 'active'
                              CHECK (status IN ('invited','active','suspended','removed')),
    -- Export control (ITAR/EAR): verified at provisioning, enforced server-side.
    is_us_person           BOOLEAN,               -- NULL until verified
    us_person_verified_at  TIMESTAMPTZ,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS role (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key                TEXT UNIQUE NOT NULL,     -- e.g. 'program_manager','content_manager'
    name               TEXT NOT NULL,
    is_external_allowed BOOLEAN NOT NULL DEFAULT false
);

CREATE TABLE IF NOT EXISTS role_permission (
    role_id        BIGINT NOT NULL REFERENCES role(id) ON DELETE CASCADE,
    permission_key TEXT   NOT NULL,             -- e.g. 'announcement.publish'
    PRIMARY KEY (role_id, permission_key)
);

CREATE TABLE IF NOT EXISTS program (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            TEXT        NOT NULL,
    customer        TEXT,
    contract_number TEXT,
    theme_color     TEXT,
    logo_url        TEXT,
    enabled_modules JSONB       NOT NULL DEFAULT '[]'::jsonb,
    status          TEXT        NOT NULL DEFAULT 'active'
                                CHECK (status IN ('draft','active','archived')),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS program_config (
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    key         TEXT   NOT NULL,
    json_value  JSONB  NOT NULL DEFAULT '{}'::jsonb,
    PRIMARY KEY (program_id, key)
);

CREATE TABLE IF NOT EXISTS company_membership (
    company_id       BIGINT NOT NULL REFERENCES company(id) ON DELETE CASCADE,
    user_id          BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    is_company_admin BOOLEAN NOT NULL DEFAULT false,
    PRIMARY KEY (company_id, user_id)
);

CREATE TABLE IF NOT EXISTS program_membership (
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    user_id     BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    role_id     BIGINT NOT NULL REFERENCES role(id),
    company_id  BIGINT REFERENCES company(id),
    expires_at  TIMESTAMPTZ,                    -- contract-bound expiry for externals
    PRIMARY KEY (program_id, user_id, role_id)
);

-- ---------------------------------------------------------------------------
-- Portal-owned content (program-scoped + audience/company-scoped)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS announcement (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    title       TEXT NOT NULL,
    body        TEXT NOT NULL,
    audience    JSONB NOT NULL DEFAULT '[]'::jsonb,   -- roles/companies/zones
    priority    TEXT  NOT NULL DEFAULT 'normal' CHECK (priority IN ('normal','high','critical')),
    publish_at  TIMESTAMPTZ,
    expire_at   TIMESTAMPTZ,
    created_by  BIGINT REFERENCES app_user(id),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Document METADATA mirror only; bytes/versions live in SharePoint.
CREATE TABLE IF NOT EXISTS document_ref (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id    BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    zone          TEXT   NOT NULL CHECK (zone IN
                    ('project','subcontractor_shared','company','customer','contracts','financial')),
    company_scope JSONB  NOT NULL DEFAULT '[]'::jsonb,
    -- Export control: true = access additionally gated to US-persons + license scope.
    export_controlled BOOLEAN NOT NULL DEFAULT false,
    cui_marked        BOOLEAN NOT NULL DEFAULT false,
    sp_item_id    TEXT,                          -- SharePoint/Graph item id (for Graph resolve)
    web_url       TEXT,                          -- SharePoint web URL (direct-open fallback)
    title         TEXT,
    updated_at    TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS task_order (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id    BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    number        TEXT NOT NULL,
    title         TEXT,
    status        TEXT,
    company_scope JSONB NOT NULL DEFAULT '[]'::jsonb,
    sp_link       TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS job_requisition (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    title       TEXT NOT NULL,
    audience    JSONB NOT NULL DEFAULT '[]'::jsonb,
    ats_url     TEXT,
    status      TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','open','filled','closed')),
    -- Targeting: audience tokens (all/internal/customer/role:) + company ids +
    -- task-order ids. Empty across all three = program-wide.
    company_scope    JSONB NOT NULL DEFAULT '[]'::jsonb,  -- company ids
    task_order_scope JSONB NOT NULL DEFAULT '[]'::jsonb,  -- task_order ids (contract attachment)
    expire_at   TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS program_contact (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    user_id     BIGINT REFERENCES app_user(id),
    freeform    TEXT,
    role_label  TEXT,
    company_id  BIGINT REFERENCES company(id),
    visibility  JSONB NOT NULL DEFAULT '[]'::jsonb,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS milestone (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    title       TEXT NOT NULL,
    due_date    DATE,
    type        TEXT CHECK (type IN ('CDRL','event','deliverable'))
);

CREATE TABLE IF NOT EXISTS quick_link (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    label       TEXT NOT NULL,
    url         TEXT NOT NULL,
    audience    JSONB NOT NULL DEFAULT '[]'::jsonb
);

CREATE TABLE IF NOT EXISTS faq (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    question    TEXT NOT NULL,
    answer      TEXT NOT NULL,
    audience    JSONB NOT NULL DEFAULT '[]'::jsonb
);

CREATE TABLE IF NOT EXISTS notification (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    type        TEXT NOT NULL,
    ref         TEXT,
    read_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Append-only audit trail (grant only INSERT/SELECT to the app role in prod).
CREATE TABLE IF NOT EXISTS audit_event (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT,
    actor_id    BIGINT,
    action      TEXT NOT NULL,
    target      TEXT,
    ip          INET,
    at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Extreme IAM: explicit per-user permission grants/denials (layered on top of
-- role defaults; denials win). Enables the two-pane IAM editor's orange "explicit
-- grant" dots vs green "role default" vs gray "denied".
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_permission_grant (
    program_id     BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    user_id        BIGINT NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
    permission_key TEXT   NOT NULL,               -- granular, e.g. 'risk.accept'
    effect         TEXT   NOT NULL DEFAULT 'grant' CHECK (effect IN ('grant','deny')),
    granted_by     BIGINT REFERENCES app_user(id),
    granted_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (program_id, user_id, permission_key)
);

-- ---------------------------------------------------------------------------
-- API clients (machine access). Only a hash of the secret is stored.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_client (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_ref  TEXT UNIQUE NOT NULL,             -- public id portion of the token
    name        TEXT NOT NULL,
    program_id  BIGINT REFERENCES program(id) ON DELETE CASCADE,
    key_hash    TEXT NOT NULL,                    -- sha256 of the secret portion
    scopes      JSONB NOT NULL DEFAULT '[]'::jsonb,
    active      BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ
);

-- ---------------------------------------------------------------------------
-- Webhooks: program-scoped subscriptions + delivery log (HMAC-signed payloads).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhook_subscription (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT REFERENCES program(id) ON DELETE CASCADE,
    url         TEXT NOT NULL,
    secret      TEXT NOT NULL,                    -- HMAC signing secret
    events      JSONB NOT NULL DEFAULT '[]'::jsonb, -- e.g. ["announcement.published"]
    active      BOOLEAN NOT NULL DEFAULT true,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS webhook_delivery (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    subscription_id BIGINT NOT NULL REFERENCES webhook_subscription(id) ON DELETE CASCADE,
    event           TEXT NOT NULL,
    status          TEXT NOT NULL CHECK (status IN ('delivered','failed','pending')),
    response_code   INTEGER,
    attempts        INTEGER NOT NULL DEFAULT 1,
    at              TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- Integration connectors (config for enterprise system links; secrets by ref).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS integration_connector (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT REFERENCES program(id) ON DELETE CASCADE,
    kind        TEXT NOT NULL,                    -- 'ats' | 'finance' | 'contracts' | 'teams' | ...
    config      JSONB NOT NULL DEFAULT '{}'::jsonb,
    secret_ref  TEXT,                             -- name/path in the secret manager
    active      BOOLEAN NOT NULL DEFAULT true
);

-- ---------------------------------------------------------------------------
-- Indexes (idempotent)
-- ---------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_prog_member_user   ON program_membership(user_id);
CREATE INDEX IF NOT EXISTS idx_announcement_prog  ON announcement(program_id);
CREATE INDEX IF NOT EXISTS idx_docref_prog_zone   ON document_ref(program_id, zone);
CREATE INDEX IF NOT EXISTS idx_taskorder_prog     ON task_order(program_id);
CREATE INDEX IF NOT EXISTS idx_job_prog           ON job_requisition(program_id);
CREATE INDEX IF NOT EXISTS idx_audit_prog_at      ON audit_event(program_id, at);
CREATE INDEX IF NOT EXISTS idx_grant_user         ON user_permission_grant(user_id);
CREATE INDEX IF NOT EXISTS idx_apiclient_ref      ON api_client(client_ref);
CREATE INDEX IF NOT EXISTS idx_websub_prog        ON webhook_subscription(program_id);
CREATE INDEX IF NOT EXISTS idx_webdel_sub_at      ON webhook_delivery(subscription_id, at);

-- ---------------------------------------------------------------------------
-- Seed: baseline roles (idempotent)
-- ---------------------------------------------------------------------------
INSERT INTO role (key, name, is_external_allowed) VALUES
    ('enterprise_admin','Enterprise Administrator', false),
    ('program_admin','Program Administrator', false),
    ('program_manager','Program Manager', false),
    ('content_manager','Content Manager', false),
    ('contracts','Contracts', false),
    ('finance','Finance', false),
    ('recruiting','Recruiting', false),
    ('internal_member','Internal Program Member', false),
    ('sub_admin','Subcontractor Administrator', true),
    ('sub_member','Subcontractor Member', true),
    ('customer_cor','Customer / COR', true),
    ('security_admin','Security Administrator', false)
ON CONFLICT (key) DO NOTHING;

-- ---------------------------------------------------------------------------
-- PORTABILITY NOTE
-- Targets PostgreSQL 10+ (uses GENERATED ALWAYS AS IDENTITY). For MySQL/MariaDB,
-- swap identity columns to BIGINT AUTO_INCREMENT and JSONB to JSON. The
-- identity/primary-key strategy will be re-confirmed with the Phase 1 installer
-- (see OPEN_ITEMS.md); this file is the current combined design schema.
-- ---------------------------------------------------------------------------
