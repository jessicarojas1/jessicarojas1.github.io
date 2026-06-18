-- ============================================================================
-- Migration 032 — Attachment versioning
-- Re-uploading a file with the same name to an entity supersedes the previous
-- one instead of creating a silent duplicate; prior versions are retained.
-- Idempotent.
-- ============================================================================

ALTER TABLE attachments ADD COLUMN IF NOT EXISTS version     INTEGER NOT NULL DEFAULT 1;
ALTER TABLE attachments ADD COLUMN IF NOT EXISTS is_current  BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE attachments ADD COLUMN IF NOT EXISTS replaced_at TIMESTAMP;

-- Fast lookup of an entity's current attachments / a name's version chain.
CREATE INDEX IF NOT EXISTS idx_attachments_current
    ON attachments(entity_type, entity_id) WHERE is_current = TRUE;
CREATE INDEX IF NOT EXISTS idx_attachments_chain
    ON attachments(entity_type, entity_id, original_name);
