-- ============================================================================
-- Migration 012 — Personal Access Tokens, Webhooks & Retention Rules
-- Idempotent. Part of the combined schema (see database/schema.sql).
-- ============================================================================

-- ── Personal Access Tokens (per-user API credentials) ──────────────────────
CREATE TABLE IF NOT EXISTS personal_access_tokens (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name         VARCHAR(120) NOT NULL,
    token_prefix VARCHAR(16) NOT NULL,
    token_hash   VARCHAR(128) NOT NULL,
    scopes       VARCHAR(120) NOT NULL DEFAULT 'read',  -- comma-sep: read,write
    last_used    TIMESTAMP,
    expires_at   TIMESTAMP,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pat_user ON personal_access_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_pat_hash ON personal_access_tokens(token_hash);

-- ── Webhooks (outbound HTTP callbacks on platform events) ───────────────────
CREATE TABLE IF NOT EXISTS webhooks (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(160) NOT NULL,
    url           TEXT NOT NULL,
    secret        VARCHAR(128),
    events        TEXT NOT NULL DEFAULT '*',  -- comma-sep event keys, or '*' for all
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    last_status   INTEGER,
    last_fired_at TIMESTAMP,
    failure_count INTEGER NOT NULL DEFAULT 0,
    created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id          SERIAL PRIMARY KEY,
    webhook_id  INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event       VARCHAR(80) NOT NULL,
    status_code INTEGER,
    success     BOOLEAN NOT NULL DEFAULT FALSE,
    error       TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_wh_deliv ON webhook_deliveries(webhook_id, created_at DESC);

-- ── Retention rules (auto-archive controlled content by age) ────────────────
CREATE TABLE IF NOT EXISTS retention_rules (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(160) NOT NULL,
    content_type  VARCHAR(20) NOT NULL DEFAULT 'document', -- document | page
    space_id      INTEGER REFERENCES spaces(id) ON DELETE CASCADE, -- NULL = all spaces
    doc_type      VARCHAR(40),                              -- optional document type filter
    age_days      INTEGER NOT NULL DEFAULT 365,             -- inactivity threshold
    action        VARCHAR(20) NOT NULL DEFAULT 'archive',   -- archive | notify
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    last_run_at   TIMESTAMP,
    last_affected INTEGER NOT NULL DEFAULT 0,
    created_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_retention_active ON retention_rules(is_active);
