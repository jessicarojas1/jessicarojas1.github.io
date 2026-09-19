-- ORRERY — GMRE Program Portal Framework
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
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
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
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Document METADATA mirror only; bytes/versions live in SharePoint.
CREATE TABLE IF NOT EXISTS document_ref (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id    BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    zone          TEXT   NOT NULL CHECK (zone IN
                    ('project','subcontractor_shared','company','customer','contracts','financial')),
    company_scope JSONB  NOT NULL DEFAULT '[]'::jsonb,
    sp_item_id    TEXT,                          -- SharePoint/Graph item id
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
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS job_requisition (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    title       TEXT NOT NULL,
    audience    JSONB NOT NULL DEFAULT '[]'::jsonb,
    ats_url     TEXT,
    status      TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','filled','closed')),
    expire_at   TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS program_contact (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    program_id  BIGINT NOT NULL REFERENCES program(id) ON DELETE CASCADE,
    user_id     BIGINT REFERENCES app_user(id),
    freeform    TEXT,
    role_label  TEXT,
    company_id  BIGINT REFERENCES company(id),
    visibility  JSONB NOT NULL DEFAULT '[]'::jsonb
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
-- Indexes (idempotent)
-- ---------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_prog_member_user   ON program_membership(user_id);
CREATE INDEX IF NOT EXISTS idx_announcement_prog  ON announcement(program_id);
CREATE INDEX IF NOT EXISTS idx_docref_prog_zone   ON document_ref(program_id, zone);
CREATE INDEX IF NOT EXISTS idx_taskorder_prog     ON task_order(program_id);
CREATE INDEX IF NOT EXISTS idx_job_prog           ON job_requisition(program_id);
CREATE INDEX IF NOT EXISTS idx_audit_prog_at      ON audit_event(program_id, at);

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
