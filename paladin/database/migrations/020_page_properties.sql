-- ============================================================================
-- Migration 020 — Page properties (Confluence "Page Properties" macro)
-- Labelled key/value rows authored in a page via a `page-properties` table are
-- extracted here so a "Page Properties Report" can aggregate them across all
-- pages sharing a label. Idempotent. Part of the combined schema.
-- ============================================================================

CREATE TABLE IF NOT EXISTS page_properties (
    id         SERIAL PRIMARY KEY,
    page_id    INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    seq        INTEGER NOT NULL DEFAULT 0,
    prop_key   VARCHAR(160) NOT NULL,
    prop_value TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_page_properties_page ON page_properties(page_id);
CREATE INDEX IF NOT EXISTS idx_page_properties_key  ON page_properties(prop_key);
