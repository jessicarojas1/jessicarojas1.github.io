-- AEGIS GRC — Phase 2 Migration
-- Run after 001_enterprise_phase1.sql

-- ─────────────────────────────────────────────
-- 2.5  Cross-framework control mapping
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS control_mappings (
    id              SERIAL PRIMARY KEY,
    source_obj_id   INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    target_obj_id   INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    mapping_type    VARCHAR(50) NOT NULL DEFAULT 'equivalent',
    -- equivalent | subset | superset | related
    notes           TEXT,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(source_obj_id, target_obj_id)
);
CREATE INDEX IF NOT EXISTS idx_cm_source ON control_mappings(source_obj_id);
CREATE INDEX IF NOT EXISTS idx_cm_target ON control_mappings(target_obj_id);

-- ─────────────────────────────────────────────
-- 2.6  Scheduled report delivery
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS report_schedules (
    id           SERIAL PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    report_type  VARCHAR(100) NOT NULL,  -- compliance | executive | risk | audit
    frequency    VARCHAR(50) NOT NULL DEFAULT 'weekly',
    -- daily | weekly | monthly | quarterly
    day_of_week  SMALLINT,        -- 0=Sun … 6=Sat (for weekly)
    day_of_month SMALLINT,        -- 1-31 (for monthly/quarterly)
    recipients   JSONB NOT NULL DEFAULT '[]',   -- array of email strings
    include_pdf  BOOLEAN NOT NULL DEFAULT FALSE,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    last_sent_at TIMESTAMP,
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS report_schedule_logs (
    id          SERIAL PRIMARY KEY,
    schedule_id INTEGER NOT NULL REFERENCES report_schedules(id) ON DELETE CASCADE,
    sent_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recipients  JSONB,
    status      VARCHAR(50) NOT NULL DEFAULT 'sent',
    error       TEXT
);

-- ─────────────────────────────────────────────
-- 2.8  Custom fields
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS custom_field_definitions (
    id           SERIAL PRIMARY KEY,
    entity_type  VARCHAR(100) NOT NULL,
    -- risk | policy | audit | incident | vendor | issue
    name         VARCHAR(100) NOT NULL,
    label        VARCHAR(255) NOT NULL,
    field_type   VARCHAR(50) NOT NULL DEFAULT 'text',
    -- text | textarea | number | date | select | multiselect | boolean | url
    options      JSONB,           -- for select/multiselect: ["opt1","opt2"]
    is_required  BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order   INTEGER NOT NULL DEFAULT 0,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entity_type, name)
);

CREATE TABLE IF NOT EXISTS custom_field_values (
    id           SERIAL PRIMARY KEY,
    definition_id INTEGER NOT NULL REFERENCES custom_field_definitions(id) ON DELETE CASCADE,
    entity_type  VARCHAR(100) NOT NULL,
    entity_id    INTEGER NOT NULL,
    value_text   TEXT,
    value_number NUMERIC,
    value_date   DATE,
    value_json   JSONB,
    updated_by   INTEGER REFERENCES users(id),
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(definition_id, entity_type, entity_id)
);

CREATE INDEX IF NOT EXISTS idx_cfv_entity ON custom_field_values(entity_type, entity_id);

-- ─────────────────────────────────────────────
-- 2.9  Metrics snapshots
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS metrics_snapshots (
    id                  SERIAL PRIMARY KEY,
    snapshot_date       DATE NOT NULL DEFAULT CURRENT_DATE,
    compliance_pct      NUMERIC(5,2),
    risk_health         NUMERIC(5,2),
    policy_health       NUMERIC(5,2),
    audit_health        NUMERIC(5,2),
    grc_score           NUMERIC(5,2),
    open_risks          INTEGER,
    critical_risks      INTEGER,
    open_incidents      INTEGER,
    critical_incidents  INTEGER,
    open_issues         INTEGER,
    overdue_reviews     INTEGER,
    vendor_count        INTEGER,
    active_audits       INTEGER,
    details             JSONB,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(snapshot_date)
);

CREATE INDEX IF NOT EXISTS idx_ms_date ON metrics_snapshots(snapshot_date DESC);

-- ─────────────────────────────────────────────
-- 2.10 Document management
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS documents (
    id              SERIAL PRIMARY KEY,
    title           VARCHAR(500) NOT NULL,
    doc_number      VARCHAR(100),
    description     TEXT,
    category        VARCHAR(100),
    classification  VARCHAR(50) NOT NULL DEFAULT 'internal',
    -- public | internal | confidential | restricted
    status          VARCHAR(50) NOT NULL DEFAULT 'draft',
    -- draft | under_review | approved | published | archived | expired
    current_version VARCHAR(50) NOT NULL DEFAULT '1.0',
    owner_id        INTEGER REFERENCES users(id),
    approver_id     INTEGER REFERENCES users(id),
    review_frequency VARCHAR(50) DEFAULT 'annual',
    next_review_date DATE,
    expiry_date      DATE,
    approved_at      TIMESTAMP,
    published_at     TIMESTAMP,
    tags             JSONB DEFAULT '[]',
    dlp_metadata     JSONB DEFAULT '{}',
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS document_versions (
    id          SERIAL PRIMARY KEY,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    version     VARCHAR(50) NOT NULL,
    file_name   VARCHAR(500),
    stored_name VARCHAR(500),
    mime_type   VARCHAR(200),
    file_size   INTEGER,
    file_hash   VARCHAR(64),
    change_summary TEXT,
    uploaded_by INTEGER REFERENCES users(id),
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_docs_owner  ON documents(owner_id);
CREATE INDEX IF NOT EXISTS idx_docs_status ON documents(status);
CREATE INDEX IF NOT EXISTS idx_docs_expiry ON documents(expiry_date);
CREATE INDEX IF NOT EXISTS idx_dv_document ON document_versions(document_id);

-- Add source_type/source_id to issues if not present (needed for workflow auto-create)
ALTER TABLE issues ADD COLUMN IF NOT EXISTS source_type VARCHAR(100);
ALTER TABLE issues ADD COLUMN IF NOT EXISTS source_id   INTEGER;
ALTER TABLE issues ADD COLUMN IF NOT EXISTS assigned_to INTEGER REFERENCES users(id);
