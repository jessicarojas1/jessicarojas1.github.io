-- 038_promote_runtime_schema.sql
-- Promote schema that previously existed ONLY as runtime guards in index.php
-- (the `if ($__runMigrations)` block) into a proper migration, so a database
-- built purely from install.php / migrations (fresh install, CI, cron-only
-- contexts that never hit the web front controller) is complete. Same class of
-- gap as the fixed migration 037 (kris.direction). Surfaced by the Phase 21
-- audit (OPEN_ITEMS TD-1a). Fully idempotent — every statement is guarded.
--
-- NOTE: `change_requests` is intentionally dropped by migration 032 (removed
-- module); its runtime ALTER is dead code and is deliberately NOT reproduced
-- here. Self-contained CREATE TABLEs come first so a later ALTER can never abort
-- them.

-- ── Tables (self-contained; ordered first) ──────────────────────────────────
-- TOTP replay guard (90s window)
CREATE TABLE IF NOT EXISTS totp_used_codes (
    id             SERIAL PRIMARY KEY,
    user_id        INTEGER NOT NULL,
    window_counter BIGINT  NOT NULL,
    used_at        TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(user_id, window_counter)
);

-- AI inference audit log (ISO 42001 governance)
CREATE TABLE IF NOT EXISTS ai_inference_log (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    provider    VARCHAR(50),
    model       VARCHAR(100),
    action      VARCHAR(100),
    input_hash  VARCHAR(64),
    tokens_used INTEGER,
    duration_ms INTEGER,
    success     BOOLEAN NOT NULL DEFAULT TRUE,
    error_msg   TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Password-reuse history (ISO 27001 A.9.4.3) — backs ProfileController::changePassword
CREATE TABLE IF NOT EXISTS password_history (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ── Columns (on tables present after migrations) ─────────────────────────────
ALTER TABLE assets                ADD COLUMN IF NOT EXISTS created_by INTEGER REFERENCES users(id);
ALTER TABLE compliance_objectives ADD COLUMN IF NOT EXISTS additional_information TEXT;

ALTER TABLE users ADD COLUMN IF NOT EXISTS sessions_revoked_at TIMESTAMP;
ALTER TABLE users ADD COLUMN IF NOT EXISTS force_password_change BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMP;

ALTER TABLE incidents ADD COLUMN IF NOT EXISTS phi_involved BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE incidents ADD COLUMN IF NOT EXISTS breach_notification_required BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE incidents ADD COLUMN IF NOT EXISTS breach_notification_sent_at TIMESTAMP;
ALTER TABLE incidents ADD COLUMN IF NOT EXISTS root_cause TEXT;

ALTER TABLE issues ADD COLUMN IF NOT EXISTS resolution TEXT;

ALTER TABLE audit_findings ADD COLUMN IF NOT EXISTS audit_id INTEGER REFERENCES audits(id) ON DELETE SET NULL;

-- ── Widened status CHECK constraints (drop-then-add is idempotent) ───────────
ALTER TABLE incidents DROP CONSTRAINT IF EXISTS incidents_status_check;
ALTER TABLE incidents ADD  CONSTRAINT incidents_status_check
    CHECK (status IN ('open','investigating','contained','resolved','closed'));

ALTER TABLE issues DROP CONSTRAINT IF EXISTS issues_status_check;
ALTER TABLE issues ADD  CONSTRAINT issues_status_check
    CHECK (status IN ('open','in_progress','pending_review','resolved','closed','wont_fix','reopened'));
