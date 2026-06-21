-- ============================================================================
-- Migration 033 — Threaded inline-comment replies
-- A reply is an inline_comments row with parent_id pointing at the top-level
-- (anchored) comment. Idempotent.
-- ============================================================================

ALTER TABLE inline_comments
    ADD COLUMN IF NOT EXISTS parent_id INTEGER REFERENCES inline_comments(id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_inline_comments_parent ON inline_comments(parent_id);
