-- AEGIS GRC — Migration 016: External Audit Findings & Automation Rules Engine
-- Safe to re-run (all statements use IF NOT EXISTS)

-- ─────────────────────────────────────────────
-- Feature 1: External Audit Findings
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS aegis.audit_findings (
    id               SERIAL PRIMARY KEY,
    finding_number   VARCHAR(20) UNIQUE NOT NULL,
    title            VARCHAR(255) NOT NULL,
    description      TEXT,
    severity         VARCHAR(20) DEFAULT 'medium',   -- critical/high/medium/low/info
    status           VARCHAR(30) DEFAULT 'open',     -- open/in_progress/resolved/risk_accepted/closed
    source           VARCHAR(50) DEFAULT 'external_audit', -- external_audit/pentest/certification/assessment/regulatory/other
    audit_name       VARCHAR(255),
    auditor_name     VARCHAR(255),
    objective_id     INTEGER REFERENCES aegis.compliance_objectives(id) ON DELETE SET NULL,
    package_id       INTEGER REFERENCES aegis.compliance_packages(id) ON DELETE SET NULL,
    owner_id         INTEGER REFERENCES aegis.users(id),
    deadline         DATE,
    response_notes   TEXT,
    closed_at        TIMESTAMP,
    created_by       INTEGER REFERENCES aegis.users(id),
    created_at       TIMESTAMP DEFAULT NOW(),
    updated_at       TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aegis.finding_updates (
    id           SERIAL PRIMARY KEY,
    finding_id   INTEGER NOT NULL REFERENCES aegis.audit_findings(id) ON DELETE CASCADE,
    user_id      INTEGER REFERENCES aegis.users(id),
    content      TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_findings_status   ON aegis.audit_findings(status);
CREATE INDEX IF NOT EXISTS idx_audit_findings_severity ON aegis.audit_findings(severity);
CREATE INDEX IF NOT EXISTS idx_finding_updates         ON aegis.finding_updates(finding_id);

-- ─────────────────────────────────────────────
-- Feature 2: Automation Rules Engine
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS aegis.automation_rules (
    id                SERIAL PRIMARY KEY,
    name              VARCHAR(255) NOT NULL,
    description       TEXT,
    trigger_type      VARCHAR(50) NOT NULL,
    trigger_config    JSONB DEFAULT '{}',
    action_type       VARCHAR(50) NOT NULL,
    action_config     JSONB DEFAULT '{}',
    is_active         BOOLEAN DEFAULT TRUE,
    last_triggered_at TIMESTAMP,
    trigger_count     INTEGER DEFAULT 0,
    created_by        INTEGER REFERENCES aegis.users(id),
    created_at        TIMESTAMP DEFAULT NOW(),
    updated_at        TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aegis.automation_logs (
    id           SERIAL PRIMARY KEY,
    rule_id      INTEGER NOT NULL REFERENCES aegis.automation_rules(id) ON DELETE CASCADE,
    triggered_at TIMESTAMP DEFAULT NOW(),
    status       VARCHAR(20) DEFAULT 'success',
    details      TEXT
);

CREATE INDEX IF NOT EXISTS idx_automation_rules_active ON aegis.automation_rules(is_active);
CREATE INDEX IF NOT EXISTS idx_automation_logs_rule    ON aegis.automation_logs(rule_id);
