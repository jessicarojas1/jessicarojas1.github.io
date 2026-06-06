-- ═══════════════════════════════════════════════════════════════════════════
-- AEGIS GRC — Complete Database Schema
-- ═══════════════════════════════════════════════════════════════════════════
--
-- PURPOSE: Manual reference / fresh-database setup.
-- AUTHORITATIVE DEPLOYMENT: Use install.php (php install.php) which runs
--   this file then applies all migrations in database/migrations/.
--
-- IDEMPOTENT: All statements use IF NOT EXISTS / IF EXISTS / ON CONFLICT DO
--   NOTHING so this script is safe to run multiple times on the same database.
--
-- COVERAGE: Equivalent to schema.sql baseline (through migration 007) PLUS
--   migrations 008 through 020, and inline table definitions from install.php.
--
-- Last updated: 2026-06-06 (covers migrations 001–020)
-- ═══════════════════════════════════════════════════════════════════════════

CREATE SCHEMA IF NOT EXISTS aegis;
SET search_path TO aegis;

-- ── Core / Auth ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    id                 SERIAL PRIMARY KEY,
    name               VARCHAR(255) NOT NULL,
    email              VARCHAR(255) UNIQUE NOT NULL,
    password_hash      VARCHAR(255) NOT NULL,
    role               VARCHAR(50)  NOT NULL DEFAULT 'viewer',
    department         VARCHAR(255),
    job_title          VARCHAR(255),
    is_active          BOOLEAN      NOT NULL DEFAULT TRUE,
    last_login         TIMESTAMP,
    email_verified_at  TIMESTAMP,
    -- SSO fields (migration 001)
    sso_provider       VARCHAR(100),
    sso_subject        VARCHAR(500),
    sso_only           BOOLEAN      NOT NULL DEFAULT FALSE,
    -- MFA fields (migration 001 + install.php)
    mfa_secret         VARCHAR(255),
    mfa_enabled        BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_users_sso ON users(sso_provider, sso_subject)
    WHERE sso_provider IS NOT NULL AND sso_subject IS NOT NULL;

CREATE TABLE IF NOT EXISTS api_keys (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER REFERENCES users(id) ON DELETE CASCADE,
    name         VARCHAR(255) NOT NULL,
    key_prefix   VARCHAR(20)  NOT NULL,
    key_hash     VARCHAR(255) NOT NULL,
    permissions  JSONB        NOT NULL DEFAULT '["read"]',
    last_used    TIMESTAMP,
    expires_at   TIMESTAMP,
    is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_permissions (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    module       VARCHAR(100) NOT NULL,
    permission   VARCHAR(50)  NOT NULL,
    granted_by   INTEGER REFERENCES users(id),
    granted_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, module, permission)
);
CREATE INDEX IF NOT EXISTS idx_up_user ON user_permissions(user_id);

CREATE TABLE IF NOT EXISTS user_notification_prefs (
    id                SERIAL PRIMARY KEY,
    user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notification_type VARCHAR(100) NOT NULL,
    enabled           BOOLEAN      NOT NULL DEFAULT TRUE,
    digest_mode       VARCHAR(50)  NOT NULL DEFAULT 'immediate'
                          CHECK (digest_mode IN ('immediate','daily','weekly')),
    digest_time       TIME DEFAULT '08:00',
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, notification_type)
);
CREATE INDEX IF NOT EXISTS idx_unp_user ON user_notification_prefs(user_id);

CREATE TABLE IF NOT EXISTS active_sessions (
    id           VARCHAR(255) PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    ip_address   VARCHAR(45),
    user_agent   TEXT,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_active_sessions_user      ON active_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_active_sessions_last_seen ON active_sessions(last_seen_at);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP   NOT NULL,
    used       BOOLEAN     NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token_hash);

CREATE TABLE IF NOT EXISTS mfa_backup_codes (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash  VARCHAR(255) NOT NULL,
    used_at    TIMESTAMP,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mbc_user ON mfa_backup_codes(user_id);

CREATE TABLE IF NOT EXISTS rate_limits (
    key           VARCHAR(255) PRIMARY KEY,
    attempts      INTEGER      NOT NULL DEFAULT 0,
    window_start  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP
);

-- ── Activity / Audit Log ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS activity_log (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER REFERENCES users(id),
    action      VARCHAR(255) NOT NULL,
    entity_type VARCHAR(100),
    entity_id   INTEGER,
    changes     JSONB,
    ip_address  VARCHAR(50),
    -- migration 001 additions
    log_hash    VARCHAR(64),
    user_agent  VARCHAR(500),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_al_user    ON activity_log(user_id);
CREATE INDEX IF NOT EXISTS idx_al_entity  ON activity_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_al_created ON activity_log(created_at);

-- ── Settings ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS settings (
    key        VARCHAR(255) PRIMARY KEY,
    value      TEXT,
    type       VARCHAR(50) DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Alerts / Notifications ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS alerts (
    id           SERIAL PRIMARY KEY,
    type         VARCHAR(100) NOT NULL,
    title        VARCHAR(500) NOT NULL,
    message      TEXT,
    severity     VARCHAR(50)  NOT NULL DEFAULT 'info',
    user_id      INTEGER REFERENCES users(id),
    related_type VARCHAR(100),
    related_id   INTEGER,
    is_read      BOOLEAN      NOT NULL DEFAULT FALSE,
    read_at      TIMESTAMP,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_alerts_user ON alerts(user_id, is_read);

CREATE TABLE IF NOT EXISTS alert_configs (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(255) NOT NULL,
    type           VARCHAR(100) NOT NULL,
    trigger_config JSONB        NOT NULL DEFAULT '{}',
    recipients     JSONB        NOT NULL DEFAULT '[]',
    channels       JSONB        NOT NULL DEFAULT '["in_app"]',
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notification_log (
    id                SERIAL PRIMARY KEY,
    notification_type VARCHAR(100) NOT NULL,
    entity_id         INTEGER,
    recipient_email   VARCHAR(255),
    sent_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_nl_sent_at   ON notification_log(sent_at);
CREATE INDEX IF NOT EXISTS idx_nl_type      ON notification_log(notification_type, entity_id, sent_at);
CREATE INDEX IF NOT EXISTS idx_nl_recipient ON notification_log(recipient_email);

-- ── Email ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS email_templates (
    id         SERIAL PRIMARY KEY,
    type       VARCHAR(100) NOT NULL UNIQUE,
    name       VARCHAR(255) NOT NULL,
    subject    VARCHAR(500) NOT NULL,
    body_html  TEXT         NOT NULL,
    body_text  TEXT,
    variables  JSONB        NOT NULL DEFAULT '[]',
    is_active  BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP   NOT NULL,
    used_at    TIMESTAMP,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_evt_user ON email_verification_tokens(user_id);

CREATE TABLE IF NOT EXISTS email_bounces (
    id          SERIAL PRIMARY KEY,
    email       VARCHAR(255) NOT NULL,
    bounce_type VARCHAR(50)  NOT NULL DEFAULT 'hard'
                CHECK (bounce_type IN ('hard','soft','complaint')),
    reason      TEXT,
    recorded_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_eb_email ON email_bounces(email);
CREATE UNIQUE INDEX IF NOT EXISTS idx_eb_email_hard ON email_bounces(email)
    WHERE bounce_type = 'hard';

CREATE TABLE IF NOT EXISTS email_unsubscribes (
    id                SERIAL PRIMARY KEY,
    user_id           INTEGER REFERENCES users(id) ON DELETE SET NULL,
    email             VARCHAR(255) NOT NULL,
    token             VARCHAR(64)  NOT NULL UNIQUE,
    notification_type VARCHAR(100),
    unsubscribed_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_eu_email ON email_unsubscribes(email);
CREATE INDEX IF NOT EXISTS idx_eu_token ON email_unsubscribes(token);

-- ── Workflows ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS workflows (
    id               SERIAL PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    description      TEXT,
    trigger_type     VARCHAR(100) NOT NULL,
    trigger_config   JSONB        NOT NULL DEFAULT '{}',
    actions          JSONB        NOT NULL DEFAULT '[]',
    is_active        BOOLEAN      NOT NULL DEFAULT TRUE,
    -- migration 001 additions
    last_triggered_at TIMESTAMP,
    cooldown_seconds  INTEGER     NOT NULL DEFAULT 3600,
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS workflow_executions (
    id            SERIAL PRIMARY KEY,
    workflow_id   INTEGER NOT NULL REFERENCES workflows(id) ON DELETE CASCADE,
    triggered_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    trigger_data  JSONB,
    actions_taken JSONB,
    status        VARCHAR(50) NOT NULL DEFAULT 'success',
    error_message TEXT
);
CREATE INDEX IF NOT EXISTS idx_we_workflow ON workflow_executions(workflow_id, triggered_at DESC);

-- ── Tags ──────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS tags (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(50)  NOT NULL UNIQUE,
    color      VARCHAR(7)   NOT NULL DEFAULT '#6366f1',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS entity_tags (
    id          SERIAL PRIMARY KEY,
    tag_id      INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    entity_type VARCHAR(30) NOT NULL,
    entity_id   INTEGER     NOT NULL,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_entity_tag UNIQUE (tag_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_entity_tags_entity ON entity_tags(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_entity_tags_tag    ON entity_tags(tag_id);

-- ── Custom Fields ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS custom_field_definitions (
    id          SERIAL PRIMARY KEY,
    entity_type VARCHAR(100) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    label       VARCHAR(255) NOT NULL,
    field_type  VARCHAR(50)  NOT NULL DEFAULT 'text',
    options     JSONB,
    is_required BOOLEAN      NOT NULL DEFAULT FALSE,
    sort_order  INTEGER      NOT NULL DEFAULT 0,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(entity_type, name)
);

CREATE TABLE IF NOT EXISTS custom_field_values (
    id            SERIAL PRIMARY KEY,
    definition_id INTEGER NOT NULL REFERENCES custom_field_definitions(id) ON DELETE CASCADE,
    entity_type   VARCHAR(100) NOT NULL,
    entity_id     INTEGER      NOT NULL,
    value_text    TEXT,
    value_number  NUMERIC,
    value_date    DATE,
    value_json    JSONB,
    updated_by    INTEGER REFERENCES users(id),
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(definition_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_cfv_entity ON custom_field_values(entity_type, entity_id);

-- ── Metrics ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS metrics_snapshots (
    id                 SERIAL PRIMARY KEY,
    snapshot_date      DATE        NOT NULL DEFAULT CURRENT_DATE,
    compliance_pct     NUMERIC(5,2),
    risk_health        NUMERIC(5,2),
    policy_health      NUMERIC(5,2),
    audit_health       NUMERIC(5,2),
    grc_score          NUMERIC(5,2),
    open_risks         INTEGER,
    critical_risks     INTEGER,
    open_incidents     INTEGER,
    critical_incidents INTEGER,
    open_issues        INTEGER,
    overdue_reviews    INTEGER,
    vendor_count       INTEGER,
    active_audits      INTEGER,
    details            JSONB,
    created_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(snapshot_date)
);
CREATE INDEX IF NOT EXISTS idx_ms_date ON metrics_snapshots(snapshot_date DESC);

-- ── Data Retention ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS data_retention_policies (
    id             SERIAL PRIMARY KEY,
    entity_type    VARCHAR(100) NOT NULL UNIQUE,
    retention_days INTEGER      NOT NULL DEFAULT 365,
    action         VARCHAR(30)  NOT NULL DEFAULT 'delete',
    is_enabled     BOOLEAN      NOT NULL DEFAULT FALSE,
    last_run_at    TIMESTAMP,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO data_retention_policies (entity_type, retention_days, action, is_enabled) VALUES
    ('activity_log',       365, 'delete', FALSE),
    ('notification_log',    90, 'delete', FALSE),
    ('webhook_deliveries', 180, 'delete', FALSE),
    ('alerts',              90, 'delete', FALSE)
ON CONFLICT (entity_type) DO NOTHING;

-- ── Approvals ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS approval_templates (
    id                SERIAL PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    description       TEXT,
    entity_type       VARCHAR(100) NOT NULL,
    trigger_condition JSONB        NOT NULL DEFAULT '{}',
    is_active         BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by        INTEGER REFERENCES users(id),
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS approval_template_steps (
    id               SERIAL PRIMARY KEY,
    template_id      INTEGER NOT NULL REFERENCES approval_templates(id) ON DELETE CASCADE,
    step_number      INTEGER NOT NULL,
    label            VARCHAR(255) NOT NULL,
    required_role    VARCHAR(50),
    required_user_id INTEGER REFERENCES users(id),
    allow_delegation BOOLEAN NOT NULL DEFAULT TRUE,
    due_hours        INTEGER NOT NULL DEFAULT 48,
    UNIQUE (template_id, step_number)
);

CREATE TABLE IF NOT EXISTS approval_requests (
    id           SERIAL PRIMARY KEY,
    template_id  INTEGER NOT NULL REFERENCES approval_templates(id),
    entity_type  VARCHAR(100) NOT NULL,
    entity_id    INTEGER      NOT NULL,
    requested_by INTEGER NOT NULL REFERENCES users(id),
    current_step INTEGER      NOT NULL DEFAULT 1,
    status       VARCHAR(50)  NOT NULL DEFAULT 'pending',
    completed_at TIMESTAMP,
    context_data JSONB,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ar_entity ON approval_requests(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_ar_status ON approval_requests(status);

CREATE TABLE IF NOT EXISTS approval_request_steps (
    id               SERIAL PRIMARY KEY,
    request_id       INTEGER NOT NULL REFERENCES approval_requests(id) ON DELETE CASCADE,
    step_number      INTEGER NOT NULL,
    label            VARCHAR(255) NOT NULL,
    required_role    VARCHAR(50),
    required_user_id INTEGER REFERENCES users(id),
    actioned_by      INTEGER REFERENCES users(id),
    decision         VARCHAR(50),
    notes            TEXT,
    due_at           TIMESTAMP,
    actioned_at      TIMESTAMP,
    UNIQUE (request_id, step_number)
);
CREATE INDEX IF NOT EXISTS idx_ars_request ON approval_request_steps(request_id);

-- ── Webhooks ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(255) NOT NULL,
    url            TEXT         NOT NULL,
    secret         VARCHAR(255),
    event_types    JSONB        NOT NULL DEFAULT '[]',
    provider       VARCHAR(50)  NOT NULL DEFAULT 'generic',
    custom_headers JSONB        NOT NULL DEFAULT '{}',
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by     INTEGER REFERENCES users(id),
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            SERIAL PRIMARY KEY,
    endpoint_id   INTEGER  NOT NULL REFERENCES webhook_endpoints(id) ON DELETE CASCADE,
    event_type    VARCHAR(100) NOT NULL,
    payload       JSONB        NOT NULL,
    status        VARCHAR(50)  NOT NULL DEFAULT 'pending',
    attempts      SMALLINT     NOT NULL DEFAULT 0,
    response_code SMALLINT,
    response_body TEXT,
    next_retry_at TIMESTAMP,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at  TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wd_status_retry ON webhook_deliveries(status, next_retry_at);
CREATE INDEX IF NOT EXISTS idx_wd_endpoint     ON webhook_deliveries(endpoint_id);

-- ── Questionnaires ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS questionnaires (
    id          SERIAL PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    entity_type VARCHAR(100) NOT NULL DEFAULT 'general',
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS questionnaire_questions (
    id               SERIAL PRIMARY KEY,
    questionnaire_id INTEGER NOT NULL REFERENCES questionnaires(id) ON DELETE CASCADE,
    section          VARCHAR(255) NOT NULL DEFAULT 'General',
    question_text    TEXT         NOT NULL,
    question_type    VARCHAR(50)  NOT NULL DEFAULT 'text',
    options          JSONB,
    weight           SMALLINT     NOT NULL DEFAULT 1,
    is_required      BOOLEAN      NOT NULL DEFAULT TRUE,
    sort_order       INTEGER      NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_qq_questionnaire ON questionnaire_questions(questionnaire_id);

CREATE TABLE IF NOT EXISTS questionnaire_assignments (
    id               SERIAL PRIMARY KEY,
    questionnaire_id INTEGER NOT NULL REFERENCES questionnaires(id),
    entity_type      VARCHAR(100),
    entity_id        INTEGER,
    assigned_to      INTEGER REFERENCES users(id),
    due_date         DATE,
    status           VARCHAR(50) NOT NULL DEFAULT 'pending',
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS questionnaire_responses (
    id             SERIAL PRIMARY KEY,
    assignment_id  INTEGER NOT NULL REFERENCES questionnaire_assignments(id) ON DELETE CASCADE,
    submitted_by   INTEGER REFERENCES users(id),
    submitted_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_score    NUMERIC(6,2),
    max_score      NUMERIC(6,2),
    reviewer_notes TEXT
);

CREATE TABLE IF NOT EXISTS questionnaire_answers (
    id          SERIAL PRIMARY KEY,
    response_id INTEGER NOT NULL REFERENCES questionnaire_responses(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL REFERENCES questionnaire_questions(id),
    answer_text TEXT,
    score       NUMERIC(6,2),
    UNIQUE(response_id, question_id)
);

-- ── Documents ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS documents (
    id               SERIAL PRIMARY KEY,
    title            VARCHAR(500) NOT NULL,
    doc_number       VARCHAR(100),
    description      TEXT,
    category         VARCHAR(100),
    classification   VARCHAR(50)  NOT NULL DEFAULT 'internal',
    status           VARCHAR(50)  NOT NULL DEFAULT 'draft',
    current_version  VARCHAR(50)  NOT NULL DEFAULT '1.0',
    owner_id         INTEGER REFERENCES users(id),
    approver_id      INTEGER REFERENCES users(id),
    review_frequency VARCHAR(50) DEFAULT 'annual',
    next_review_date DATE,
    expiry_date      DATE,
    approved_at      TIMESTAMP,
    published_at     TIMESTAMP,
    tags             JSONB DEFAULT '[]',
    dlp_metadata     JSONB DEFAULT '{}',
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_docs_owner  ON documents(owner_id);
CREATE INDEX IF NOT EXISTS idx_docs_status ON documents(status);
CREATE INDEX IF NOT EXISTS idx_docs_expiry ON documents(expiry_date);

CREATE TABLE IF NOT EXISTS document_versions (
    id             SERIAL PRIMARY KEY,
    document_id    INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    version        VARCHAR(50) NOT NULL,
    file_name      VARCHAR(500),
    stored_name    VARCHAR(500),
    mime_type      VARCHAR(200),
    file_size      INTEGER,
    file_hash      VARCHAR(64),
    change_summary TEXT,
    uploaded_by    INTEGER REFERENCES users(id),
    uploaded_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_dv_document ON document_versions(document_id);

-- ── Report Schedules ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS report_schedules (
    id           SERIAL PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    report_type  VARCHAR(100) NOT NULL,
    frequency    VARCHAR(50)  NOT NULL DEFAULT 'weekly'
                 CHECK (frequency IN ('daily','weekly','monthly','quarterly')),
    day_of_week  INTEGER DEFAULT 1,
    day_of_month INTEGER DEFAULT 1,
    send_time    TIME         NOT NULL DEFAULT '08:00',
    recipients   JSONB        NOT NULL DEFAULT '[]',
    filters      JSONB        NOT NULL DEFAULT '{}',
    format       VARCHAR(10)  NOT NULL DEFAULT 'html'
                 CHECK (format IN ('html','csv','both')),
    is_active    BOOLEAN      NOT NULL DEFAULT TRUE,
    last_sent_at TIMESTAMP,
    next_send_at TIMESTAMP,
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS report_schedule_logs (
    id          SERIAL PRIMARY KEY,
    schedule_id INTEGER NOT NULL REFERENCES report_schedules(id) ON DELETE CASCADE,
    sent_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recipients  JSONB,
    status      VARCHAR(50) NOT NULL DEFAULT 'sent',
    error       TEXT
);

-- ── Compliance / Standards ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS standards (
    id          SERIAL PRIMARY KEY,
    code        VARCHAR(100) UNIQUE NOT NULL,
    name        VARCHAR(255) NOT NULL,
    version     VARCHAR(50),
    description TEXT,
    category    VARCHAR(100),
    authority   VARCHAR(255),
    url         VARCHAR(500),
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- Drop legacy column if it was ever added (migration 011)
ALTER TABLE standards DROP COLUMN IF EXISTS is_builtin;

CREATE TABLE IF NOT EXISTS compliance_packages (
    id               SERIAL PRIMARY KEY,
    standard_id      INTEGER REFERENCES standards(id) ON DELETE CASCADE,
    name             VARCHAR(255) NOT NULL,
    version          VARCHAR(50),
    description      TEXT,
    price            DECIMAL(10,2),
    objectives_count INTEGER DEFAULT 0,
    is_active        BOOLEAN      NOT NULL DEFAULT TRUE,
    imported_by      INTEGER REFERENCES users(id),
    imported_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- Drop legacy columns if they were ever added (migration 011)
ALTER TABLE compliance_packages DROP COLUMN IF EXISTS is_builtin;
ALTER TABLE compliance_packages DROP COLUMN IF EXISTS is_paid;

CREATE TABLE IF NOT EXISTS compliance_objectives (
    id          SERIAL PRIMARY KEY,
    package_id  INTEGER REFERENCES compliance_packages(id) ON DELETE CASCADE,
    parent_id   INTEGER REFERENCES compliance_objectives(id),
    code        VARCHAR(100) NOT NULL,
    title       TEXT         NOT NULL,
    description TEXT,
    category    VARCHAR(255),
    level       INTEGER      NOT NULL DEFAULT 1,
    weight      DECIMAL(5,2) DEFAULT 1.0,
    sort_order  INTEGER DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_co_package ON compliance_objectives(package_id);
CREATE INDEX IF NOT EXISTS idx_co_parent  ON compliance_objectives(parent_id);

CREATE TABLE IF NOT EXISTS control_implementations (
    id                   SERIAL PRIMARY KEY,
    objective_id         INTEGER REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    status               VARCHAR(50) NOT NULL DEFAULT 'not_started',
    implementation_notes TEXT,
    evidence             TEXT,
    assigned_to          INTEGER REFERENCES users(id),
    due_date             DATE,
    last_reviewed        TIMESTAMP,
    reviewed_by          INTEGER REFERENCES users(id),
    created_at           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(objective_id)
);
CREATE INDEX IF NOT EXISTS idx_ci_objective ON control_implementations(objective_id);
CREATE INDEX IF NOT EXISTS idx_ci_status    ON control_implementations(status);

CREATE TABLE IF NOT EXISTS control_mappings (
    id            SERIAL PRIMARY KEY,
    source_obj_id INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    target_obj_id INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    mapping_type  VARCHAR(50) NOT NULL DEFAULT 'equivalent',
    notes         TEXT,
    created_by    INTEGER REFERENCES users(id),
    created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(source_obj_id, target_obj_id)
);
CREATE INDEX IF NOT EXISTS idx_cm_source ON control_mappings(source_obj_id);
CREATE INDEX IF NOT EXISTS idx_cm_target ON control_mappings(target_obj_id);

CREATE TABLE IF NOT EXISTS control_tests (
    id             SERIAL PRIMARY KEY,
    objective_id   INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    package_id     INTEGER NOT NULL REFERENCES compliance_packages(id) ON DELETE CASCADE,
    test_date      DATE    NOT NULL DEFAULT CURRENT_DATE,
    tester_id      INTEGER REFERENCES users(id),
    result         VARCHAR(20) NOT NULL CHECK (result IN ('pass','fail','partial','not_tested')),
    effectiveness  INTEGER CHECK (effectiveness BETWEEN 0 AND 100),
    method         VARCHAR(50),
    findings       TEXT,
    evidence_refs  TEXT,
    next_test_date DATE,
    created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ct_objective ON control_tests(objective_id);
CREATE INDEX IF NOT EXISTS idx_ct_package   ON control_tests(package_id);

-- ── Audits ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS audits (
    id             SERIAL PRIMARY KEY,
    -- migration 020: human-readable identifier
    audit_number   VARCHAR(20) UNIQUE,
    name           VARCHAR(255) NOT NULL,
    description    TEXT,
    package_id     INTEGER REFERENCES compliance_packages(id),
    audit_type     VARCHAR(100) NOT NULL DEFAULT 'internal',
    frequency      VARCHAR(50),
    status         VARCHAR(50)  NOT NULL DEFAULT 'planned',
    scheduled_date DATE,
    start_date     DATE,
    completed_date DATE,
    auditor_id     INTEGER REFERENCES users(id),
    created_by     INTEGER REFERENCES users(id),
    notes          TEXT,
    score          DECIMAL(5,2),
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_schedules (
    id               SERIAL PRIMARY KEY,
    package_id       INTEGER REFERENCES compliance_packages(id),
    frequency        VARCHAR(50) NOT NULL DEFAULT 'annual',
    last_audit_date  DATE,
    next_due_date    DATE,
    assigned_auditor INTEGER REFERENCES users(id),
    is_active        BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_items (
    id              SERIAL PRIMARY KEY,
    audit_id        INTEGER REFERENCES audits(id) ON DELETE CASCADE,
    objective_id    INTEGER REFERENCES compliance_objectives(id),
    status          VARCHAR(50) NOT NULL DEFAULT 'not_assessed',
    finding         TEXT,
    evidence        TEXT,
    notes           TEXT,
    risk_level      VARCHAR(50),
    remediation     TEXT,
    remediation_due DATE,
    created_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ai_audit ON audit_items(audit_id);

-- ── Policies ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS policies (
    id               SERIAL PRIMARY KEY,
    title            VARCHAR(500) NOT NULL,
    policy_number    VARCHAR(100),
    description      TEXT,
    content          TEXT,
    version          VARCHAR(50)  NOT NULL DEFAULT '1.0',
    status           VARCHAR(50)  NOT NULL DEFAULT 'draft',
    category         VARCHAR(255),
    owner_id         INTEGER REFERENCES users(id),
    approver_id      INTEGER REFERENCES users(id),
    review_frequency VARCHAR(50) DEFAULT 'annual',
    next_review_date DATE,
    approved_at      TIMESTAMP,
    published_at     TIMESTAMP,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policy_versions (
    id             SERIAL PRIMARY KEY,
    policy_id      INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    version        VARCHAR(50) NOT NULL,
    content        TEXT,
    change_summary TEXT,
    created_by     INTEGER REFERENCES users(id),
    created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policy_mappings (
    id           SERIAL PRIMARY KEY,
    policy_id    INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    objective_id INTEGER REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    notes        TEXT,
    created_at   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(policy_id, objective_id)
);
CREATE INDEX IF NOT EXISTS idx_pm_policy    ON policy_mappings(policy_id);
CREATE INDEX IF NOT EXISTS idx_pm_objective ON policy_mappings(objective_id);

CREATE TABLE IF NOT EXISTS policy_reviews (
    id             SERIAL PRIMARY KEY,
    policy_id      INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    reviewer_id    INTEGER REFERENCES users(id),
    scheduled_date DATE,
    completed_date DATE,
    status         VARCHAR(50) NOT NULL DEFAULT 'pending',
    outcome        VARCHAR(50),
    notes          TEXT,
    created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policy_attestations (
    id          SERIAL PRIMARY KEY,
    policy_id   INTEGER NOT NULL REFERENCES policies(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    attested_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address  VARCHAR(45),
    notes       TEXT,
    CONSTRAINT uq_policy_attestation UNIQUE (policy_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_pa_policy ON policy_attestations(policy_id);
CREATE INDEX IF NOT EXISTS idx_pa_user   ON policy_attestations(user_id);

CREATE TABLE IF NOT EXISTS policy_attestation_campaigns (
    id         SERIAL PRIMARY KEY,
    policy_id  INTEGER NOT NULL REFERENCES policies(id) ON DELETE CASCADE,
    title      VARCHAR(255) NOT NULL,
    due_date   DATE,
    is_active  BOOLEAN     NOT NULL DEFAULT TRUE,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Risk ──────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS risk_categories (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    description TEXT,
    color       VARCHAR(50) DEFAULT '#6366f1',
    sort_order  INTEGER DEFAULT 0,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risks (
    id                   SERIAL PRIMARY KEY,
    title                VARCHAR(500) NOT NULL,
    risk_id              VARCHAR(100),
    description          TEXT,
    category_id          INTEGER REFERENCES risk_categories(id),
    likelihood           INTEGER      NOT NULL DEFAULT 3 CHECK (likelihood BETWEEN 1 AND 5),
    impact               INTEGER      NOT NULL DEFAULT 3 CHECK (impact BETWEEN 1 AND 5),
    inherent_score       INTEGER      NOT NULL DEFAULT 0,
    residual_likelihood  INTEGER CHECK (residual_likelihood BETWEEN 1 AND 5),
    residual_impact      INTEGER CHECK (residual_impact BETWEEN 1 AND 5),
    residual_score       INTEGER      NOT NULL DEFAULT 0,
    status               VARCHAR(50)  NOT NULL DEFAULT 'open',
    treatment_type       VARCHAR(50),
    -- migration 004: multi-select treatment strategies
    treatment_strategies JSONB        NOT NULL DEFAULT '[]',
    treatment_description TEXT,
    owner_id             INTEGER REFERENCES users(id),
    review_date          DATE,
    identified_date      DATE         NOT NULL DEFAULT CURRENT_DATE,
    tags                 JSONB DEFAULT '[]',
    created_by           INTEGER REFERENCES users(id),
    -- migration 003: scanner source
    source               VARCHAR(100),
    source_external_id   VARCHAR(500),
    -- migration 005: enterprise columns
    velocity             INTEGER DEFAULT 3 CHECK (velocity BETWEEN 1 AND 5),
    proximity            VARCHAR(20) DEFAULT 'medium_term'
                         CHECK (proximity IN ('immediate','short_term','medium_term','long_term')),
    financial_min        DECIMAL(15,2),
    financial_likely     DECIMAL(15,2),
    financial_max        DECIMAL(15,2),
    financial_currency   VARCHAR(3) DEFAULT 'USD',
    parent_risk_id       INTEGER REFERENCES risks(id),
    assessment_status    VARCHAR(20)  NOT NULL DEFAULT 'draft'
                         CHECK (assessment_status IN ('draft','pending_review','approved')),
    reviewed_by          INTEGER REFERENCES users(id),
    reviewed_at          TIMESTAMP,
    review_notes         TEXT,
    risk_source          VARCHAR(50)
                         CHECK (risk_source IN ('strategic','operational','financial','compliance','technology',
                                                'reputational','external','people','project') OR risk_source IS NULL),
    confidence           VARCHAR(10) DEFAULT 'medium' CHECK (confidence IN ('low','medium','high')),
    target_likelihood    INTEGER CHECK (target_likelihood BETWEEN 1 AND 5),
    target_impact        INTEGER CHECK (target_impact BETWEEN 1 AND 5),
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_risks_status     ON risks(status);
CREATE INDEX IF NOT EXISTS idx_risks_owner      ON risks(owner_id);
CREATE INDEX IF NOT EXISTS idx_risks_parent     ON risks(parent_risk_id)
    WHERE parent_risk_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_risks_assessment ON risks(assessment_status);
CREATE INDEX IF NOT EXISTS idx_risks_source     ON risks(risk_source)
    WHERE risk_source IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_risks_source_ext ON risks(source_external_id)
    WHERE source_external_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS risk_score_history (
    id                   SERIAL PRIMARY KEY,
    risk_id              INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    likelihood           INTEGER NOT NULL,
    impact               INTEGER NOT NULL,
    score                INTEGER NOT NULL,
    residual_likelihood  INTEGER,
    residual_impact      INTEGER,
    residual_score       INTEGER,
    status               VARCHAR(50),
    treatment_strategies JSONB,
    changed_by           INTEGER REFERENCES users(id),
    note                 TEXT,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rsh_risk    ON risk_score_history(risk_id, created_at);
CREATE INDEX IF NOT EXISTS idx_rsh_created ON risk_score_history(created_at);

CREATE TABLE IF NOT EXISTS risk_control_links (
    id                        SERIAL PRIMARY KEY,
    risk_id                   INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    control_implementation_id INTEGER NOT NULL REFERENCES control_implementations(id) ON DELETE CASCADE,
    effectiveness             VARCHAR(20) NOT NULL DEFAULT 'partial'
                              CHECK (effectiveness IN ('none','partial','substantial','full')),
    notes                     TEXT,
    created_by                INTEGER REFERENCES users(id),
    created_at                TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(risk_id, control_implementation_id)
);
CREATE INDEX IF NOT EXISTS idx_rcl_risk    ON risk_control_links(risk_id);
CREATE INDEX IF NOT EXISTS idx_rcl_control ON risk_control_links(control_implementation_id);

CREATE TABLE IF NOT EXISTS risk_related_links (
    id         SERIAL PRIMARY KEY,
    risk_id    INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    related_id INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    link_type  VARCHAR(50) NOT NULL DEFAULT 'related'
               CHECK (link_type IN ('related','causes','caused_by','aggregates')),
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(risk_id, related_id)
);
CREATE INDEX IF NOT EXISTS idx_rrl_risk    ON risk_related_links(risk_id);
CREATE INDEX IF NOT EXISTS idx_rrl_related ON risk_related_links(related_id);

CREATE TABLE IF NOT EXISTS risk_treatments (
    id               SERIAL PRIMARY KEY,
    risk_id          INTEGER REFERENCES risks(id) ON DELETE CASCADE,
    treatment_type   VARCHAR(50)  NOT NULL,
    description      TEXT         NOT NULL,
    cost_estimate    DECIMAL(12,2),
    effort           VARCHAR(50),
    due_date         DATE,
    status           VARCHAR(50)  NOT NULL DEFAULT 'planned',
    owner_id         INTEGER REFERENCES users(id),
    completion_date  DATE,
    -- migration 004 addition
    completion_notes TEXT,
    notes            TEXT,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rt_status ON risk_treatments(status);

CREATE TABLE IF NOT EXISTS risk_matrix_config (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL DEFAULT 'Default',
    rows        INTEGER      NOT NULL DEFAULT 5,
    cols        INTEGER      NOT NULL DEFAULT 5,
    row_label   VARCHAR(50)  NOT NULL DEFAULT 'Likelihood',
    col_label   VARCHAR(50)  NOT NULL DEFAULT 'Impact',
    row_labels  JSONB        NOT NULL DEFAULT '["Never","Unexpected","Anticipated","Foreseeable","Expected"]',
    col_labels  JSONB        NOT NULL DEFAULT '["Acceptable","Tolerable","Unacceptable","Critical","Catastrophic"]',
    thresholds  JSONB        NOT NULL DEFAULT '{"low":4,"medium":9,"high":14,"critical":25}',
    colors      JSONB        NOT NULL DEFAULT '{"low":"#22c55e","medium":"#f59e0b","high":"#f97316","critical":"#ef4444"}',
    -- migration 010 additions
    cells       JSONB        NOT NULL DEFAULT '{}',
    description TEXT,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risk_appetite (
    id              SERIAL PRIMARY KEY,
    category        VARCHAR(100) NOT NULL,
    appetite        VARCHAR(20)  NOT NULL CHECK (appetite IN ('zero','low','moderate','high')),
    statement       TEXT         NOT NULL,
    max_score       INTEGER,
    -- migration 007 / 003 additions
    amber_threshold INTEGER,
    red_threshold   INTEGER,
    updated_by      INTEGER REFERENCES users(id),
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
COMMENT ON COLUMN risk_appetite.amber_threshold IS 'Score at or above which risk shows amber (warning) on heat maps';
COMMENT ON COLUMN risk_appetite.red_threshold   IS 'Score at or above which risk shows red (critical) on heat maps';

INSERT INTO risk_appetite (category, appetite, statement, max_score) VALUES
    ('Operational',  'low',      'We accept minimal operational disruption. Controls must reduce residual risk to Low or lower.', 6),
    ('Financial',    'low',      'Financial losses above $50,000 are unacceptable without board approval.', 6),
    ('Reputational', 'zero',     'Reputational risks are not tolerated. Any risk that could harm public trust must be mitigated to residual score ≤ 3.', 3),
    ('Regulatory',   'zero',     'Compliance violations carry zero tolerance. All regulatory requirements must be met.', 2),
    ('Strategic',    'moderate', 'Moderate strategic risk is acceptable when pursuing growth objectives with documented rationale.', 12),
    ('Technology',   'low',      'Technology risks must be mitigated to Low. Critical system downtime tolerance is < 4 hours RTO.', 6)
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS risk_reviews (
    id               SERIAL PRIMARY KEY,
    title            VARCHAR(500) NOT NULL,
    review_type      VARCHAR(50)  NOT NULL DEFAULT 'periodic'
                     CHECK (review_type IN ('periodic','triggered','ad_hoc','board')),
    scheduled_date   DATE         NOT NULL,
    completed_date   DATE,
    status           VARCHAR(30)  NOT NULL DEFAULT 'planned'
                     CHECK (status IN ('planned','in_progress','completed','cancelled')),
    lead_reviewer_id INTEGER REFERENCES users(id),
    scope_description TEXT,
    scope_filter     JSONB        NOT NULL DEFAULT '{}',
    total_risks      INTEGER      NOT NULL DEFAULT 0,
    reviewed_count   INTEGER      NOT NULL DEFAULT 0,
    escalated_count  INTEGER      NOT NULL DEFAULT 0,
    conclusion       TEXT,
    sign_off_by      INTEGER REFERENCES users(id),
    sign_off_at      TIMESTAMP,
    sign_off_notes   TEXT,
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rr_status    ON risk_reviews(status);
CREATE INDEX IF NOT EXISTS idx_rr_scheduled ON risk_reviews(scheduled_date);

CREATE TABLE IF NOT EXISTS risk_review_items (
    id                 SERIAL PRIMARY KEY,
    review_id          INTEGER NOT NULL REFERENCES risk_reviews(id) ON DELETE CASCADE,
    risk_id            INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    status             VARCHAR(30) NOT NULL DEFAULT 'pending'
                       CHECK (status IN ('pending','reviewed','escalated','deferred','not_applicable')),
    score_confirmed    BOOLEAN,
    new_likelihood     INTEGER CHECK (new_likelihood BETWEEN 1 AND 5),
    new_impact         INTEGER CHECK (new_impact BETWEEN 1 AND 5),
    treatment_adequate BOOLEAN,
    action_required    TEXT,
    reviewer_notes     TEXT,
    reviewed_by        INTEGER REFERENCES users(id),
    reviewed_at        TIMESTAMP,
    created_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(review_id, risk_id)
);
CREATE INDEX IF NOT EXISTS idx_rri_review ON risk_review_items(review_id);
CREATE INDEX IF NOT EXISTS idx_rri_risk   ON risk_review_items(risk_id);

CREATE TABLE IF NOT EXISTS risk_acceptances (
    id                       SERIAL PRIMARY KEY,
    risk_id                  INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    accepted_by              INTEGER NOT NULL REFERENCES users(id),
    acceptance_reason        TEXT    NOT NULL,
    conditions               TEXT,
    valid_until              DATE    NOT NULL,
    status                   VARCHAR(20) NOT NULL DEFAULT 'active'
                             CHECK (status IN ('active','expired','revoked','superseded')),
    risk_score_at_acceptance INTEGER,
    risk_level_at_acceptance VARCHAR(20),
    renewal_required         BOOLEAN NOT NULL DEFAULT FALSE,
    renewed_from             INTEGER REFERENCES risk_acceptances(id),
    revoked_by               INTEGER REFERENCES users(id),
    revoked_at               TIMESTAMP,
    revocation_reason        TEXT,
    created_at               TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ra_risk_id ON risk_acceptances(risk_id);
CREATE INDEX IF NOT EXISTS idx_ra_status  ON risk_acceptances(status);

CREATE TABLE IF NOT EXISTS risk_exceptions (
    id                        SERIAL PRIMARY KEY,
    risk_id                   INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    requested_by              INTEGER NOT NULL REFERENCES users(id),
    approved_by               INTEGER REFERENCES users(id),
    status                    VARCHAR(30) NOT NULL DEFAULT 'pending',
    exception_type            VARCHAR(30) NOT NULL DEFAULT 'accept',
    rationale                 TEXT        NOT NULL,
    compensating_controls     TEXT,
    residual_risk_acknowledged BOOLEAN    NOT NULL DEFAULT FALSE,
    expiry_date               DATE,
    approved_at               TIMESTAMP,
    rejected_at               TIMESTAMP,
    rejection_reason          TEXT,
    created_at                TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_risk_exceptions_risk_id ON risk_exceptions(risk_id);
CREATE INDEX IF NOT EXISTS idx_risk_exceptions_status  ON risk_exceptions(status);
CREATE INDEX IF NOT EXISTS idx_risk_exceptions_expiry  ON risk_exceptions(expiry_date) WHERE expiry_date IS NOT NULL;

CREATE TABLE IF NOT EXISTS risk_bowtie_causes (
    id                      SERIAL PRIMARY KEY,
    risk_id                 INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    description             TEXT    NOT NULL,
    cause_type              VARCHAR(30) NOT NULL DEFAULT 'threat'
                            CHECK (cause_type IN ('threat','vulnerability','hazard','event')),
    likelihood_contribution VARCHAR(10) NOT NULL DEFAULT 'medium'
                            CHECK (likelihood_contribution IN ('low','medium','high')),
    sort_order              INTEGER     NOT NULL DEFAULT 0,
    created_by              INTEGER REFERENCES users(id),
    created_at              TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbc_risk_id ON risk_bowtie_causes(risk_id);

CREATE TABLE IF NOT EXISTS risk_bowtie_consequences (
    id               SERIAL PRIMARY KEY,
    risk_id          INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    description      TEXT    NOT NULL,
    consequence_type VARCHAR(30) NOT NULL DEFAULT 'impact'
                     CHECK (consequence_type IN ('financial','operational','reputational','legal','safety','impact')),
    severity         VARCHAR(20) NOT NULL DEFAULT 'medium'
                     CHECK (severity IN ('low','medium','high','critical')),
    sort_order       INTEGER     NOT NULL DEFAULT 0,
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbcons_risk_id ON risk_bowtie_consequences(risk_id);

CREATE TABLE IF NOT EXISTS risk_bowtie_barriers (
    id                        SERIAL PRIMARY KEY,
    risk_id                   INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    side                      VARCHAR(10) NOT NULL CHECK (side IN ('left','right')),
    description               TEXT        NOT NULL,
    barrier_type              VARCHAR(30) NOT NULL DEFAULT 'control'
                              CHECK (barrier_type IN ('control','procedure','training','technology','monitoring')),
    effectiveness             VARCHAR(20) NOT NULL DEFAULT 'partial'
                              CHECK (effectiveness IN ('degraded','partial','substantial','full')),
    control_implementation_id INTEGER REFERENCES control_implementations(id) ON DELETE SET NULL,
    sort_order                INTEGER     NOT NULL DEFAULT 0,
    created_by                INTEGER REFERENCES users(id),
    created_at                TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbb_risk_id ON risk_bowtie_barriers(risk_id);

CREATE TABLE IF NOT EXISTS risk_scenarios (
    id                    SERIAL PRIMARY KEY,
    risk_id               INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    name                  VARCHAR(255) NOT NULL,
    description           TEXT,
    scenario_type         VARCHAR(30) NOT NULL DEFAULT 'stress'
                          CHECK (scenario_type IN ('stress','base','optimistic','catastrophic','regulatory')),
    likelihood_multiplier NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    impact_multiplier     NUMERIC(4,2) NOT NULL DEFAULT 1.0,
    scenario_likelihood   INTEGER CHECK (scenario_likelihood BETWEEN 1 AND 5),
    scenario_impact       INTEGER CHECK (scenario_impact BETWEEN 1 AND 5),
    scenario_score        INTEGER,
    financial_impact_est  NUMERIC(15,2),
    probability           NUMERIC(5,2),
    assumptions           TEXT,
    created_by            INTEGER REFERENCES users(id),
    created_at            TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rs_risk_id ON risk_scenarios(risk_id);

-- Key Risk Indicators

CREATE TABLE IF NOT EXISTS kris (
    id              SERIAL PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    description     TEXT,
    unit            VARCHAR(50)  NOT NULL DEFAULT 'count',
    direction       VARCHAR(10)  NOT NULL DEFAULT 'higher_worse'
                    CHECK (direction IN ('higher_worse','lower_worse')),
    threshold_green NUMERIC(15,4) NOT NULL,
    threshold_amber NUMERIC(15,4) NOT NULL,
    threshold_red   NUMERIC(15,4) NOT NULL,
    frequency       VARCHAR(20)  NOT NULL DEFAULT 'monthly'
                    CHECK (frequency IN ('daily','weekly','monthly','quarterly')),
    owner_id        INTEGER REFERENCES users(id),
    linked_risk_id  INTEGER REFERENCES risks(id) ON DELETE SET NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS kri_values (
    id          SERIAL PRIMARY KEY,
    kri_id      INTEGER NOT NULL REFERENCES kris(id) ON DELETE CASCADE,
    value       NUMERIC(15,4) NOT NULL,
    recorded_at DATE          NOT NULL DEFAULT CURRENT_DATE,
    notes       TEXT,
    recorded_by INTEGER REFERENCES users(id),
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_kv_kri      ON kri_values(kri_id);
CREATE INDEX IF NOT EXISTS idx_kv_recorded ON kri_values(recorded_at);

-- Treatment Plans (detailed, from migration 003)

CREATE TABLE IF NOT EXISTS treatment_plans (
    id          SERIAL PRIMARY KEY,
    -- migration 020: human-readable identifier
    plan_code   VARCHAR(20) UNIQUE,
    risk_id     INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    title       VARCHAR(255) NOT NULL,
    strategy    VARCHAR(20)  NOT NULL DEFAULT 'mitigate'
                CHECK (strategy IN ('mitigate','transfer','accept','avoid')),
    target_score INTEGER,
    owner_id    INTEGER REFERENCES users(id),
    start_date  DATE,
    target_date DATE,
    status      VARCHAR(20)  NOT NULL DEFAULT 'draft'
                CHECK (status IN ('draft','active','completed','cancelled')),
    description TEXT,
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tp_risk   ON treatment_plans(risk_id);
CREATE INDEX IF NOT EXISTS idx_tp_status ON treatment_plans(status);

CREATE TABLE IF NOT EXISTS treatment_milestones (
    id           SERIAL PRIMARY KEY,
    plan_id      INTEGER NOT NULL REFERENCES treatment_plans(id) ON DELETE CASCADE,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    due_date     DATE,
    completed_at TIMESTAMP,
    completed_by INTEGER REFERENCES users(id),
    sort_order   INTEGER      NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_tm_plan ON treatment_milestones(plan_id);

-- ── Incidents ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS incidents (
    id                 SERIAL PRIMARY KEY,
    incident_number    VARCHAR(20) UNIQUE NOT NULL,
    title              VARCHAR(255) NOT NULL,
    description        TEXT,
    severity           VARCHAR(20)  NOT NULL DEFAULT 'medium'
                       CHECK (severity IN ('critical','high','medium','low')),
    category           VARCHAR(100),
    status             VARCHAR(30)  NOT NULL DEFAULT 'open',
    reported_by        INTEGER REFERENCES users(id),
    assigned_to        INTEGER REFERENCES users(id),
    affected_systems   TEXT,
    impact_description TEXT,
    root_cause         TEXT,
    lessons_learned    TEXT,
    detected_at        TIMESTAMP,
    contained_at       TIMESTAMP,
    resolved_at        TIMESTAMP,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_incidents_status   ON incidents(status);
CREATE INDEX IF NOT EXISTS idx_incidents_severity ON incidents(severity);

CREATE TABLE IF NOT EXISTS incident_updates (
    id          SERIAL PRIMARY KEY,
    incident_id INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id),
    content     TEXT    NOT NULL,
    update_type VARCHAR(20) DEFAULT 'comment',
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_iu_incident ON incident_updates(incident_id);

CREATE TABLE IF NOT EXISTS incident_sla_policies (
    id               SERIAL PRIMARY KEY,
    severity         VARCHAR(20) NOT NULL UNIQUE,
    acknowledge_hours INTEGER    NOT NULL DEFAULT 4,
    resolve_hours    INTEGER     NOT NULL DEFAULT 72,
    escalate_hours   INTEGER,
    is_active        BOOLEAN     NOT NULL DEFAULT TRUE
);
INSERT INTO incident_sla_policies (severity, acknowledge_hours, resolve_hours, escalate_hours) VALUES
    ('critical', 1,  24,  8),
    ('high',     4,  72,  24),
    ('medium',   8,  168, NULL),
    ('low',      24, 336, NULL)
ON CONFLICT (severity) DO NOTHING;

CREATE TABLE IF NOT EXISTS incident_sla_events (
    id          SERIAL PRIMARY KEY,
    incident_id INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    event_type  VARCHAR(30) NOT NULL CHECK (event_type IN ('acknowledged','resolved','escalated','breach')),
    occurred_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by INTEGER REFERENCES users(id),
    notes       TEXT
);
CREATE INDEX IF NOT EXISTS idx_sla_incident ON incident_sla_events(incident_id);

-- Incident Response Playbooks

CREATE TABLE IF NOT EXISTS playbooks (
    id              SERIAL PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    category        VARCHAR(50)  NOT NULL DEFAULT 'general',
    severity_filter VARCHAR(20),
    description     TEXT,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS playbook_steps (
    id          SERIAL PRIMARY KEY,
    playbook_id INTEGER NOT NULL REFERENCES playbooks(id) ON DELETE CASCADE,
    step_number INTEGER NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    owner_role  VARCHAR(50),
    due_minutes INTEGER,
    sort_order  INTEGER      NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ps_playbook ON playbook_steps(playbook_id);

CREATE TABLE IF NOT EXISTS incident_playbook_runs (
    id           SERIAL PRIMARY KEY,
    incident_id  INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    playbook_id  INTEGER NOT NULL REFERENCES playbooks(id),
    started_by   INTEGER REFERENCES users(id),
    started_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    CONSTRAINT uq_incident_playbook UNIQUE (incident_id, playbook_id)
);

CREATE TABLE IF NOT EXISTS playbook_step_completions (
    id           SERIAL PRIMARY KEY,
    run_id       INTEGER NOT NULL REFERENCES incident_playbook_runs(id) ON DELETE CASCADE,
    step_id      INTEGER NOT NULL REFERENCES playbook_steps(id) ON DELETE CASCADE,
    completed_by INTEGER REFERENCES users(id),
    completed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes        TEXT,
    CONSTRAINT uq_run_step UNIQUE (run_id, step_id)
);
CREATE INDEX IF NOT EXISTS idx_psc_run ON playbook_step_completions(run_id);

-- ── Issues / Remediations ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS issues (
    id                    SERIAL PRIMARY KEY,
    issue_number          VARCHAR(20) UNIQUE NOT NULL,
    title                 VARCHAR(255) NOT NULL,
    description           TEXT,
    severity              VARCHAR(20)  NOT NULL DEFAULT 'medium'
                          CHECK (severity IN ('critical','high','medium','low')),
    status                VARCHAR(30)  NOT NULL DEFAULT 'open',
    source_type           VARCHAR(100),
    source_id             INTEGER,
    assigned_to           INTEGER REFERENCES users(id),
    created_by            INTEGER REFERENCES users(id),
    due_date              DATE,
    resolved_at           TIMESTAMP,
    resolution            TEXT,
    recurrence_prevention TEXT,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_issues_status ON issues(status);

CREATE TABLE IF NOT EXISTS issue_updates (
    id          SERIAL PRIMARY KEY,
    issue_id    INTEGER NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id),
    content     TEXT    NOT NULL,
    update_type VARCHAR(20) DEFAULT 'comment',
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Vendors ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS vendors (
    id               SERIAL PRIMARY KEY,
    vendor_code      VARCHAR(20) UNIQUE NOT NULL,
    name             VARCHAR(255) NOT NULL,
    category         VARCHAR(100),
    website          VARCHAR(255),
    primary_contact  VARCHAR(100),
    contact_email    VARCHAR(255),
    contact_phone    VARCHAR(50),
    contact_name     VARCHAR(255),
    risk_tier        VARCHAR(20) DEFAULT 'medium',
    risk_rating      VARCHAR(20) DEFAULT 'medium'
                     CHECK (risk_rating IN ('critical','high','medium','low')),
    status           VARCHAR(20)  NOT NULL DEFAULT 'active'
                     CHECK (status IN ('active','inactive','under_review')),
    country          VARCHAR(100),
    description      TEXT,
    notes            TEXT,
    contract_start   DATE,
    contract_end     DATE,
    data_access      BOOLEAN DEFAULT FALSE,
    critical_service BOOLEAN DEFAULT FALSE,
    owner_id         INTEGER REFERENCES users(id),
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vendors_status   ON vendors(status);
CREATE INDEX IF NOT EXISTS idx_vendors_risk_tier ON vendors(risk_tier);

CREATE TABLE IF NOT EXISTS vendor_assessments (
    id                   SERIAL PRIMARY KEY,
    vendor_id            INTEGER NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
    assessment_type      VARCHAR(50) NOT NULL DEFAULT 'security',
    status               VARCHAR(20) NOT NULL DEFAULT 'planned'
                         CHECK (status IN ('planned','in_progress','completed','cancelled')),
    overall_score        SMALLINT,
    score                INTEGER CHECK (score BETWEEN 0 AND 100),
    risk_rating          VARCHAR(20),
    findings             TEXT,
    recommendations      TEXT,
    assessed_by          INTEGER REFERENCES users(id),
    scheduled_date       DATE,
    completed_date       DATE,
    next_assessment_date DATE,
    created_at           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_va_vendor ON vendor_assessments(vendor_id);

CREATE TABLE IF NOT EXISTS vendor_contracts (
    id                  SERIAL PRIMARY KEY,
    vendor_id           INTEGER NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
    title               VARCHAR(255) NOT NULL,
    contract_number     VARCHAR(100),
    status              VARCHAR(20)  NOT NULL DEFAULT 'active'
                        CHECK (status IN ('draft','active','expired','terminated')),
    value               NUMERIC(15,2),
    currency            VARCHAR(3)   NOT NULL DEFAULT 'USD',
    start_date          DATE         NOT NULL,
    end_date            DATE,
    auto_renewal        BOOLEAN      NOT NULL DEFAULT FALSE,
    renewal_notice_days INTEGER DEFAULT 30,
    description         TEXT,
    owner_id            INTEGER REFERENCES users(id),
    created_by          INTEGER REFERENCES users(id),
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vc_vendor   ON vendor_contracts(vendor_id);
CREATE INDEX IF NOT EXISTS idx_vc_end_date ON vendor_contracts(end_date);

CREATE TABLE IF NOT EXISTS vendor_portal_tokens (
    id         SERIAL PRIMARY KEY,
    vendor_id  INTEGER NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    title      VARCHAR(255) NOT NULL DEFAULT 'Vendor Self-Assessment',
    questions  JSONB        NOT NULL DEFAULT '[]',
    expires_at TIMESTAMP    NOT NULL,
    used_at    TIMESTAMP,
    response   JSONB,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vpt_vendor ON vendor_portal_tokens(vendor_id);
CREATE INDEX IF NOT EXISTS idx_vpt_token  ON vendor_portal_tokens(token_hash);

-- ── Evidence ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS evidence (
    id          SERIAL PRIMARY KEY,
    entity_type VARCHAR(50)  NOT NULL,
    entity_id   INTEGER      NOT NULL,
    filename    VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_size   INTEGER,
    mime_type   VARCHAR(100),
    uploaded_by INTEGER REFERENCES users(id),
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_evidence_entity ON evidence(entity_type, entity_id);

CREATE TABLE IF NOT EXISTS evidence_files (
    id            SERIAL PRIMARY KEY,
    entity_type   VARCHAR(50)  NOT NULL,
    entity_id     INTEGER      NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(100),
    file_size     INTEGER,
    file_hash     VARCHAR(64),
    description   TEXT,
    expires_at    DATE,
    uploaded_by   INTEGER REFERENCES users(id),
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ef_entity ON evidence_files(entity_type, entity_id);

-- ── Assets ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS assets (
    id             SERIAL PRIMARY KEY,
    -- migration 020: human-readable identifier
    asset_code     VARCHAR(20) UNIQUE,
    name           VARCHAR(255) NOT NULL,
    asset_type     VARCHAR(100) NOT NULL DEFAULT 'server',
    criticality    VARCHAR(50)  NOT NULL DEFAULT 'medium',
    classification VARCHAR(50)  NOT NULL DEFAULT 'internal',
    status         VARCHAR(50)  NOT NULL DEFAULT 'active',
    owner_id       INTEGER REFERENCES users(id),
    location       VARCHAR(255),
    ip_address     INET,
    hostname       VARCHAR(255),
    vendor         VARCHAR(255),
    version        VARCHAR(100),
    last_scanned   DATE,
    last_reviewed  DATE,
    tags           JSONB        NOT NULL DEFAULT '[]',
    description    TEXT,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_assets_type        ON assets(asset_type);
CREATE INDEX IF NOT EXISTS idx_assets_criticality ON assets(criticality);
CREATE INDEX IF NOT EXISTS idx_assets_status      ON assets(status);

CREATE TABLE IF NOT EXISTS asset_risk_links (
    id       SERIAL PRIMARY KEY,
    asset_id INTEGER NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
    risk_id  INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    UNIQUE(asset_id, risk_id)
);

-- ── Threats ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS threats (
    id           SERIAL PRIMARY KEY,
    -- migration 020: human-readable identifier
    threat_number VARCHAR(20) UNIQUE,
    title        VARCHAR(255) NOT NULL,
    category     VARCHAR(30)  NOT NULL DEFAULT 'technology'
                 CHECK (category IN ('people','process','technology','natural','regulatory','financial')),
    description  TEXT,
    likelihood   INTEGER      CHECK (likelihood BETWEEN 1 AND 5),
    impact       INTEGER      CHECK (impact BETWEEN 1 AND 5),
    status       VARCHAR(20)  NOT NULL DEFAULT 'active'
                 CHECK (status IN ('active','mitigated','accepted','retired')),
    source       VARCHAR(255),
    mitigations  TEXT,
    owner_id     INTEGER REFERENCES users(id),
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_threats_category ON threats(category);
CREATE INDEX IF NOT EXISTS idx_threats_status   ON threats(status);

CREATE TABLE IF NOT EXISTS threat_risk_links (
    id         SERIAL PRIMARY KEY,
    threat_id  INTEGER NOT NULL REFERENCES threats(id) ON DELETE CASCADE,
    risk_id    INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_threat_risk UNIQUE (threat_id, risk_id)
);
CREATE INDEX IF NOT EXISTS idx_trl_threat ON threat_risk_links(threat_id);
CREATE INDEX IF NOT EXISTS idx_trl_risk   ON threat_risk_links(risk_id);

-- ── Change Management ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS change_requests (
    id                  SERIAL PRIMARY KEY,
    -- migration 020: human-readable identifier
    change_number       VARCHAR(20) UNIQUE,
    title               VARCHAR(255) NOT NULL,
    description         TEXT         NOT NULL,
    change_type         VARCHAR(50)  NOT NULL DEFAULT 'normal',
    status              VARCHAR(50)  NOT NULL DEFAULT 'draft',
    risk_level          VARCHAR(50)  NOT NULL DEFAULT 'medium',
    implementation_date TIMESTAMP,
    rollback_plan       TEXT,
    impact_analysis     TEXT,
    testing_plan        TEXT,
    submitter_id        INTEGER NOT NULL REFERENCES users(id),
    cab_reviewer_id     INTEGER REFERENCES users(id),
    reviewed_at         TIMESTAMP,
    review_notes        TEXT,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cr_status    ON change_requests(status);
CREATE INDEX IF NOT EXISTS idx_cr_submitter ON change_requests(submitter_id);

CREATE TABLE IF NOT EXISTS change_request_updates (
    id          SERIAL PRIMARY KEY,
    change_id   INTEGER NOT NULL REFERENCES change_requests(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id),
    content     TEXT    NOT NULL,
    update_type VARCHAR(50) NOT NULL DEFAULT 'comment',
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── BCP / DR ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS bcp_plans (
    id             SERIAL PRIMARY KEY,
    -- migration 020: human-readable identifier
    plan_code      VARCHAR(20) UNIQUE,
    title          VARCHAR(255) NOT NULL,
    description    TEXT,
    version        VARCHAR(50)  NOT NULL DEFAULT '1.0',
    status         VARCHAR(50)  NOT NULL DEFAULT 'draft',
    owner_id       INTEGER REFERENCES users(id),
    rto_hours      INTEGER,
    rpo_hours      INTEGER,
    last_tested    DATE,
    next_test_date DATE,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bcp_plan_sections (
    id           SERIAL PRIMARY KEY,
    plan_id      INTEGER NOT NULL REFERENCES bcp_plans(id) ON DELETE CASCADE,
    section_type VARCHAR(100) NOT NULL,
    title        VARCHAR(255) NOT NULL,
    content      TEXT,
    sort_order   INTEGER      NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bcp_exercises (
    id              SERIAL PRIMARY KEY,
    plan_id         INTEGER NOT NULL REFERENCES bcp_plans(id) ON DELETE CASCADE,
    exercise_type   VARCHAR(100) NOT NULL DEFAULT 'tabletop',
    name            VARCHAR(255) NOT NULL,
    scheduled_date  DATE,
    conducted_date  DATE,
    outcome         VARCHAR(50),
    findings        TEXT,
    lessons_learned TEXT,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ── Awareness Training ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS awareness_programs (
    id           SERIAL PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    content_type VARCHAR(30)  DEFAULT 'document',
    content_body TEXT,
    content_url  VARCHAR(500),
    due_date     DATE,
    status       VARCHAR(20)  DEFAULT 'active',
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP    DEFAULT NOW(),
    updated_at   TIMESTAMP    DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS awareness_assignments (
    id           SERIAL PRIMARY KEY,
    program_id   INTEGER NOT NULL REFERENCES awareness_programs(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    completed    BOOLEAN   DEFAULT FALSE,
    completed_at TIMESTAMP,
    notes        TEXT,
    UNIQUE(program_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_awareness_assignments_program ON awareness_assignments(program_id);

-- ── Account Reviews (Access Certification) ────────────────────────────────────

CREATE TABLE IF NOT EXISTS account_reviews (
    id           SERIAL PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    scope        TEXT,
    reviewer_id  INTEGER REFERENCES users(id),
    status       VARCHAR(20)  DEFAULT 'pending',
    due_date     DATE,
    completed_at TIMESTAMP,
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP    DEFAULT NOW(),
    updated_at   TIMESTAMP    DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS account_review_items (
    id             SERIAL PRIMARY KEY,
    review_id      INTEGER NOT NULL REFERENCES account_reviews(id) ON DELETE CASCADE,
    account_name   VARCHAR(255) NOT NULL,
    user_full_name VARCHAR(255),
    system_name    VARCHAR(255),
    access_level   VARCHAR(100),
    decision       VARCHAR(20)  DEFAULT 'pending',
    decision_notes TEXT,
    reviewed_at    TIMESTAMP,
    reviewed_by    INTEGER REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_account_review_items_review ON account_review_items(review_id);

-- ── Data Privacy ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS privacy_records (
    id                      SERIAL PRIMARY KEY,
    name                    VARCHAR(255) NOT NULL,
    description             TEXT,
    controller_name         VARCHAR(255),
    processor_name          VARCHAR(255),
    purpose                 TEXT,
    legal_basis             VARCHAR(50),
    data_subject_categories TEXT,
    data_categories         TEXT,
    recipients              TEXT,
    third_country_transfers TEXT,
    retention_period        VARCHAR(255),
    security_measures       TEXT,
    dpia_required           BOOLEAN   DEFAULT FALSE,
    dpia_completed          BOOLEAN   DEFAULT FALSE,
    dpia_date               DATE,
    status                  VARCHAR(20) DEFAULT 'active',
    created_by              INTEGER REFERENCES users(id),
    created_at              TIMESTAMP   DEFAULT NOW(),
    updated_at              TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_privacy_records_status ON privacy_records(status);

CREATE TABLE IF NOT EXISTS data_subject_requests (
    id            SERIAL PRIMARY KEY,
    request_type  VARCHAR(50),
    subject_name  VARCHAR(255),
    subject_email VARCHAR(255),
    description   TEXT,
    status        VARCHAR(20) DEFAULT 'open',
    due_date      DATE,
    completed_at  TIMESTAMP,
    assigned_to   INTEGER REFERENCES users(id),
    notes         TEXT,
    created_at    TIMESTAMP   DEFAULT NOW(),
    updated_at    TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_dsr_status ON data_subject_requests(status);

-- ── System Security Plans (SSP) ───────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS ssp_plans (
    id                       SERIAL PRIMARY KEY,
    title                    VARCHAR(255) NOT NULL,
    system_name              VARCHAR(255),
    system_description       TEXT,
    system_owner             VARCHAR(255),
    system_owner_email       VARCHAR(255),
    information_owner        VARCHAR(255),
    authorizing_official     VARCHAR(255),
    authorization_boundary   TEXT,
    network_architecture     TEXT,
    data_flow                TEXT,
    operational_status       VARCHAR(50) DEFAULT 'operational',
    system_type              VARCHAR(50) DEFAULT 'major_application',
    confidentiality_impact   VARCHAR(20) DEFAULT 'moderate',
    integrity_impact         VARCHAR(20) DEFAULT 'moderate',
    availability_impact      VARCHAR(20) DEFAULT 'moderate',
    authorization_date       DATE,
    next_review_date         DATE,
    -- migration 018: versioning and signature
    version                  VARCHAR(20)  DEFAULT '1.0',
    revision                 INTEGER      DEFAULT 0,
    authorizing_signature    VARCHAR(255),
    signature_date           DATE,
    network_arch_filename    VARCHAR(500),
    network_arch_data        BYTEA,
    data_flow_filename       VARCHAR(500),
    data_flow_data           BYTEA,
    -- migration 019: company/org info
    company_name             VARCHAR(255),
    duns_number              VARCHAR(50),
    cage_code                VARCHAR(50),
    framework                VARCHAR(255),
    assessment_scope         TEXT,
    presentation_mode        VARCHAR(50)  DEFAULT 'standard',
    -- migration 019: approval
    approval_status          VARCHAR(50),
    approval_date            DATE,
    approval_notes           TEXT,
    approver_name            VARCHAR(255),
    approver_title           VARCHAR(255),
    -- migration 019: certification
    certifying_official_name  VARCHAR(255),
    certifying_official_title VARCHAR(255),
    certification_date        DATE,
    certification_statement   TEXT,
    -- migration 019: extended boundary
    boundary_description      TEXT,
    info_systems_apps         TEXT,
    endpoints_user_devices    TEXT,
    servers_storage           TEXT,
    physical_security         TEXT,
    access_control_auth       TEXT,
    general_system_purpose    TEXT,
    -- migration 019: environment
    topology_description      TEXT,
    maintenance_info          TEXT,
    system_details            TEXT,
    -- migration 019: JSONB inventories
    team_contacts             JSONB DEFAULT '[]'::jsonb,
    contracts                 JSONB DEFAULT '[]'::jsonb,
    data_inventory            JSONB DEFAULT '[]'::jsonb,
    hardware_inventory        JSONB DEFAULT '[]'::jsonb,
    software_inventory        JSONB DEFAULT '[]'::jsonb,
    network_devices           JSONB DEFAULT '[]'::jsonb,
    other_connected_systems   JSONB DEFAULT '[]'::jsonb,
    server_inventory          JSONB DEFAULT '[]'::jsonb,
    user_device_types         JSONB DEFAULT '[]'::jsonb,
    created_by                INTEGER REFERENCES users(id),
    created_at                TIMESTAMP DEFAULT NOW(),
    updated_at                TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS ssp_packages (
    id         SERIAL PRIMARY KEY,
    ssp_id     INTEGER NOT NULL REFERENCES ssp_plans(id) ON DELETE CASCADE,
    package_id INTEGER NOT NULL REFERENCES compliance_packages(id) ON DELETE CASCADE,
    UNIQUE(ssp_id, package_id)
);
CREATE INDEX IF NOT EXISTS idx_ssp_packages_ssp ON ssp_packages(ssp_id);

CREATE TABLE IF NOT EXISTS ssp_control_statements (
    id                       SERIAL PRIMARY KEY,
    ssp_id                   INTEGER NOT NULL REFERENCES ssp_plans(id) ON DELETE CASCADE,
    objective_id             INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    implementation_statement TEXT,
    responsible_roles        TEXT,
    objective_responses      TEXT,
    UNIQUE(ssp_id, objective_id)
);
CREATE INDEX IF NOT EXISTS idx_ssp_statements_ssp ON ssp_control_statements(ssp_id);

-- ── POA&M ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS poam_items (
    id                    SERIAL PRIMARY KEY,
    poam_number           VARCHAR(20) UNIQUE NOT NULL,
    title                 VARCHAR(255) NOT NULL,
    weakness_description  TEXT,
    resource_requirements TEXT,
    scheduled_completion  DATE,
    status                VARCHAR(20) DEFAULT 'open',
    objective_id          INTEGER REFERENCES compliance_objectives(id) ON DELETE SET NULL,
    package_id            INTEGER REFERENCES compliance_packages(id) ON DELETE SET NULL,
    owner_id              INTEGER REFERENCES users(id),
    created_by            INTEGER REFERENCES users(id),
    created_at            TIMESTAMP   DEFAULT NOW(),
    updated_at            TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_poam_items_status     ON poam_items(status);
CREATE INDEX IF NOT EXISTS idx_poam_items_package_id ON poam_items(package_id);

CREATE TABLE IF NOT EXISTS poam_milestones (
    id           SERIAL PRIMARY KEY,
    poam_id      INTEGER NOT NULL REFERENCES poam_items(id) ON DELETE CASCADE,
    description  TEXT    NOT NULL,
    due_date     DATE,
    is_complete  BOOLEAN DEFAULT FALSE,
    completed_at TIMESTAMP,
    created_at   TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_poam_milestones_poam ON poam_milestones(poam_id);

CREATE TABLE IF NOT EXISTS odp_entries (
    id              SERIAL PRIMARY KEY,
    objective_id    INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    parameter_name  VARCHAR(255) NOT NULL,
    parameter_value TEXT,
    notes           TEXT,
    updated_by      INTEGER REFERENCES users(id),
    updated_at      TIMESTAMP   DEFAULT NOW(),
    UNIQUE(objective_id, parameter_name)
);
CREATE INDEX IF NOT EXISTS idx_odp_entries_objective ON odp_entries(objective_id);

-- ── External Audit Findings ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS audit_findings (
    id             SERIAL PRIMARY KEY,
    finding_number VARCHAR(20) UNIQUE NOT NULL,
    title          VARCHAR(255) NOT NULL,
    description    TEXT,
    severity       VARCHAR(20) DEFAULT 'medium',
    status         VARCHAR(30) DEFAULT 'open',
    source         VARCHAR(50) DEFAULT 'external_audit',
    audit_name     VARCHAR(255),
    auditor_name   VARCHAR(255),
    objective_id   INTEGER REFERENCES compliance_objectives(id) ON DELETE SET NULL,
    package_id     INTEGER REFERENCES compliance_packages(id) ON DELETE SET NULL,
    owner_id       INTEGER REFERENCES users(id),
    deadline       DATE,
    response_notes TEXT,
    closed_at      TIMESTAMP,
    created_by     INTEGER REFERENCES users(id),
    created_at     TIMESTAMP   DEFAULT NOW(),
    updated_at     TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_audit_findings_status   ON audit_findings(status);
CREATE INDEX IF NOT EXISTS idx_audit_findings_severity ON audit_findings(severity);

CREATE TABLE IF NOT EXISTS finding_updates (
    id         SERIAL PRIMARY KEY,
    finding_id INTEGER NOT NULL REFERENCES audit_findings(id) ON DELETE CASCADE,
    user_id    INTEGER REFERENCES users(id),
    content    TEXT    NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_finding_updates ON finding_updates(finding_id);

-- ── Automation Rules Engine ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS automation_rules (
    id                SERIAL PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    description       TEXT,
    trigger_type      VARCHAR(50)  NOT NULL,
    trigger_config    JSONB DEFAULT '{}',
    action_type       VARCHAR(50)  NOT NULL,
    action_config     JSONB DEFAULT '{}',
    is_active         BOOLEAN DEFAULT TRUE,
    last_triggered_at TIMESTAMP,
    trigger_count     INTEGER DEFAULT 0,
    created_by        INTEGER REFERENCES users(id),
    created_at        TIMESTAMP   DEFAULT NOW(),
    updated_at        TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_automation_rules_active ON automation_rules(is_active);

CREATE TABLE IF NOT EXISTS automation_logs (
    id           SERIAL PRIMARY KEY,
    rule_id      INTEGER NOT NULL REFERENCES automation_rules(id) ON DELETE CASCADE,
    triggered_at TIMESTAMP DEFAULT NOW(),
    status       VARCHAR(20) DEFAULT 'success',
    details      TEXT
);
CREATE INDEX IF NOT EXISTS idx_automation_logs_rule ON automation_logs(rule_id);

-- ── Dashboards ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS custom_dashboards (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    description TEXT,
    is_shared   BOOLEAN DEFAULT FALSE,
    owner_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
    created_at  TIMESTAMP   DEFAULT NOW(),
    updated_at  TIMESTAMP   DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS dashboard_widgets (
    id           SERIAL PRIMARY KEY,
    dashboard_id INTEGER NOT NULL REFERENCES custom_dashboards(id) ON DELETE CASCADE,
    widget_type  VARCHAR(50)  NOT NULL,
    title        VARCHAR(255) NOT NULL,
    config       JSONB DEFAULT '{}',
    position     INTEGER DEFAULT 0,
    created_at   TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_dashboard_widgets ON dashboard_widgets(dashboard_id);

-- ── RACI / Shared Responsibility ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS raci_assignments (
    id           SERIAL PRIMARY KEY,
    package_id   INTEGER NOT NULL REFERENCES compliance_packages(id) ON DELETE CASCADE,
    objective_id INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    raci_role    VARCHAR(20) NOT NULL,
    UNIQUE(package_id, objective_id, user_id, raci_role)
);
CREATE INDEX IF NOT EXISTS idx_raci_package ON raci_assignments(package_id);

CREATE TABLE IF NOT EXISTS shared_responsibility (
    id             SERIAL PRIMARY KEY,
    package_id     INTEGER NOT NULL REFERENCES compliance_packages(id) ON DELETE CASCADE,
    objective_id   INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    responsibility VARCHAR(20) DEFAULT 'customer',
    provider_name  VARCHAR(255),
    customer_notes TEXT,
    provider_notes TEXT,
    UNIQUE(package_id, objective_id)
);
CREATE INDEX IF NOT EXISTS idx_shared_resp_package ON shared_responsibility(package_id);

-- ── Projects ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS grc_projects (
    id             SERIAL PRIMARY KEY,
    project_code   VARCHAR(20) UNIQUE NOT NULL,
    title          VARCHAR(255) NOT NULL,
    description    TEXT,
    status         VARCHAR(30) DEFAULT 'planning',
    priority       VARCHAR(20) DEFAULT 'medium',
    start_date     DATE,
    end_date       DATE,
    budget_planned NUMERIC(12,2),
    budget_actual  NUMERIC(12,2),
    project_lead   INTEGER REFERENCES users(id),
    created_by     INTEGER REFERENCES users(id),
    created_at     TIMESTAMP   DEFAULT NOW(),
    updated_at     TIMESTAMP   DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS grc_project_tasks (
    id          SERIAL PRIMARY KEY,
    project_id  INTEGER NOT NULL REFERENCES grc_projects(id) ON DELETE CASCADE,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    status      VARCHAR(20) DEFAULT 'todo',
    assigned_to INTEGER REFERENCES users(id),
    due_date    DATE,
    created_at  TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_grc_project_tasks ON grc_project_tasks(project_id);

CREATE TABLE IF NOT EXISTS grc_project_links (
    id          SERIAL PRIMARY KEY,
    project_id  INTEGER NOT NULL REFERENCES grc_projects(id) ON DELETE CASCADE,
    entity_type VARCHAR(50) NOT NULL,
    entity_id   INTEGER     NOT NULL,
    UNIQUE(project_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_grc_project_links ON grc_project_links(project_id);

-- ── CUI Inventory ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS cui_inventory (
    id                   SERIAL PRIMARY KEY,
    inventory_number     VARCHAR(20) UNIQUE NOT NULL,
    data_description     TEXT NOT NULL,
    cui_category         VARCHAR(100),
    asset_id             INTEGER REFERENCES assets(id) ON DELETE SET NULL,
    system_name          VARCHAR(255),
    location_description TEXT,
    storage_type         VARCHAR(50) DEFAULT 'database',
    access_controls      TEXT,
    is_encrypted         BOOLEAN DEFAULT FALSE,
    encryption_details   TEXT,
    data_owner           VARCHAR(255),
    created_by           INTEGER REFERENCES users(id),
    created_at           TIMESTAMP   DEFAULT NOW(),
    updated_at           TIMESTAMP   DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_cui_inventory_type ON cui_inventory(storage_type);

-- ═══════════════════════════════════════════════════════════════════════════
-- SEED DATA
-- ═══════════════════════════════════════════════════════════════════════════

-- ── Default Settings ──────────────────────────────────────────────────────────
-- (Matches install.php seed + migration 003 additions; ON CONFLICT DO NOTHING
--  means existing values are never overwritten.)

INSERT INTO settings (key, value, type, description) VALUES
    ('org_name',              'My Organization',                                                  'string',  'Organization name'),
    ('org_logo',              '',                                                                 'string',  'Organization logo URL'),
    ('date_format',           'Y-m-d',                                                           'string',  'Date display format'),
    ('timezone',              'UTC',                                                              'string',  'Application timezone'),
    ('session_timeout',       '480',                                                              'integer', 'Session timeout in minutes'),
    ('version',               '2.0.0',                                                           'string',  'AEGIS version'),
    ('ai_settings',           '{"provider":"","api_key":"","model":""}',                         'string',  'AI advisor configuration (JSON)'),
    ('upload_allowed_types',  'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,txt,csv,zip',              'string',  'Allowed upload file extensions'),
    ('upload_max_size_mb',    '20',                                                               'integer', 'Maximum file upload size in MB'),
    ('smtp_host',             '',                                                                 'string',  'SMTP server hostname'),
    ('smtp_port',             '587',                                                              'integer', 'SMTP server port'),
    ('smtp_user',             '',                                                                 'string',  'SMTP username'),
    ('smtp_pass',             '',                                                                 'string',  'SMTP password (encrypted at rest)'),
    ('smtp_from',             '',                                                                 'string',  'Default from address for outbound email'),
    ('smtp_from_name',        'AEGIS GRC',                                                        'string',  'Default from name for outbound email'),
    ('smtp_tls',              '1',                                                               'boolean', 'Enable STARTTLS'),
    ('email_notifications',   '0',                                                               'boolean', 'Enable email notifications')
ON CONFLICT (key) DO NOTHING;

-- ── Incident SLA Policies ─────────────────────────────────────────────────────
-- (already inserted above in table block; repeated guard for idempotency)

INSERT INTO incident_sla_policies (severity, acknowledge_hours, resolve_hours, escalate_hours) VALUES
    ('critical', 1,  24,  8),
    ('high',     4,  72,  24),
    ('medium',   8,  168, NULL),
    ('low',      24, 336, NULL)
ON CONFLICT (severity) DO NOTHING;

-- ── Data Retention Policies ───────────────────────────────────────────────────

INSERT INTO data_retention_policies (entity_type, retention_days, action, is_enabled) VALUES
    ('activity_log',       365, 'delete', FALSE),
    ('notification_log',    90, 'delete', FALSE),
    ('webhook_deliveries', 180, 'delete', FALSE),
    ('alerts',              90, 'delete', FALSE)
ON CONFLICT (entity_type) DO NOTHING;

-- ── Risk Appetite Defaults ────────────────────────────────────────────────────

INSERT INTO risk_appetite (category, appetite, statement, max_score) VALUES
    ('Operational',  'low',      'We accept minimal operational disruption. Controls must reduce residual risk to Low or lower.', 6),
    ('Financial',    'low',      'Financial losses above $50,000 are unacceptable without board approval.', 6),
    ('Reputational', 'zero',     'Reputational risks are not tolerated. Any risk that could harm public trust must be mitigated to residual score ≤ 3.', 3),
    ('Regulatory',   'zero',     'Compliance violations carry zero tolerance. All regulatory requirements must be met.', 2),
    ('Strategic',    'moderate', 'Moderate strategic risk is acceptable when pursuing growth objectives with documented rationale.', 12),
    ('Technology',   'low',      'Technology risks must be mitigated to Low. Critical system downtime tolerance is < 4 hours RTO.', 6)
ON CONFLICT DO NOTHING;

-- ── Default Report Schedules ──────────────────────────────────────────────────
-- (is_active = FALSE; recipients not yet configured)

INSERT INTO report_schedules
    (name, report_type, frequency, day_of_week, day_of_month, send_time, recipients, filters, format, is_active)
VALUES
    ('Weekly Risk Summary',        'risk_register',    'weekly',    1, 1, '08:00', '[]', '{}', 'html', FALSE),
    ('Monthly Compliance Report',  'compliance_summary','monthly',  1, 1, '08:00', '[]', '{}', 'html', FALSE),
    ('Quarterly Executive Summary','executive_summary', 'quarterly', 1, 1, '08:00', '[]', '{}', 'html', FALSE)
ON CONFLICT DO NOTHING;

-- ═══════════════════════════════════════════════════════════════════════════
-- END OF SCHEMA
-- ═══════════════════════════════════════════════════════════════════════════
