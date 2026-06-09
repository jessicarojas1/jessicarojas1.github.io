-- Per-user "recently viewed" history (title denormalized for resilient display).
CREATE TABLE IF NOT EXISTS recent_views (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    entity_type VARCHAR(40) NOT NULL,
    entity_id   INTEGER NOT NULL,
    title       VARCHAR(255),
    viewed_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_recent_user ON recent_views(user_id, viewed_at DESC);
