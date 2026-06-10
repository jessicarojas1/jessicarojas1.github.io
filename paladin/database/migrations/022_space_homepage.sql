-- ============================================================================
-- Migration 022 — Space homepage
-- A space can designate one of its pages as its homepage/overview, shown at the
-- top of the space view. Idempotent. Part of the combined schema.
-- ============================================================================

ALTER TABLE spaces ADD COLUMN IF NOT EXISTS homepage_id INTEGER REFERENCES pages(id) ON DELETE SET NULL;
