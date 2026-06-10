-- ============================================================================
-- Migration 028 — Document review/expiry reminders
-- Tracks when an owner was last reminded that a controlled document is due for
-- review or about to expire, so the cron sweep (cli/send_review_reminders.php)
-- doesn't re-notify too often. Idempotent.
-- ============================================================================

ALTER TABLE documents ADD COLUMN IF NOT EXISTS last_review_reminder_at TIMESTAMP;
