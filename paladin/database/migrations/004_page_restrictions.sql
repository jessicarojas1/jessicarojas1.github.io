-- Per-page access restrictions (layered on top of space/role permissions).
-- A page with any 'view' restriction is visible only to listed principals
-- (plus admins and the page owner/creator); same idea for 'edit'.
CREATE TABLE IF NOT EXISTS page_restrictions (
    id             SERIAL PRIMARY KEY,
    page_id        INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    mode           VARCHAR(10) NOT NULL DEFAULT 'view',   -- view | edit
    principal_type VARCHAR(10) NOT NULL DEFAULT 'user',   -- user | role
    principal      VARCHAR(64) NOT NULL,                  -- user id (as text) or role_key
    created_by     INTEGER REFERENCES users(id),
    created_at     TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (page_id, mode, principal_type, principal)
);
CREATE INDEX IF NOT EXISTS idx_page_restrictions_page ON page_restrictions(page_id);
