-- ============================================================================
-- Migration 025 — Scheduled page publishing
-- A draft/in-review page can carry a future publish time; a lightweight sweep
-- (Scheduler::runDuePages, invoked on common page requests) flips it to
-- published once that time passes. Idempotent.
-- ============================================================================

ALTER TABLE pages ADD COLUMN IF NOT EXISTS scheduled_publish_at TIMESTAMP;

CREATE INDEX IF NOT EXISTS idx_pages_scheduled_publish
    ON pages (scheduled_publish_at)
    WHERE scheduled_publish_at IS NOT NULL;
