-- AEGIS GRC — Phase 1 Enterprise Migration
-- Run once after deploying Phase 1 code.
-- Safe to re-run (all statements use IF NOT EXISTS / IF EXISTS).

-- ─────────────────────────────────────────────
-- 1.1  Tamper-evident audit log hash chain
-- ─────────────────────────────────────────────
ALTER TABLE activity_log ADD COLUMN IF NOT EXISTS log_hash VARCHAR(64);
ALTER TABLE activity_log ADD COLUMN IF NOT EXISTS user_agent VARCHAR(500);

-- Back-fill existing rows with a placeholder so the chain can start fresh
UPDATE activity_log SET log_hash = 'genesis' WHERE log_hash IS NULL;

CREATE INDEX IF NOT EXISTS idx_al_created ON activity_log(created_at);

-- ─────────────────────────────────────────────
-- 1.2  SSO user linking
-- ─────────────────────────────────────────────
ALTER TABLE users ADD COLUMN IF NOT EXISTS sso_provider  VARCHAR(100);
ALTER TABLE users ADD COLUMN IF NOT EXISTS sso_subject   VARCHAR(500);
ALTER TABLE users ADD COLUMN IF NOT EXISTS sso_only      BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS mfa_secret    VARCHAR(255);
ALTER TABLE users ADD COLUMN IF NOT EXISTS mfa_enabled   BOOLEAN NOT NULL DEFAULT FALSE;

CREATE UNIQUE INDEX IF NOT EXISTS idx_users_sso ON users(sso_provider, sso_subject)
    WHERE sso_provider IS NOT NULL AND sso_subject IS NOT NULL;

-- ─────────────────────────────────────────────
-- 1.3  Workflow execution tracking
-- ─────────────────────────────────────────────
ALTER TABLE workflows ADD COLUMN IF NOT EXISTS last_triggered_at TIMESTAMP;
ALTER TABLE workflows ADD COLUMN IF NOT EXISTS cooldown_seconds  INTEGER NOT NULL DEFAULT 3600;

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

-- ─────────────────────────────────────────────
-- 1.4  Approval chains
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS approval_templates (
    id           SERIAL PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    description  TEXT,
    entity_type  VARCHAR(100) NOT NULL,  -- risk | policy | audit | vendor | incident
    trigger_condition JSONB NOT NULL DEFAULT '{}',
    -- e.g. {"min_score": 15} for risks, {"status_change": "published"} for policies
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_by   INTEGER REFERENCES users(id),
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS approval_template_steps (
    id              SERIAL PRIMARY KEY,
    template_id     INTEGER NOT NULL REFERENCES approval_templates(id) ON DELETE CASCADE,
    step_number     INTEGER NOT NULL,
    label           VARCHAR(255) NOT NULL,
    required_role   VARCHAR(50),          -- admin | manager | auditor (any user with this role)
    required_user_id INTEGER REFERENCES users(id),  -- specific named approver
    allow_delegation BOOLEAN NOT NULL DEFAULT TRUE,
    due_hours       INTEGER NOT NULL DEFAULT 48,    -- SLA: hours before escalation
    UNIQUE (template_id, step_number)
);

CREATE TABLE IF NOT EXISTS approval_requests (
    id            SERIAL PRIMARY KEY,
    template_id   INTEGER NOT NULL REFERENCES approval_templates(id),
    entity_type   VARCHAR(100) NOT NULL,
    entity_id     INTEGER NOT NULL,
    requested_by  INTEGER NOT NULL REFERENCES users(id),
    current_step  INTEGER NOT NULL DEFAULT 1,
    status        VARCHAR(50) NOT NULL DEFAULT 'pending',
    -- pending | approved | rejected | withdrawn
    completed_at  TIMESTAMP,
    context_data  JSONB,   -- snapshot of entity state at time of request
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS approval_request_steps (
    id           SERIAL PRIMARY KEY,
    request_id   INTEGER NOT NULL REFERENCES approval_requests(id) ON DELETE CASCADE,
    step_number  INTEGER NOT NULL,
    label        VARCHAR(255) NOT NULL,
    required_role    VARCHAR(50),
    required_user_id INTEGER REFERENCES users(id),
    actioned_by  INTEGER REFERENCES users(id),
    decision     VARCHAR(50),    -- approved | rejected | delegated
    notes        TEXT,
    due_at       TIMESTAMP,
    actioned_at  TIMESTAMP,
    UNIQUE (request_id, step_number)
);

CREATE INDEX IF NOT EXISTS idx_ar_entity   ON approval_requests(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_ar_status   ON approval_requests(status);
CREATE INDEX IF NOT EXISTS idx_ars_request ON approval_request_steps(request_id);
