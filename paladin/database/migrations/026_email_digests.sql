-- ============================================================================
-- Migration 026 — Email digests
-- Per-user digest opt-in and a mail outbox. The outbox records every message
-- the app generates; a configured SMTP transport delivers it (env MAIL_*),
-- otherwise messages stay 'queued' so the pipeline is fully functional and
-- inspectable without mail credentials. Idempotent.
-- ============================================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS digest_frequency VARCHAR(10) NOT NULL DEFAULT 'off'; -- off/daily/weekly
ALTER TABLE users ADD COLUMN IF NOT EXISTS digest_last_sent_at TIMESTAMP;

CREATE TABLE IF NOT EXISTS mail_outbox (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    to_email    VARCHAR(255) NOT NULL,
    subject     VARCHAR(255) NOT NULL,
    body_html   TEXT,
    body_text   TEXT,
    transport   VARCHAR(20)  NOT NULL DEFAULT 'queued', -- queued/smtp/mail
    status      VARCHAR(20)  NOT NULL DEFAULT 'queued',  -- queued/sent/failed
    error       TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    sent_at     TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mail_outbox_status ON mail_outbox(status);
CREATE INDEX IF NOT EXISTS idx_mail_outbox_created ON mail_outbox(created_at DESC);
