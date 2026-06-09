-- ============================================================================
-- Migration 018 — Page trash (soft delete)
-- Pages are soft-deleted (deleted_at set) so they can be restored from a
-- per-space Trash, keeping the original page id (and therefore links) intact.
-- Idempotent. Part of the combined schema (see database/schema.sql).
-- ============================================================================

ALTER TABLE pages ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP;
ALTER TABLE pages ADD COLUMN IF NOT EXISTS deleted_by INTEGER REFERENCES users(id);
CREATE INDEX IF NOT EXISTS idx_pages_deleted ON pages(space_id, deleted_at);
