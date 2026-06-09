-- ============================================================================
-- Migration 019 — Inline tasks / action items
-- Checkbox tasks authored inside a page body are extracted into trackable
-- action items (assignable, due-dated, completable) and aggregated into a
-- "My Action Items" view. Idempotent. Part of the combined schema.
-- ============================================================================

CREATE TABLE IF NOT EXISTS page_tasks (
    id          SERIAL PRIMARY KEY,
    page_id     INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    seq         INTEGER NOT NULL DEFAULT 0,
    text        TEXT NOT NULL,
    text_hash   VARCHAR(40) NOT NULL,
    assignee_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    due_date    DATE,
    done        BOOLEAN NOT NULL DEFAULT FALSE,
    done_at     TIMESTAMP,
    done_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_page_tasks_page     ON page_tasks(page_id, seq);
CREATE INDEX IF NOT EXISTS idx_page_tasks_assignee ON page_tasks(assignee_id, done);
