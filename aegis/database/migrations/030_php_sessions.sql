-- Migration 030 — Shared session store (horizontal scaling)
--
-- Backing table for the optional Postgres session handler (src/PgSessionHandler.php,
-- enabled with SESSION_DRIVER=pg). Lets multiple app instances behind a load
-- balancer share sessions instead of pinning users to one instance's local files.
--
-- This is a SYSTEM table: deliberately NO tenant_id and NO row-level security —
-- the handler runs at session_start(), before authentication binds a tenant, so
-- RLS here would filter out the session being read. Session payloads are
-- base64-encoded into TEXT by the handler (binary-safe). Idempotent.

CREATE TABLE IF NOT EXISTS php_sessions (
    id          VARCHAR(128) PRIMARY KEY,
    data        TEXT        NOT NULL DEFAULT '',
    expires_at  TIMESTAMPTZ NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- gc() deletes expired rows; index the predicate.
CREATE INDEX IF NOT EXISTS idx_php_sessions_expires ON php_sessions(expires_at);
