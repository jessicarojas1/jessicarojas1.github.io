-- AEGIS GRC — Phase 3 Migration
-- Run after 002_phase2.sql

-- ─────────────────────────────────────────────
-- 3.1  Webhook endpoints & delivery log
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS webhook_endpoints (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(255) NOT NULL,
    url            TEXT NOT NULL,
    secret         VARCHAR(255),                          -- HMAC signing key
    event_types    JSONB NOT NULL DEFAULT '[]',           -- ["risk.created","incident.created",...]
    provider       VARCHAR(50) NOT NULL DEFAULT 'generic',-- generic|slack|jira|pagerduty|servicenow
    custom_headers JSONB NOT NULL DEFAULT '{}',
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    created_by     INTEGER REFERENCES users(id),
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id             SERIAL PRIMARY KEY,
    endpoint_id    INTEGER NOT NULL REFERENCES webhook_endpoints(id) ON DELETE CASCADE,
    event_type     VARCHAR(100) NOT NULL,
    payload        JSONB NOT NULL,
    status         VARCHAR(50) NOT NULL DEFAULT 'pending', -- pending|delivered|failed
    attempts       SMALLINT NOT NULL DEFAULT 0,
    response_code  SMALLINT,
    response_body  TEXT,
    next_retry_at  TIMESTAMP,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at   TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wd_status_retry    ON webhook_deliveries(status, next_retry_at);
CREATE INDEX IF NOT EXISTS idx_wd_endpoint        ON webhook_deliveries(endpoint_id);

-- ─────────────────────────────────────────────
-- 3.2  Questionnaires
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS questionnaires (
    id          SERIAL PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    entity_type VARCHAR(100) NOT NULL DEFAULT 'general', -- general|vendor|audit
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS questionnaire_questions (
    id                SERIAL PRIMARY KEY,
    questionnaire_id  INTEGER NOT NULL REFERENCES questionnaires(id) ON DELETE CASCADE,
    section           VARCHAR(255) NOT NULL DEFAULT 'General',
    question_text     TEXT NOT NULL,
    question_type     VARCHAR(50) NOT NULL DEFAULT 'text', -- text|scale|boolean|choice|multiselect
    options           JSONB,                               -- for choice/multiselect: ["opt1","opt2"]
    weight            SMALLINT NOT NULL DEFAULT 1,
    is_required       BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order        INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_qq_questionnaire ON questionnaire_questions(questionnaire_id);

CREATE TABLE IF NOT EXISTS questionnaire_assignments (
    id               SERIAL PRIMARY KEY,
    questionnaire_id INTEGER NOT NULL REFERENCES questionnaires(id),
    entity_type      VARCHAR(100),
    entity_id        INTEGER,
    assigned_to      INTEGER REFERENCES users(id),
    due_date         DATE,
    status           VARCHAR(50) NOT NULL DEFAULT 'pending', -- pending|in_progress|submitted|reviewed
    created_by       INTEGER REFERENCES users(id),
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS questionnaire_responses (
    id             SERIAL PRIMARY KEY,
    assignment_id  INTEGER NOT NULL REFERENCES questionnaire_assignments(id) ON DELETE CASCADE,
    submitted_by   INTEGER REFERENCES users(id),
    submitted_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

-- ─────────────────────────────────────────────
-- 3.3  Change management
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS change_requests (
    id                  SERIAL PRIMARY KEY,
    title               VARCHAR(255) NOT NULL,
    description         TEXT NOT NULL,
    change_type         VARCHAR(50) NOT NULL DEFAULT 'normal',   -- normal|emergency|standard
    status              VARCHAR(50) NOT NULL DEFAULT 'draft',    -- draft|submitted|under_review|approved|rejected|implementing|implemented|closed
    risk_level          VARCHAR(50) NOT NULL DEFAULT 'medium',   -- low|medium|high|critical
    implementation_date TIMESTAMP,
    rollback_plan       TEXT,
    impact_analysis     TEXT,
    testing_plan        TEXT,
    submitter_id        INTEGER NOT NULL REFERENCES users(id),
    cab_reviewer_id     INTEGER REFERENCES users(id),
    reviewed_at         TIMESTAMP,
    review_notes        TEXT,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_cr_status      ON change_requests(status);
CREATE INDEX IF NOT EXISTS idx_cr_submitter   ON change_requests(submitter_id);

CREATE TABLE IF NOT EXISTS change_request_updates (
    id          SERIAL PRIMARY KEY,
    change_id   INTEGER NOT NULL REFERENCES change_requests(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id),
    content     TEXT NOT NULL,
    update_type VARCHAR(50) NOT NULL DEFAULT 'comment', -- comment|status_change
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────
-- 3.4  Business Continuity / Disaster Recovery
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bcp_plans (
    id             SERIAL PRIMARY KEY,
    title          VARCHAR(255) NOT NULL,
    description    TEXT,
    version        VARCHAR(50) NOT NULL DEFAULT '1.0',
    status         VARCHAR(50) NOT NULL DEFAULT 'draft', -- draft|active|archived
    owner_id       INTEGER REFERENCES users(id),
    rto_hours      INTEGER,
    rpo_hours      INTEGER,
    last_tested    DATE,
    next_test_date DATE,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bcp_plan_sections (
    id           SERIAL PRIMARY KEY,
    plan_id      INTEGER NOT NULL REFERENCES bcp_plans(id) ON DELETE CASCADE,
    section_type VARCHAR(100) NOT NULL, -- scope|threats|procedures|contacts|recovery|dependencies
    title        VARCHAR(255) NOT NULL,
    content      TEXT,
    sort_order   INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bcp_exercises (
    id              SERIAL PRIMARY KEY,
    plan_id         INTEGER NOT NULL REFERENCES bcp_plans(id) ON DELETE CASCADE,
    exercise_type   VARCHAR(100) NOT NULL DEFAULT 'tabletop', -- tabletop|walkthrough|full_scale
    name            VARCHAR(255) NOT NULL,
    scheduled_date  DATE,
    conducted_date  DATE,
    outcome         VARCHAR(50),  -- passed|passed_with_findings|failed|cancelled
    findings        TEXT,
    lessons_learned TEXT,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────
-- 3.5  Asset inventory
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assets (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(255) NOT NULL,
    asset_type     VARCHAR(100) NOT NULL DEFAULT 'server',      -- server|workstation|application|database|network|cloud|mobile|iot|saas
    criticality    VARCHAR(50) NOT NULL DEFAULT 'medium',        -- critical|high|medium|low
    classification VARCHAR(50) NOT NULL DEFAULT 'internal',      -- public|internal|confidential|restricted
    status         VARCHAR(50) NOT NULL DEFAULT 'active',        -- active|decommissioned|maintenance
    owner_id       INTEGER REFERENCES users(id),
    location       VARCHAR(255),
    ip_address     INET,
    hostname       VARCHAR(255),
    vendor         VARCHAR(255),
    version        VARCHAR(100),
    last_scanned   DATE,
    last_reviewed  DATE,
    tags           JSONB NOT NULL DEFAULT '[]',
    description    TEXT,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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

-- Scanner ingestion support: track external finding IDs on risks
ALTER TABLE risks ADD COLUMN IF NOT EXISTS source              VARCHAR(100);
ALTER TABLE risks ADD COLUMN IF NOT EXISTS source_external_id  VARCHAR(500);
CREATE INDEX IF NOT EXISTS idx_risks_source_ext ON risks(source_external_id) WHERE source_external_id IS NOT NULL;

-- Risk Exception / Acceptance Management
CREATE TABLE IF NOT EXISTS risk_exceptions (
    id SERIAL PRIMARY KEY,
    risk_id INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    requested_by INTEGER NOT NULL REFERENCES users(id),
    approved_by INTEGER REFERENCES users(id),
    status VARCHAR(30) NOT NULL DEFAULT 'pending', -- pending, approved, rejected, expired
    exception_type VARCHAR(30) NOT NULL DEFAULT 'accept', -- accept, transfer, defer
    rationale TEXT NOT NULL,
    compensating_controls TEXT,
    residual_risk_acknowledged BOOLEAN NOT NULL DEFAULT FALSE,
    expiry_date DATE,
    approved_at TIMESTAMP,
    rejected_at TIMESTAMP,
    rejection_reason TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_risk_exceptions_risk_id ON risk_exceptions(risk_id);
CREATE INDEX IF NOT EXISTS idx_risk_exceptions_status ON risk_exceptions(status);
CREATE INDEX IF NOT EXISTS idx_risk_exceptions_expiry ON risk_exceptions(expiry_date) WHERE expiry_date IS NOT NULL;

-- ─────────────────────────────────────────────
-- 3.6  Data Retention Policies
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS data_retention_policies (
    id             SERIAL PRIMARY KEY,
    entity_type    VARCHAR(100) NOT NULL UNIQUE,
    retention_days INTEGER NOT NULL DEFAULT 365,
    action         VARCHAR(30) NOT NULL DEFAULT 'delete',
    is_enabled     BOOLEAN NOT NULL DEFAULT FALSE,
    last_run_at    TIMESTAMP,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO data_retention_policies (entity_type, retention_days, action, is_enabled) VALUES
    ('activity_log',       365, 'delete', FALSE),
    ('notification_log',    90, 'delete', FALSE),
    ('webhook_deliveries', 180, 'delete', FALSE),
    ('alerts',              90, 'delete', FALSE)
ON CONFLICT (entity_type) DO NOTHING;

-- ─────────────────────────────────────────────
-- 3.7  Active Session Tracking
-- ─────────────────────────────────────────────
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

-- ─────────────────────────────────────────────
-- 3.8  Password Reset Tokens
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used       BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token_hash);

-- ─────────────────────────────────────────────
-- 3.9  Vendor External Assessment Tokens
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_portal_tokens (
    id SERIAL PRIMARY KEY,
    vendor_id INTEGER NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL DEFAULT 'Vendor Self-Assessment',
    questions JSONB NOT NULL DEFAULT '[]',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP,
    response JSONB,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vpt_vendor ON vendor_portal_tokens(vendor_id);
CREATE INDEX IF NOT EXISTS idx_vpt_token ON vendor_portal_tokens(token_hash);

-- ─────────────────────────────────────────────
-- 3.10  Tags & Entity Tags
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tags (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(50) NOT NULL UNIQUE,
    color      VARCHAR(7)  NOT NULL DEFAULT '#6366f1',
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS entity_tags (
    id          SERIAL PRIMARY KEY,
    tag_id      INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    entity_type VARCHAR(30) NOT NULL,
    entity_id   INTEGER NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_entity_tag UNIQUE (tag_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_entity_tags_entity ON entity_tags(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_entity_tags_tag ON entity_tags(tag_id);

-- ─────────────────────────────────────────────
-- 3.11  Policy Attestation
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS policy_attestations (
    id          SERIAL PRIMARY KEY,
    policy_id   INTEGER NOT NULL REFERENCES policies(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    attested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address  VARCHAR(45),
    notes       TEXT,
    CONSTRAINT uq_policy_attestation UNIQUE (policy_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_pa_policy ON policy_attestations(policy_id);
CREATE INDEX IF NOT EXISTS idx_pa_user ON policy_attestations(user_id);

CREATE TABLE IF NOT EXISTS policy_attestation_campaigns (
    id           SERIAL PRIMARY KEY,
    policy_id    INTEGER NOT NULL REFERENCES policies(id) ON DELETE CASCADE,
    title        VARCHAR(255) NOT NULL,
    due_date     DATE,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ─────────────────────────────────────────────
-- 3.12 Risk Appetite
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS risk_appetite (
    id           SERIAL PRIMARY KEY,
    category     VARCHAR(100) NOT NULL,
    appetite     VARCHAR(20)  NOT NULL CHECK (appetite IN ('zero','low','moderate','high')),
    statement    TEXT         NOT NULL,
    max_score    INTEGER,
    updated_by   INTEGER REFERENCES users(id),
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO risk_appetite (category, appetite, statement, max_score)
VALUES
  ('Operational',  'low',      'We accept minimal operational disruption. Controls must reduce residual risk to Low or lower.', 6),
  ('Financial',    'low',      'Financial losses above $50,000 are unacceptable without board approval.', 6),
  ('Reputational', 'zero',     'Reputational risks are not tolerated. Any risk that could harm public trust must be mitigated to residual score ≤ 3.', 3),
  ('Regulatory',   'zero',     'Compliance violations carry zero tolerance. All regulatory requirements must be met.', 2),
  ('Strategic',    'moderate', 'Moderate strategic risk is acceptable when pursuing growth objectives with documented rationale.', 12),
  ('Technology',   'low',      'Technology risks must be mitigated to Low. Critical system downtime tolerance is < 4 hours RTO.', 6)
ON CONFLICT DO NOTHING;

-- ─────────────────────────────────────────────
-- 3.13 Control Testing
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS control_tests (
    id               SERIAL PRIMARY KEY,
    objective_id     INTEGER NOT NULL REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    package_id       INTEGER NOT NULL REFERENCES compliance_packages(id) ON DELETE CASCADE,
    test_date        DATE    NOT NULL DEFAULT CURRENT_DATE,
    tester_id        INTEGER REFERENCES users(id),
    result           VARCHAR(20) NOT NULL CHECK (result IN ('pass','fail','partial','not_tested')),
    effectiveness    INTEGER CHECK (effectiveness BETWEEN 0 AND 100),
    method           VARCHAR(50),
    findings         TEXT,
    evidence_refs    TEXT,
    next_test_date   DATE,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ct_objective ON control_tests(objective_id);
CREATE INDEX IF NOT EXISTS idx_ct_package ON control_tests(package_id);

-- ─────────────────────────────────────────────
-- 3.14 Incident Response Playbooks
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS playbooks (
    id              SERIAL PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    category        VARCHAR(50)  NOT NULL DEFAULT 'general',
    severity_filter VARCHAR(20),
    description     TEXT,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS playbook_steps (
    id            SERIAL PRIMARY KEY,
    playbook_id   INTEGER NOT NULL REFERENCES playbooks(id) ON DELETE CASCADE,
    step_number   INTEGER NOT NULL,
    title         VARCHAR(255) NOT NULL,
    description   TEXT,
    owner_role    VARCHAR(50),
    due_minutes   INTEGER,
    sort_order    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ps_playbook ON playbook_steps(playbook_id);

CREATE TABLE IF NOT EXISTS incident_playbook_runs (
    id            SERIAL PRIMARY KEY,
    incident_id   INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    playbook_id   INTEGER NOT NULL REFERENCES playbooks(id),
    started_by    INTEGER REFERENCES users(id),
    started_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at  TIMESTAMP,
    CONSTRAINT uq_incident_playbook UNIQUE (incident_id, playbook_id)
);

CREATE TABLE IF NOT EXISTS playbook_step_completions (
    id          SERIAL PRIMARY KEY,
    run_id      INTEGER NOT NULL REFERENCES incident_playbook_runs(id) ON DELETE CASCADE,
    step_id     INTEGER NOT NULL REFERENCES playbook_steps(id) ON DELETE CASCADE,
    completed_by INTEGER REFERENCES users(id),
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes       TEXT,
    CONSTRAINT uq_run_step UNIQUE (run_id, step_id)
);
CREATE INDEX IF NOT EXISTS idx_psc_run ON playbook_step_completions(run_id);

-- ─────────────────────────────────────────────
-- 3.15 Vendor Contracts
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS vendor_contracts (
    id              SERIAL PRIMARY KEY,
    vendor_id       INTEGER NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
    title           VARCHAR(255) NOT NULL,
    contract_number VARCHAR(100),
    status          VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('draft','active','expired','terminated')),
    value           NUMERIC(15,2),
    currency        VARCHAR(3) NOT NULL DEFAULT 'USD',
    start_date      DATE NOT NULL,
    end_date        DATE,
    auto_renewal    BOOLEAN NOT NULL DEFAULT FALSE,
    renewal_notice_days INTEGER DEFAULT 30,
    description     TEXT,
    owner_id        INTEGER REFERENCES users(id),
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_vc_vendor ON vendor_contracts(vendor_id);
CREATE INDEX IF NOT EXISTS idx_vc_end_date ON vendor_contracts(end_date);

-- ─────────────────────────────────────────────
-- 3.16 Threat Register
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS threats (
    id              SERIAL PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    category        VARCHAR(30)  NOT NULL DEFAULT 'technology'
                    CHECK (category IN ('people','process','technology','natural','regulatory','financial')),
    description     TEXT,
    likelihood      INTEGER      CHECK (likelihood BETWEEN 1 AND 5),
    impact          INTEGER      CHECK (impact BETWEEN 1 AND 5),
    status          VARCHAR(20)  NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active','mitigated','accepted','retired')),
    source          VARCHAR(255),
    mitigations     TEXT,
    owner_id        INTEGER REFERENCES users(id),
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_threats_category ON threats(category);
CREATE INDEX IF NOT EXISTS idx_threats_status ON threats(status);

CREATE TABLE IF NOT EXISTS threat_risk_links (
    id         SERIAL PRIMARY KEY,
    threat_id  INTEGER NOT NULL REFERENCES threats(id) ON DELETE CASCADE,
    risk_id    INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_threat_risk UNIQUE (threat_id, risk_id)
);
CREATE INDEX IF NOT EXISTS idx_trl_threat ON threat_risk_links(threat_id);
CREATE INDEX IF NOT EXISTS idx_trl_risk ON threat_risk_links(risk_id);

-- ─────────────────────────────────────────────
-- 3.17 Risk Treatment Plans
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS treatment_plans (
    id           SERIAL PRIMARY KEY,
    risk_id      INTEGER NOT NULL REFERENCES risks(id) ON DELETE CASCADE,
    title        VARCHAR(255) NOT NULL,
    strategy     VARCHAR(20)  NOT NULL DEFAULT 'mitigate'
                 CHECK (strategy IN ('mitigate','transfer','accept','avoid')),
    target_score INTEGER,
    owner_id     INTEGER REFERENCES users(id),
    start_date   DATE,
    target_date  DATE,
    status       VARCHAR(20)  NOT NULL DEFAULT 'draft'
                 CHECK (status IN ('draft','active','completed','cancelled')),
    description  TEXT,
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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
    sort_order   INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_tm_plan ON treatment_milestones(plan_id);

-- ─────────────────────────────────────────────
-- 3.18 Key Risk Indicators
-- ─────────────────────────────────────────────
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
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      INTEGER REFERENCES users(id),
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS kri_values (
    id          SERIAL PRIMARY KEY,
    kri_id      INTEGER NOT NULL REFERENCES kris(id) ON DELETE CASCADE,
    value       NUMERIC(15,4) NOT NULL,
    recorded_at DATE NOT NULL DEFAULT CURRENT_DATE,
    notes       TEXT,
    recorded_by INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_kv_kri ON kri_values(kri_id);
CREATE INDEX IF NOT EXISTS idx_kv_recorded ON kri_values(recorded_at);

-- ─────────────────────────────────────────────
-- 3.19 MFA Backup Codes
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mfa_backup_codes (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash   VARCHAR(255) NOT NULL,
    used_at     TIMESTAMP,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mbc_user ON mfa_backup_codes(user_id);

-- ─────────────────────────────────────────────
-- 3.20 Incident SLAs
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS incident_sla_policies (
    id                    SERIAL PRIMARY KEY,
    severity              VARCHAR(20) NOT NULL UNIQUE,
    acknowledge_hours     INTEGER NOT NULL DEFAULT 4,
    resolve_hours         INTEGER NOT NULL DEFAULT 72,
    escalate_hours        INTEGER,
    is_active             BOOLEAN NOT NULL DEFAULT TRUE
);
INSERT INTO incident_sla_policies (severity, acknowledge_hours, resolve_hours, escalate_hours) VALUES
    ('critical', 1,  24,  8),
    ('high',     4,  72,  24),
    ('medium',   8,  168, NULL),
    ('low',      24, 336, NULL)
ON CONFLICT (severity) DO NOTHING;

CREATE TABLE IF NOT EXISTS incident_sla_events (
    id           SERIAL PRIMARY KEY,
    incident_id  INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    event_type   VARCHAR(30) NOT NULL CHECK (event_type IN ('acknowledged','resolved','escalated','breach')),
    occurred_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by  INTEGER REFERENCES users(id),
    notes        TEXT
);
CREATE INDEX IF NOT EXISTS idx_sla_incident ON incident_sla_events(incident_id);
