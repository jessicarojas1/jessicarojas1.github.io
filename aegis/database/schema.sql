-- AEGIS GRC Database Schema (all tables created in the 'aegis' schema)
--
-- This file is a complete, idempotent, manual-setup REFERENCE: it can be run
-- against a fresh database to produce a fully functional schema. It uses
-- CREATE TABLE / INDEX IF NOT EXISTS and INSERT ... ON CONFLICT DO NOTHING so
-- it is safe to re-run. The AUTHORITATIVE installer is install.php (which also
-- seeds default settings and runs database/migrations/*); keep this file in
-- sync with the combined state of all migrations whenever one is added.

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'viewer',
    department VARCHAR(255),
    job_title VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login TIMESTAMP,
    email_verified_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_keys (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    key_prefix VARCHAR(20) NOT NULL,
    key_hash VARCHAR(255) NOT NULL,
    permissions JSONB NOT NULL DEFAULT '["read"]',
    last_used TIMESTAMP,
    expires_at TIMESTAMP,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS standards (
    id SERIAL PRIMARY KEY,
    code VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    version VARCHAR(50),
    description TEXT,
    category VARCHAR(100),
    authority VARCHAR(255),
    url VARCHAR(500),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS compliance_packages (
    id SERIAL PRIMARY KEY,
    standard_id INTEGER REFERENCES standards(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    version VARCHAR(50),
    description TEXT,
    price DECIMAL(10,2),
    objectives_count INTEGER DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    imported_by INTEGER REFERENCES users(id),
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS compliance_objectives (
    id SERIAL PRIMARY KEY,
    package_id INTEGER REFERENCES compliance_packages(id) ON DELETE CASCADE,
    parent_id INTEGER REFERENCES compliance_objectives(id),
    code VARCHAR(100) NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    category VARCHAR(255),
    level INTEGER NOT NULL DEFAULT 1,
    weight DECIMAL(5,2) DEFAULT 1.0,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS control_implementations (
    id SERIAL PRIMARY KEY,
    objective_id INTEGER REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    status VARCHAR(50) NOT NULL DEFAULT 'not_started',
    implementation_notes TEXT,
    evidence TEXT,
    assigned_to INTEGER REFERENCES users(id),
    due_date DATE,
    last_reviewed TIMESTAMP,
    reviewed_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(objective_id)
);

CREATE TABLE IF NOT EXISTS audits (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    package_id INTEGER REFERENCES compliance_packages(id),
    audit_type VARCHAR(100) NOT NULL DEFAULT 'internal',
    frequency VARCHAR(50),
    status VARCHAR(50) NOT NULL DEFAULT 'planned',
    scheduled_date DATE,
    start_date DATE,
    completed_date DATE,
    auditor_id INTEGER REFERENCES users(id),
    created_by INTEGER REFERENCES users(id),
    notes TEXT,
    score DECIMAL(5,2),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_schedules (
    id SERIAL PRIMARY KEY,
    package_id INTEGER REFERENCES compliance_packages(id),
    frequency VARCHAR(50) NOT NULL DEFAULT 'annual',
    last_audit_date DATE,
    next_due_date DATE,
    assigned_auditor INTEGER REFERENCES users(id),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_items (
    id SERIAL PRIMARY KEY,
    audit_id INTEGER REFERENCES audits(id) ON DELETE CASCADE,
    objective_id INTEGER REFERENCES compliance_objectives(id),
    status VARCHAR(50) NOT NULL DEFAULT 'not_assessed',
    finding TEXT,
    evidence TEXT,
    notes TEXT,
    risk_level VARCHAR(50),
    remediation TEXT,
    remediation_due DATE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policies (
    id SERIAL PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    policy_number VARCHAR(100),
    description TEXT,
    content TEXT,
    version VARCHAR(50) NOT NULL DEFAULT '1.0',
    status VARCHAR(50) NOT NULL DEFAULT 'draft',
    category VARCHAR(255),
    owner_id INTEGER REFERENCES users(id),
    approver_id INTEGER REFERENCES users(id),
    review_frequency VARCHAR(50) DEFAULT 'annual',
    next_review_date DATE,
    approved_at TIMESTAMP,
    published_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policy_versions (
    id SERIAL PRIMARY KEY,
    policy_id INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    version VARCHAR(50) NOT NULL,
    content TEXT,
    change_summary TEXT,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policy_mappings (
    id SERIAL PRIMARY KEY,
    policy_id INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    objective_id INTEGER REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(policy_id, objective_id)
);

CREATE TABLE IF NOT EXISTS policy_reviews (
    id SERIAL PRIMARY KEY,
    policy_id INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    reviewer_id INTEGER REFERENCES users(id),
    scheduled_date DATE,
    completed_date DATE,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    outcome VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risk_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    color VARCHAR(50) DEFAULT '#6366f1',
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risks (
    id SERIAL PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    risk_id VARCHAR(100),
    description TEXT,
    category_id INTEGER REFERENCES risk_categories(id),
    likelihood INTEGER NOT NULL DEFAULT 3 CHECK (likelihood BETWEEN 1 AND 5),
    impact INTEGER NOT NULL DEFAULT 3 CHECK (impact BETWEEN 1 AND 5),
    inherent_score INTEGER NOT NULL DEFAULT 0,
    residual_likelihood INTEGER CHECK (residual_likelihood BETWEEN 1 AND 5),
    residual_impact INTEGER CHECK (residual_impact BETWEEN 1 AND 5),
    residual_score INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'open',
    treatment_type VARCHAR(50),
    treatment_strategies JSONB NOT NULL DEFAULT '[]',
    treatment_description TEXT,
    owner_id INTEGER REFERENCES users(id),
    review_date DATE,
    identified_date DATE NOT NULL DEFAULT CURRENT_DATE,
    tags JSONB DEFAULT '[]',
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Enterprise columns
    velocity INTEGER DEFAULT 3 CHECK (velocity BETWEEN 1 AND 5),
    proximity VARCHAR(20) DEFAULT 'medium_term'
        CHECK (proximity IN ('immediate','short_term','medium_term','long_term')),
    financial_min     DECIMAL(15,2),
    financial_likely  DECIMAL(15,2),
    financial_max     DECIMAL(15,2),
    financial_currency VARCHAR(3) DEFAULT 'USD',
    parent_risk_id INTEGER REFERENCES risks(id),
    assessment_status VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (assessment_status IN ('draft','pending_review','approved')),
    reviewed_by INTEGER REFERENCES users(id),
    reviewed_at TIMESTAMP,
    review_notes TEXT,
    risk_source VARCHAR(50)
        CHECK (risk_source IN ('strategic','operational','financial','compliance','technology',
                               'reputational','external','people','project') OR risk_source IS NULL),
    confidence VARCHAR(10) DEFAULT 'medium' CHECK (confidence IN ('low','medium','high')),
    target_likelihood INTEGER CHECK (target_likelihood BETWEEN 1 AND 5),
    target_impact INTEGER CHECK (target_impact BETWEEN 1 AND 5)
);

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
    id                         SERIAL PRIMARY KEY,
    risk_id                    INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    control_implementation_id  INTEGER NOT NULL REFERENCES control_implementations(id) ON DELETE CASCADE,
    effectiveness              VARCHAR(20) NOT NULL DEFAULT 'partial'
                               CHECK (effectiveness IN ('none','partial','substantial','full')),
    notes                      TEXT,
    created_by                 INTEGER REFERENCES users(id),
    created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(risk_id, control_implementation_id)
);
CREATE INDEX IF NOT EXISTS idx_rcl_risk    ON risk_control_links(risk_id);
CREATE INDEX IF NOT EXISTS idx_rcl_control ON risk_control_links(control_implementation_id);

CREATE TABLE IF NOT EXISTS risk_related_links (
    id          SERIAL PRIMARY KEY,
    risk_id     INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    related_id  INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    link_type   VARCHAR(50) NOT NULL DEFAULT 'related'
                CHECK (link_type IN ('related','causes','caused_by','aggregates')),
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(risk_id, related_id)
);
CREATE INDEX IF NOT EXISTS idx_rrl_risk    ON risk_related_links(risk_id);
CREATE INDEX IF NOT EXISTS idx_rrl_related ON risk_related_links(related_id);

CREATE TABLE IF NOT EXISTS risk_treatments (
    id SERIAL PRIMARY KEY,
    risk_id INTEGER REFERENCES risks(id) ON DELETE CASCADE,
    treatment_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    cost_estimate DECIMAL(12,2),
    effort VARCHAR(50),
    due_date DATE,
    status VARCHAR(50) NOT NULL DEFAULT 'planned',
    owner_id INTEGER REFERENCES users(id),
    completion_date DATE,
    completion_notes TEXT,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risk_matrix_config (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT 'Default',
    rows INTEGER NOT NULL DEFAULT 5,
    cols INTEGER NOT NULL DEFAULT 5,
    row_label VARCHAR(50) NOT NULL DEFAULT 'Likelihood',
    col_label VARCHAR(50) NOT NULL DEFAULT 'Impact',
    row_labels JSONB NOT NULL DEFAULT '["Rare","Unlikely","Possible","Likely","Almost Certain"]',
    col_labels JSONB NOT NULL DEFAULT '["Negligible","Minor","Moderate","Major","Critical"]',
    thresholds JSONB NOT NULL DEFAULT '{"low":4,"medium":9,"high":14,"critical":25}',
    colors JSONB NOT NULL DEFAULT '{"low":"#22c55e","medium":"#f59e0b","high":"#f97316","critical":"#ef4444"}',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS workflows (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    trigger_type VARCHAR(100) NOT NULL,
    trigger_config JSONB NOT NULL DEFAULT '{}',
    actions JSONB NOT NULL DEFAULT '[]',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alerts (
    id SERIAL PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    title VARCHAR(500) NOT NULL,
    message TEXT,
    severity VARCHAR(50) NOT NULL DEFAULT 'info',
    user_id INTEGER REFERENCES users(id),
    related_type VARCHAR(100),
    related_id INTEGER,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    read_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alert_configs (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,
    trigger_config JSONB NOT NULL DEFAULT '{}',
    recipients JSONB NOT NULL DEFAULT '[]',
    channels JSONB NOT NULL DEFAULT '["in_app"]',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT,
    type VARCHAR(50) DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activity_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    action VARCHAR(255) NOT NULL,
    entity_type VARCHAR(100),
    entity_id INTEGER,
    changes JSONB,
    ip_address VARCHAR(50),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rate_limits (
    key VARCHAR(255) PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP
);

-- Granular page-level permissions (Migration 021+).
-- module: one of risk, compliance, audit, policy, incident, vendor, issue, asset, change,
--         bcp, threat, awareness, report, kri, ssp, automation, approval
-- permission: one of view, create, edit, delete, accept, review, treatment, scenarios,
--             bowtie, export, assess, import, test, gap, findings, close, publish, attest,
--             playbook, questionnaire, contracts, approve, exercise, manage, record
CREATE TABLE IF NOT EXISTS user_permissions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    module VARCHAR(100) NOT NULL,
    permission VARCHAR(50) NOT NULL,
    granted_by INTEGER REFERENCES users(id),
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, module, permission)
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_co_package   ON compliance_objectives(package_id);
CREATE INDEX IF NOT EXISTS idx_co_parent    ON compliance_objectives(parent_id);
CREATE INDEX IF NOT EXISTS idx_ci_objective ON control_implementations(objective_id);
CREATE INDEX IF NOT EXISTS idx_ci_status    ON control_implementations(status);
CREATE INDEX IF NOT EXISTS idx_ai_audit     ON audit_items(audit_id);
CREATE INDEX IF NOT EXISTS idx_risks_status ON risks(status);

-- ──────────────────────────────────────────────────────────────
-- Core operational tables (incidents, issues, vendors, evidence)
-- ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS incidents (
    id                 SERIAL PRIMARY KEY,
    incident_number    VARCHAR(20) UNIQUE NOT NULL,
    title              VARCHAR(255) NOT NULL,
    description        TEXT,
    severity           VARCHAR(20) NOT NULL DEFAULT 'medium'
                       CHECK (severity IN ('critical','high','medium','low')),
    category           VARCHAR(100),
    status             VARCHAR(20) NOT NULL DEFAULT 'open'
                       CHECK (status IN ('open','investigating','resolved','closed')),
    reported_by        INTEGER REFERENCES users(id),
    assigned_to        INTEGER REFERENCES users(id),
    affected_systems   TEXT,
    impact_description TEXT,
    detected_at        TIMESTAMP,
    resolved_at        TIMESTAMP,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_incidents_status   ON incidents(status);
CREATE INDEX IF NOT EXISTS idx_incidents_severity ON incidents(severity);

CREATE TABLE IF NOT EXISTS incident_updates (
    id          SERIAL PRIMARY KEY,
    incident_id INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id),
    content     TEXT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_iu_incident ON incident_updates(incident_id);

CREATE TABLE IF NOT EXISTS issues (
    id           SERIAL PRIMARY KEY,
    issue_number VARCHAR(20) UNIQUE NOT NULL,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    severity     VARCHAR(20) NOT NULL DEFAULT 'medium'
                 CHECK (severity IN ('critical','high','medium','low')),
    status       VARCHAR(20) NOT NULL DEFAULT 'open'
                 CHECK (status IN ('open','in_progress','resolved','closed')),
    source_type  VARCHAR(100),
    source_id    INTEGER,
    assigned_to  INTEGER REFERENCES users(id),
    created_by   INTEGER REFERENCES users(id),
    due_date     DATE,
    resolved_at  TIMESTAMP,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_issues_status ON issues(status);

CREATE TABLE IF NOT EXISTS issue_updates (
    id         SERIAL PRIMARY KEY,
    issue_id   INTEGER NOT NULL REFERENCES issues(id) ON DELETE CASCADE,
    user_id    INTEGER REFERENCES users(id),
    content    TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vendors (
    id               SERIAL PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    category         VARCHAR(100),
    status           VARCHAR(20) NOT NULL DEFAULT 'active'
                     CHECK (status IN ('active','inactive','under_review')),
    risk_rating      VARCHAR(20) DEFAULT 'medium'
                     CHECK (risk_rating IN ('critical','high','medium','low')),
    contact_name     VARCHAR(255),
    contact_email    VARCHAR(255),
    contact_phone    VARCHAR(50),
    website          VARCHAR(255),
    description      TEXT,
    notes            TEXT,
    owner_id         INTEGER REFERENCES users(id),
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vendors_status ON vendors(status);

CREATE TABLE IF NOT EXISTS vendor_assessments (
    id              SERIAL PRIMARY KEY,
    vendor_id       INTEGER NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
    assessment_type VARCHAR(50) NOT NULL DEFAULT 'security',
    status          VARCHAR(20) NOT NULL DEFAULT 'planned'
                    CHECK (status IN ('planned','in_progress','completed','cancelled')),
    assessed_by     INTEGER REFERENCES users(id),
    scheduled_date  DATE,
    completed_date  DATE,
    score           INTEGER CHECK (score BETWEEN 0 AND 100),
    findings        TEXT,
    recommendations TEXT,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_va_vendor ON vendor_assessments(vendor_id);

CREATE TABLE IF NOT EXISTS evidence (
    id           SERIAL PRIMARY KEY,
    entity_type  VARCHAR(50) NOT NULL,
    entity_id    INTEGER NOT NULL,
    filename     VARCHAR(255) NOT NULL,
    stored_name  VARCHAR(255) NOT NULL,
    file_size    INTEGER,
    mime_type    VARCHAR(100),
    uploaded_by  INTEGER REFERENCES users(id),
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_evidence_entity ON evidence(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_risks_owner  ON risks(owner_id);
CREATE INDEX IF NOT EXISTS idx_pm_policy    ON policy_mappings(policy_id);
CREATE INDEX IF NOT EXISTS idx_pm_objective ON policy_mappings(objective_id);
CREATE INDEX IF NOT EXISTS idx_alerts_user  ON alerts(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_al_user      ON activity_log(user_id);
CREATE INDEX IF NOT EXISTS idx_al_entity    ON activity_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_up_user      ON user_permissions(user_id);

-- Evidence files (replaces the simpler `evidence` table; supports full upload lifecycle)
CREATE TABLE IF NOT EXISTS evidence_files (
    id            SERIAL PRIMARY KEY,
    entity_type   VARCHAR(50) NOT NULL,
    entity_id     INTEGER NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name   VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(100),
    file_size     INTEGER,
    file_hash     VARCHAR(64),
    description   TEXT,
    expires_at    TIMESTAMP,
    uploaded_by   INTEGER REFERENCES users(id),
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ef_entity ON evidence_files(entity_type, entity_id);

-- Notification log (used by data-retention cleanup in AdminController)
CREATE TABLE IF NOT EXISTS notification_log (
    id                  SERIAL PRIMARY KEY,
    notification_type   VARCHAR(100) NOT NULL,
    entity_id           INTEGER,
    recipient_email     VARCHAR(255),
    sent_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_nl_sent_at   ON notification_log(sent_at);
CREATE INDEX IF NOT EXISTS idx_nl_type      ON notification_log(notification_type, entity_id, sent_at);
CREATE INDEX IF NOT EXISTS idx_nl_recipient ON notification_log(recipient_email);

CREATE TABLE IF NOT EXISTS email_templates (
    id          SERIAL PRIMARY KEY,
    type        VARCHAR(100) NOT NULL UNIQUE,
    name        VARCHAR(255) NOT NULL,
    subject     VARCHAR(500) NOT NULL,
    body_html   TEXT NOT NULL,
    body_text   TEXT,
    variables   JSONB NOT NULL DEFAULT '[]',
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    updated_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS report_schedules (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    report_type     VARCHAR(100) NOT NULL,
    frequency       VARCHAR(50) NOT NULL DEFAULT 'weekly'
                    CHECK (frequency IN ('daily','weekly','monthly','quarterly')),
    day_of_week     INTEGER DEFAULT 1,
    day_of_month    INTEGER DEFAULT 1,
    send_time       TIME NOT NULL DEFAULT '08:00',
    recipients      JSONB NOT NULL DEFAULT '[]',
    filters         JSONB NOT NULL DEFAULT '{}',
    format          VARCHAR(10) NOT NULL DEFAULT 'html' CHECK (format IN ('html','csv','both')),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    last_sent_at    TIMESTAMP,
    next_send_at    TIMESTAMP,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(64) NOT NULL UNIQUE,
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_evt_user ON email_verification_tokens(user_id);

CREATE TABLE IF NOT EXISTS email_bounces (
    id          SERIAL PRIMARY KEY,
    email       VARCHAR(255) NOT NULL,
    bounce_type VARCHAR(50) NOT NULL DEFAULT 'hard'
                CHECK (bounce_type IN ('hard','soft','complaint')),
    reason      TEXT,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_eb_email ON email_bounces(email);

CREATE TABLE IF NOT EXISTS email_unsubscribes (
    id                  SERIAL PRIMARY KEY,
    user_id             INTEGER REFERENCES users(id) ON DELETE SET NULL,
    email               VARCHAR(255) NOT NULL,
    token               VARCHAR(64) NOT NULL UNIQUE,
    notification_type   VARCHAR(100),
    unsubscribed_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_eu_email ON email_unsubscribes(email);
CREATE INDEX IF NOT EXISTS idx_eu_token ON email_unsubscribes(token);

CREATE TABLE IF NOT EXISTS risk_reviews (
    id                  SERIAL PRIMARY KEY,
    title               VARCHAR(500) NOT NULL,
    review_type         VARCHAR(50) NOT NULL DEFAULT 'periodic'
                        CHECK (review_type IN ('periodic','triggered','ad_hoc','board')),
    scheduled_date      DATE NOT NULL,
    completed_date      DATE,
    status              VARCHAR(30) NOT NULL DEFAULT 'planned'
                        CHECK (status IN ('planned','in_progress','completed','cancelled')),
    lead_reviewer_id    INTEGER REFERENCES users(id),
    scope_description   TEXT,
    scope_filter        JSONB NOT NULL DEFAULT '{}',
    total_risks         INTEGER NOT NULL DEFAULT 0,
    reviewed_count      INTEGER NOT NULL DEFAULT 0,
    escalated_count     INTEGER NOT NULL DEFAULT 0,
    conclusion          TEXT,
    sign_off_by         INTEGER REFERENCES users(id),
    sign_off_at         TIMESTAMP,
    sign_off_notes      TEXT,
    created_by          INTEGER REFERENCES users(id),
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rr_status    ON risk_reviews(status);
CREATE INDEX IF NOT EXISTS idx_rr_scheduled ON risk_reviews(scheduled_date);

CREATE TABLE IF NOT EXISTS risk_review_items (
    id                  SERIAL PRIMARY KEY,
    review_id           INTEGER NOT NULL REFERENCES risk_reviews(id) ON DELETE CASCADE,
    risk_id             INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    status              VARCHAR(30) NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','reviewed','escalated','deferred','not_applicable')),
    score_confirmed     BOOLEAN,
    new_likelihood      INTEGER CHECK (new_likelihood BETWEEN 1 AND 5),
    new_impact          INTEGER CHECK (new_impact BETWEEN 1 AND 5),
    treatment_adequate  BOOLEAN,
    action_required     TEXT,
    reviewer_notes      TEXT,
    reviewed_by         INTEGER REFERENCES users(id),
    reviewed_at         TIMESTAMP,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(review_id, risk_id)
);
CREATE INDEX IF NOT EXISTS idx_rri_review ON risk_review_items(review_id);
CREATE INDEX IF NOT EXISTS idx_rri_risk   ON risk_review_items(risk_id);

-- ─────────────────────────────────────────────
-- Risk Acceptances
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_acceptances (
    id                       SERIAL PRIMARY KEY,
    risk_id                  INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    accepted_by              INTEGER NOT NULL REFERENCES users(id),
    acceptance_reason        TEXT NOT NULL,
    conditions               TEXT,
    valid_until              DATE NOT NULL,
    status                   VARCHAR(20) NOT NULL DEFAULT 'active'
                             CHECK (status IN ('active','expired','revoked','superseded')),
    risk_score_at_acceptance INTEGER,
    risk_level_at_acceptance VARCHAR(20),
    renewal_required         BOOLEAN NOT NULL DEFAULT FALSE,
    renewed_from             INTEGER REFERENCES risk_acceptances(id),
    revoked_by               INTEGER REFERENCES users(id),
    revoked_at               TIMESTAMP,
    revocation_reason        TEXT,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ra_risk_id ON risk_acceptances(risk_id);
CREATE INDEX IF NOT EXISTS idx_ra_status  ON risk_acceptances(status);

-- ─────────────────────────────────────────────
-- Risk Bowtie – Causes
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_bowtie_causes (
    id                      SERIAL PRIMARY KEY,
    risk_id                 INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    description             TEXT NOT NULL,
    cause_type              VARCHAR(30) NOT NULL DEFAULT 'threat'
                            CHECK (cause_type IN ('threat','vulnerability','hazard','event')),
    likelihood_contribution VARCHAR(10) NOT NULL DEFAULT 'medium'
                            CHECK (likelihood_contribution IN ('low','medium','high')),
    sort_order              INTEGER NOT NULL DEFAULT 0,
    created_by              INTEGER REFERENCES users(id),
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbc_risk_id ON risk_bowtie_causes(risk_id);

-- ─────────────────────────────────────────────
-- Risk Bowtie – Consequences
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_bowtie_consequences (
    id               SERIAL PRIMARY KEY,
    risk_id          INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    description      TEXT NOT NULL,
    consequence_type VARCHAR(30) NOT NULL DEFAULT 'impact'
                     CHECK (consequence_type IN ('financial','operational','reputational','legal','safety','impact')),
    severity         VARCHAR(20) NOT NULL DEFAULT 'medium'
                     CHECK (severity IN ('low','medium','high','critical')),
    sort_order       INTEGER NOT NULL DEFAULT 0,
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbcons_risk_id ON risk_bowtie_consequences(risk_id);

-- ─────────────────────────────────────────────
-- Risk Bowtie – Barriers
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_bowtie_barriers (
    id                          SERIAL PRIMARY KEY,
    risk_id                     INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    side                        VARCHAR(10) NOT NULL CHECK (side IN ('left','right')),
    description                 TEXT NOT NULL,
    barrier_type                VARCHAR(30) NOT NULL DEFAULT 'control'
                                CHECK (barrier_type IN ('control','procedure','training','technology','monitoring')),
    effectiveness               VARCHAR(20) NOT NULL DEFAULT 'partial'
                                CHECK (effectiveness IN ('degraded','partial','substantial','full')),
    control_implementation_id   INTEGER REFERENCES control_implementations(id) ON DELETE SET NULL,
    sort_order                  INTEGER NOT NULL DEFAULT 0,
    created_by                  INTEGER REFERENCES users(id),
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rbb_risk_id ON risk_bowtie_barriers(risk_id);

-- ─────────────────────────────────────────────
-- Risk Scenarios
-- ─────────────────────────────────────────────
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
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_rs_risk_id ON risk_scenarios(risk_id);

-- ─────────────────────────────────────────────
-- Branding settings (Settings → Branding)
-- Display name, logo (URL or data: URI) and primary accent colour.
-- Empty defaults keep the UI on the built-in AEGIS mark/name/accent until set.
-- ─────────────────────────────────────────────
INSERT INTO settings (key, value, type, description) VALUES
    ('org_name',           'My Organization', 'string', 'Organization / product display name (Branding)'),
    ('company_logo_data',  '',                'string', 'Logo source — http(s):// URL or data:image/... URI (Branding)'),
    ('company_logo_name',  '',                'string', 'Original logo filename / label (Branding)'),
    ('brand_accent',       '',                'string', 'Primary brand accent colour as #RRGGBB hex (Branding)')
ON CONFLICT (key) DO NOTHING;

