-- ============================================================================
-- Migration 030 — Per-space sidebar shortcut links
-- Curated quick links shown in a space's sidebar (Confluence space shortcuts).
-- Idempotent.
-- ============================================================================

CREATE TABLE IF NOT EXISTS space_shortcuts (
    id         SERIAL PRIMARY KEY,
    space_id   INTEGER NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    label      VARCHAR(120) NOT NULL,
    url        VARCHAR(2048) NOT NULL,
    icon       VARCHAR(40) NOT NULL DEFAULT 'bi-link-45deg',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_space_shortcuts_space ON space_shortcuts(space_id, sort_order);
