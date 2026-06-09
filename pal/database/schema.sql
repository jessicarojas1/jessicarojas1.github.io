-- ============================================================================
-- PAL Platform — Process, Approval & Library
-- Complete, idempotent PostgreSQL schema.
--
-- This file is a MANUAL-SETUP REFERENCE. The authoritative installer is
-- install.php (run automatically on container start by scripts/startup.sh).
-- It is safe to run repeatedly against a fresh OR existing database: every
-- object uses IF NOT EXISTS / ON CONFLICT DO NOTHING.
--
-- Keep this file in sync with database/migrations/* — it must always represent
-- the full, combined schema across all migrations.
-- ============================================================================

CREATE SCHEMA IF NOT EXISTS pal;
SET search_path TO pal, public;

-- ============================================================================
-- IDENTITY & ACCESS
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
    id                     SERIAL PRIMARY KEY,
    name                   VARCHAR(255) NOT NULL,
    email                  VARCHAR(255) UNIQUE NOT NULL,
    password_hash          TEXT NOT NULL,
    role                   VARCHAR(40) NOT NULL DEFAULT 'viewer',
    department             VARCHAR(120),
    title                  VARCHAR(120),
    avatar_color           VARCHAR(9) DEFAULT '#2563eb',
    is_active              BOOLEAN NOT NULL DEFAULT TRUE,
    mfa_secret             VARCHAR(64),
    mfa_enabled            BOOLEAN NOT NULL DEFAULT FALSE,
    force_password_change  BOOLEAN NOT NULL DEFAULT FALSE,
    password_changed_at    TIMESTAMP,
    sessions_revoked_at    TIMESTAMP,
    last_login             TIMESTAMP,
    created_at             TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at             TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS user_permissions (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    module      VARCHAR(40) NOT NULL,
    permission  VARCHAR(40) NOT NULL,
    granted_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, module, permission)
);
CREATE INDEX IF NOT EXISTS idx_user_permissions_user ON user_permissions(user_id);

CREATE TABLE IF NOT EXISTS settings (
    key         VARCHAR(80) PRIMARY KEY,
    value       TEXT,
    type        VARCHAR(20) DEFAULT 'string',
    description TEXT,
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS rate_limits (
    key           VARCHAR(255) PRIMARY KEY,
    attempts      INTEGER NOT NULL DEFAULT 0,
    window_start  TIMESTAMP NOT NULL DEFAULT NOW(),
    blocked_until TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_keys (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    key_prefix  VARCHAR(20),
    key_hash    VARCHAR(128) NOT NULL,
    scopes      TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    expires_at  TIMESTAMP,
    last_used   TIMESTAMP,
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS active_sessions (
    id           VARCHAR(128) PRIMARY KEY,
    user_id      INTEGER REFERENCES users(id) ON DELETE CASCADE,
    ip_address   VARCHAR(64),
    user_agent   VARCHAR(500),
    last_seen_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Immutable, hash-chained audit log (Auth::log)
CREATE TABLE IF NOT EXISTS activity_log (
    id           BIGSERIAL PRIMARY KEY,
    user_id      INTEGER REFERENCES users(id),
    action       VARCHAR(80) NOT NULL,
    entity_type  VARCHAR(60),
    entity_id    INTEGER,
    changes      TEXT,
    ip_address   VARCHAR(64),
    user_agent   VARCHAR(500),
    log_hash     VARCHAR(64),
    created_at   TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_activity_log_entity ON activity_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_activity_log_user   ON activity_log(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_log_action ON activity_log(action);

-- In-app notifications / alerts
CREATE TABLE IF NOT EXISTS alerts (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title       VARCHAR(255) NOT NULL,
    body        TEXT,
    severity    VARCHAR(20) DEFAULT 'info',
    link        VARCHAR(500),
    is_read     BOOLEAN NOT NULL DEFAULT FALSE,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_alerts_user ON alerts(user_id, is_read);

-- ============================================================================
-- TAGS (shared taxonomy)
-- ============================================================================

CREATE TABLE IF NOT EXISTS tags (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(80) UNIQUE NOT NULL,
    color       VARCHAR(9) DEFAULT '#64748b',
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS entity_tags (
    id          SERIAL PRIMARY KEY,
    tag_id      INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    entity_type VARCHAR(40) NOT NULL,
    entity_id   INTEGER NOT NULL,
    UNIQUE (tag_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_entity_tags_entity ON entity_tags(entity_type, entity_id);

-- ============================================================================
-- SPACES / KNOWLEDGE AREAS
-- ============================================================================

CREATE TABLE IF NOT EXISTS spaces (
    id          SERIAL PRIMARY KEY,
    space_key   VARCHAR(20) UNIQUE NOT NULL,
    name        VARCHAR(160) NOT NULL,
    description TEXT,
    type        VARCHAR(30) NOT NULL DEFAULT 'team',   -- department/team/program/project/compliance/process/admin
    icon        VARCHAR(40) DEFAULT 'bi-folder2-open',
    color       VARCHAR(9) DEFAULT '#2563eb',
    owner_id    INTEGER REFERENCES users(id),
    is_private  BOOLEAN NOT NULL DEFAULT FALSE,
    is_archived BOOLEAN NOT NULL DEFAULT FALSE,
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_spaces_type ON spaces(type);

CREATE TABLE IF NOT EXISTS space_members (
    id        SERIAL PRIMARY KEY,
    space_id  INTEGER NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role      VARCHAR(20) NOT NULL DEFAULT 'viewer',  -- owner/reviewer/approver/contributor/viewer
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (space_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_space_members_user ON space_members(user_id);

-- ============================================================================
-- PAGES (rich wiki content)
-- ============================================================================

CREATE TABLE IF NOT EXISTS pages (
    id              SERIAL PRIMARY KEY,
    space_id        INTEGER NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    parent_id       INTEGER REFERENCES pages(id) ON DELETE CASCADE,
    title           VARCHAR(255) NOT NULL,
    slug            VARCHAR(255),
    body            TEXT,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',   -- draft/in_review/published
    owner_id        INTEGER REFERENCES users(id),
    current_version INTEGER NOT NULL DEFAULT 1,
    position        INTEGER NOT NULL DEFAULT 0,
    created_by      INTEGER REFERENCES users(id),
    published_at    TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pages_space  ON pages(space_id);
CREATE INDEX IF NOT EXISTS idx_pages_parent ON pages(parent_id);
CREATE INDEX IF NOT EXISTS idx_pages_status ON pages(status);

CREATE TABLE IF NOT EXISTS page_versions (
    id          SERIAL PRIMARY KEY,
    page_id     INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    version     INTEGER NOT NULL,
    title       VARCHAR(255) NOT NULL,
    body        TEXT,
    change_note VARCHAR(500),
    edited_by   INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_page_versions_page ON page_versions(page_id);

-- ============================================================================
-- DOCUMENTS (controlled document management)
-- ============================================================================

CREATE TABLE IF NOT EXISTS documents (
    id                 SERIAL PRIMARY KEY,
    document_code      VARCHAR(40) UNIQUE NOT NULL,
    title              VARCHAR(255) NOT NULL,
    doc_type           VARCHAR(40) NOT NULL DEFAULT 'policy',   -- policy/procedure/process/standard/guideline/work_instruction/plan/form/template/record/evidence/training
    space_id           INTEGER REFERENCES spaces(id) ON DELETE SET NULL,
    owner_id           INTEGER REFERENCES users(id),
    reviewer_id        INTEGER REFERENCES users(id),
    approver_id        INTEGER REFERENCES users(id),
    department         VARCHAR(120),
    business_unit      VARCHAR(120),
    classification     VARCHAR(40) DEFAULT 'internal',          -- public/internal/confidential/restricted
    revision           VARCHAR(20) NOT NULL DEFAULT '1.0',
    status             VARCHAR(20) NOT NULL DEFAULT 'draft',     -- draft/in_review/approved/published/rejected/archived/obsolete
    description        TEXT,
    body               TEXT,
    effective_date     DATE,
    review_date        DATE,
    expiration_date    DATE,
    file_stored_name   VARCHAR(255),
    file_original_name VARCHAR(255),
    file_mime          VARCHAR(120),
    file_size          INTEGER,
    file_hash          VARCHAR(64),
    checked_out_by     INTEGER REFERENCES users(id),
    checked_out_at     TIMESTAMP,
    requires_ack       BOOLEAN NOT NULL DEFAULT FALSE,
    created_by         INTEGER REFERENCES users(id),
    published_at       TIMESTAMP,
    created_at         TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_documents_status ON documents(status);
CREATE INDEX IF NOT EXISTS idx_documents_type   ON documents(doc_type);
CREATE INDEX IF NOT EXISTS idx_documents_space  ON documents(space_id);

CREATE TABLE IF NOT EXISTS document_versions (
    id                 SERIAL PRIMARY KEY,
    document_id        INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    revision           VARCHAR(20) NOT NULL,
    title              VARCHAR(255),
    body               TEXT,
    change_summary     VARCHAR(500),
    file_stored_name   VARCHAR(255),
    file_original_name VARCHAR(255),
    file_mime          VARCHAR(120),
    file_size          INTEGER,
    status             VARCHAR(20),
    created_by         INTEGER REFERENCES users(id),
    created_at         TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_document_versions_doc ON document_versions(document_id);

-- Read receipts / acknowledgements (controlled document protection)
CREATE TABLE IF NOT EXISTS document_acknowledgements (
    id              SERIAL PRIMARY KEY,
    document_id     INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    revision        VARCHAR(20),
    acknowledged_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (document_id, user_id, revision)
);

-- Relationships: process / risk / control / system links
CREATE TABLE IF NOT EXISTS entity_relations (
    id            SERIAL PRIMARY KEY,
    source_type   VARCHAR(40) NOT NULL,
    source_id     INTEGER NOT NULL,
    relation_type VARCHAR(40) NOT NULL,   -- related_process/related_risk/related_control/related_system/related_policy/related_procedure
    target_label  VARCHAR(255) NOT NULL,
    target_type   VARCHAR(40),
    target_id     INTEGER,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_entity_relations_src ON entity_relations(source_type, source_id);

-- ============================================================================
-- PROCESSES
-- ============================================================================

CREATE TABLE IF NOT EXISTS processes (
    id           SERIAL PRIMARY KEY,
    process_code VARCHAR(40) UNIQUE NOT NULL,
    name         VARCHAR(255) NOT NULL,
    description  TEXT,
    space_id     INTEGER REFERENCES spaces(id) ON DELETE SET NULL,
    owner_id     INTEGER REFERENCES users(id),
    department   VARCHAR(120),
    status       VARCHAR(20) NOT NULL DEFAULT 'draft',   -- draft/in_review/published/retired
    version      VARCHAR(20) NOT NULL DEFAULT '1.0',
    diagram      TEXT,
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_processes_status ON processes(status);

-- ============================================================================
-- WORKFLOW ENGINE & APPROVALS
-- ============================================================================

CREATE TABLE IF NOT EXISTS workflow_templates (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(160) NOT NULL,
    description   TEXT,
    workflow_type VARCHAR(40) NOT NULL DEFAULT 'general',  -- policy/procedure/process/change/record/evidence/corrective/general
    approval_mode VARCHAR(20) NOT NULL DEFAULT 'sequential', -- single/sequential/parallel/consensus
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_by    INTEGER REFERENCES users(id),
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS workflow_steps (
    id               SERIAL PRIMARY KEY,
    template_id      INTEGER NOT NULL REFERENCES workflow_templates(id) ON DELETE CASCADE,
    step_number      INTEGER NOT NULL,
    name             VARCHAR(160) NOT NULL,
    approver_role    VARCHAR(40),
    approver_user_id INTEGER REFERENCES users(id),
    sla_hours        INTEGER DEFAULT 72,
    UNIQUE (template_id, step_number)
);

CREATE TABLE IF NOT EXISTS approval_requests (
    id            SERIAL PRIMARY KEY,
    title         VARCHAR(255) NOT NULL,
    entity_type   VARCHAR(40),
    entity_id     INTEGER,
    template_id   INTEGER REFERENCES workflow_templates(id),
    approval_mode VARCHAR(20) NOT NULL DEFAULT 'sequential',
    status        VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending/approved/rejected/returned/cancelled
    current_step  INTEGER NOT NULL DEFAULT 1,
    requested_by  INTEGER REFERENCES users(id),
    due_at        TIMESTAMP,
    decided_at    TIMESTAMP,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_approval_requests_status ON approval_requests(status);
CREATE INDEX IF NOT EXISTS idx_approval_requests_entity ON approval_requests(entity_type, entity_id);

CREATE TABLE IF NOT EXISTS approval_request_steps (
    id               SERIAL PRIMARY KEY,
    request_id       INTEGER NOT NULL REFERENCES approval_requests(id) ON DELETE CASCADE,
    step_number      INTEGER NOT NULL,
    name             VARCHAR(160),
    required_role    VARCHAR(40),
    required_user_id INTEGER REFERENCES users(id),
    status           VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending/approved/rejected/skipped
    decided_by       INTEGER REFERENCES users(id),
    decision_comment TEXT,
    decided_at       TIMESTAMP,
    due_at           TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (request_id, step_number)
);

CREATE TABLE IF NOT EXISTS approval_history (
    id          SERIAL PRIMARY KEY,
    request_id  INTEGER NOT NULL REFERENCES approval_requests(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id),
    action      VARCHAR(40) NOT NULL,   -- submitted/approved/rejected/returned/escalated/cancelled/commented
    comment     TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_approval_history_req ON approval_history(request_id);

-- ============================================================================
-- TASKS & ACTION ITEMS
-- ============================================================================

CREATE TABLE IF NOT EXISTS tasks (
    id           SERIAL PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    type         VARCHAR(30) NOT NULL DEFAULT 'task',   -- task/review/approval/corrective_action
    status       VARCHAR(20) NOT NULL DEFAULT 'open',   -- open/in_progress/blocked/done/cancelled
    priority     VARCHAR(20) NOT NULL DEFAULT 'medium', -- low/medium/high/urgent
    assigned_to  INTEGER REFERENCES users(id),
    created_by   INTEGER REFERENCES users(id),
    due_date     DATE,
    entity_type  VARCHAR(40),
    entity_id    INTEGER,
    completed_at TIMESTAMP,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_tasks_assigned ON tasks(assigned_to, status);
CREATE INDEX IF NOT EXISTS idx_tasks_status   ON tasks(status);

-- ============================================================================
-- TEMPLATE LIBRARY
-- ============================================================================

CREATE TABLE IF NOT EXISTS templates (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(160) NOT NULL,
    description TEXT,
    category    VARCHAR(40) DEFAULT 'document',  -- document/page/process/meeting/project/risk/audit
    doc_type    VARCHAR(40),
    body        TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ============================================================================
-- COLLABORATION — comments, watches, favorites, attachments, saved searches
-- ============================================================================

CREATE TABLE IF NOT EXISTS comments (
    id          SERIAL PRIMARY KEY,
    entity_type VARCHAR(40) NOT NULL,   -- page/document/process/task/approval
    entity_id   INTEGER NOT NULL,
    user_id     INTEGER REFERENCES users(id),
    parent_id   INTEGER REFERENCES comments(id) ON DELETE CASCADE,
    body        TEXT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_comments_entity ON comments(entity_type, entity_id);

CREATE TABLE IF NOT EXISTS watches (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    entity_type VARCHAR(40) NOT NULL,   -- space/page/document/process
    entity_id   INTEGER NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_watches_user ON watches(user_id);

CREATE TABLE IF NOT EXISTS favorites (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    entity_type VARCHAR(40) NOT NULL,
    entity_id   INTEGER NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_favorites_user ON favorites(user_id);

CREATE TABLE IF NOT EXISTS attachments (
    id            SERIAL PRIMARY KEY,
    entity_type   VARCHAR(40) NOT NULL,
    entity_id     INTEGER NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(120),
    file_size     INTEGER,
    file_hash     VARCHAR(64),
    description   TEXT,
    uploaded_by   INTEGER REFERENCES users(id),
    created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_attachments_entity ON attachments(entity_type, entity_id);

CREATE TABLE IF NOT EXISTS saved_searches (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name       VARCHAR(160) NOT NULL,
    query      VARCHAR(500),
    filters    TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ============================================================================
-- DEFAULT SETTINGS (seed)
-- ============================================================================

INSERT INTO settings (key, value, type, description) VALUES
    ('org_name',             'PAL Platform',        'string',  'Organization / product display name'),
    ('company_logo_data',    '',                    'string',  'Logo source — data: URI or http(s):// URL'),
    ('company_logo_name',    '',                    'string',  'Logo original filename / label'),
    ('brand_accent',         '#2563eb',             'string',  'Primary accent colour (#RRGGBB)'),
    ('date_format',          'Y-m-d',               'string',  'Date display format'),
    ('timezone',             'UTC',                 'string',  'Application timezone'),
    ('version',              '1.0.0',               'string',  'PAL platform version'),
    ('upload_max_size_mb',   '25',                  'integer', 'Maximum file upload size in MB'),
    ('upload_allowed_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,gif,svg,txt,csv,md,zip', 'string', 'Allowed upload file extensions'),
    ('password_min_length',  '12',                  'integer', 'Minimum password length'),
    ('password_require_uppercase', '1',             'boolean', 'Require an uppercase letter'),
    ('password_require_numbers',   '1',             'boolean', 'Require a number'),
    ('password_require_special',   '1',             'boolean', 'Require a special character'),
    ('smtp_host',            '',                    'string',  'SMTP server hostname'),
    ('smtp_port',            '587',                 'integer', 'SMTP server port'),
    ('smtp_user',            '',                    'string',  'SMTP username'),
    ('smtp_pass',            '',                    'string',  'SMTP password'),
    ('smtp_from',            '',                    'string',  'Default from address'),
    ('smtp_from_name',       'PAL Platform',        'string',  'Default from name'),
    ('email_notifications',  '0',                   'boolean', 'Enable outbound email notifications')
ON CONFLICT (key) DO NOTHING;
