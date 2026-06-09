-- ============================================================================
-- Migration 021 — Comment editing
-- Authors can edit their own comments; edited_at records when. Idempotent.
-- Part of the combined schema (see database/schema.sql).
-- ============================================================================

ALTER TABLE comments ADD COLUMN IF NOT EXISTS edited_at TIMESTAMP;
