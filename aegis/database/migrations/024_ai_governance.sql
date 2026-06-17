-- Migration 024 — AI governance (Phase 4)
-- Adds the global AIAdvisor kill-switch setting. Default '1' (enabled) preserves
-- existing behavior; set to '0' to disable all AI features org-wide regardless of
-- whether an API key is configured. Idempotent.

INSERT INTO settings (key, value, type, description) VALUES
    ('ai_enabled', '1', 'string', 'Global AIAdvisor kill-switch — set to 0 to disable all AI features org-wide')
ON CONFLICT (key) DO NOTHING;
