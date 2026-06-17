-- Migration 023 — Risk scoring consolidation (Phase 3)
-- Adds a stored target_score column to mirror the existing inherent_score /
-- residual_score columns, and indexes the score columns that the risk dashboard,
-- level filters, and heatmap sort/aggregate on. Idempotent: safe to re-run.

ALTER TABLE risks ADD COLUMN IF NOT EXISTS target_score INTEGER NOT NULL DEFAULT 0;

-- Backfill target_score from the target likelihood × impact axes where present.
UPDATE risks
   SET target_score = COALESCE(target_likelihood, 0) * COALESCE(target_impact, 0)
 WHERE target_score = 0
   AND target_likelihood IS NOT NULL
   AND target_impact IS NOT NULL;

-- Dashboard counts, level filters, and "ORDER BY inherent_score DESC" list views.
CREATE INDEX IF NOT EXISTS idx_risks_inherent_score ON risks(inherent_score);
CREATE INDEX IF NOT EXISTS idx_risks_residual_score ON risks(residual_score);
