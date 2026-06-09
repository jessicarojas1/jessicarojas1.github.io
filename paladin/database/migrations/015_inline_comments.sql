-- ============================================================================
-- Migration 015 — Inline (anchored) comments on pages
-- A comment bound to a specific text selection within a page body. The quote
-- plus a little surrounding context lets us re-locate (and highlight) the
-- anchor even after edits; if the quote no longer matches, the comment is
-- shown as "outdated" rather than lost.
-- Idempotent. Part of the combined schema (see database/schema.sql).
-- ============================================================================

CREATE TABLE IF NOT EXISTS inline_comments (
    id          SERIAL PRIMARY KEY,
    page_id     INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    quote       TEXT NOT NULL,            -- the exact selected text
    prefix      VARCHAR(160),             -- a little text before (disambiguation)
    suffix      VARCHAR(160),             -- a little text after
    body        TEXT NOT NULL,
    resolved    BOOLEAN NOT NULL DEFAULT FALSE,
    resolved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    resolved_at TIMESTAMP,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_inline_comments_page ON inline_comments(page_id, resolved);
