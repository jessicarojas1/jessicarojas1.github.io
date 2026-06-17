-- ============================================================================
-- Migration 031 — Webhook delivery retry/backoff
-- Adds payload storage + retry bookkeeping so failed deliveries can be re-tried
-- with exponential backoff by the scheduler. Idempotent.
-- ============================================================================

ALTER TABLE webhook_deliveries ADD COLUMN IF NOT EXISTS payload       TEXT;
ALTER TABLE webhook_deliveries ADD COLUMN IF NOT EXISTS attempts      INTEGER NOT NULL DEFAULT 1;
ALTER TABLE webhook_deliveries ADD COLUMN IF NOT EXISTS next_retry_at TIMESTAMP;

-- Worker lookup: due, still-failing deliveries.
CREATE INDEX IF NOT EXISTS idx_wh_deliv_retry
    ON webhook_deliveries(next_retry_at)
    WHERE next_retry_at IS NOT NULL AND success = FALSE;
