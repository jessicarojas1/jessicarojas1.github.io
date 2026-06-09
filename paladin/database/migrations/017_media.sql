-- ============================================================================
-- Migration 017 — Editor media (uploaded images embedded in rich content)
-- Images inserted via the editor are stored through Storage and referenced by
-- an integer id (/media/{id}) so the URL survives HTML sanitization and reading
-- is auth-gated. Idempotent. Part of the combined schema (see schema.sql).
-- ============================================================================

CREATE TABLE IF NOT EXISTS media (
    id           SERIAL PRIMARY KEY,
    stored_key   VARCHAR(255) NOT NULL,
    original_name VARCHAR(255),
    mime         VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    size         INTEGER,
    uploaded_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_media_uploader ON media(uploaded_by);
