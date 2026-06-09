-- Stateful workflow runtime: a content item's current state + transition history.
CREATE TABLE IF NOT EXISTS wf_status (
    id          SERIAL PRIMARY KEY,
    entity_type VARCHAR(40) NOT NULL,
    entity_id   INTEGER NOT NULL,
    template_id INTEGER NOT NULL REFERENCES workflow_templates(id) ON DELETE CASCADE,
    state_id    INTEGER NOT NULL REFERENCES wf_states(id) ON DELETE CASCADE,
    updated_by  INTEGER REFERENCES users(id),
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (entity_type, entity_id)
);
CREATE TABLE IF NOT EXISTS wf_history (
    id            SERIAL PRIMARY KEY,
    entity_type   VARCHAR(40) NOT NULL,
    entity_id     INTEGER NOT NULL,
    template_id   INTEGER,
    from_state_id INTEGER,
    to_state_id   INTEGER,
    action_label  VARCHAR(60),
    user_id       INTEGER REFERENCES users(id),
    signed        BOOLEAN NOT NULL DEFAULT FALSE,
    comment       TEXT,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_wf_history_entity ON wf_history(entity_type, entity_id);

INSERT INTO settings (key, value, type, description) VALUES
    ('require_esignature', '0', 'boolean', 'Require password re-authentication (e-signature) on workflow transitions')
ON CONFLICT (key) DO NOTHING;
