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
