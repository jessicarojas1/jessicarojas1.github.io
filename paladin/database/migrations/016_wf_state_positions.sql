-- ============================================================================
-- Migration 016 — Workflow state diagram positions
-- Stores each state's x/y position on the visual workflow diagram so the
-- editor layout is preserved. NULL positions fall back to an auto-layout.
-- Idempotent. Part of the combined schema (see database/schema.sql).
-- ============================================================================

ALTER TABLE wf_states ADD COLUMN IF NOT EXISTS pos_x INTEGER;
ALTER TABLE wf_states ADD COLUMN IF NOT EXISTS pos_y INTEGER;
