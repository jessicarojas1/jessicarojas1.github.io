-- Likes / reactions on pages, documents and comments (one like per user/entity).
CREATE TABLE IF NOT EXISTS reactions (
    id          SERIAL PRIMARY KEY,
    entity_type VARCHAR(40) NOT NULL,   -- page | document | comment
    entity_id   INTEGER NOT NULL,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (entity_type, entity_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_reactions_entity ON reactions(entity_type, entity_id);
