-- Stateful (Comala-style) workflows: named states + transitions + space assignment.
CREATE TABLE IF NOT EXISTS wf_states (
    id          SERIAL PRIMARY KEY,
    template_id INTEGER NOT NULL REFERENCES workflow_templates(id) ON DELETE CASCADE,
    name        VARCHAR(80) NOT NULL,
    color       VARCHAR(9) DEFAULT '#64748b',
    kind        VARCHAR(20) NOT NULL DEFAULT 'inprogress', -- initial/inprogress/review/approved/rejected/final
    is_initial  BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_wf_states_tpl ON wf_states(template_id);

CREATE TABLE IF NOT EXISTS wf_transitions (
    id            SERIAL PRIMARY KEY,
    template_id   INTEGER NOT NULL REFERENCES workflow_templates(id) ON DELETE CASCADE,
    from_state_id INTEGER NOT NULL REFERENCES wf_states(id) ON DELETE CASCADE,
    to_state_id   INTEGER NOT NULL REFERENCES wf_states(id) ON DELETE CASCADE,
    action_label  VARCHAR(60) NOT NULL DEFAULT 'Submit',
    approver_role VARCHAR(40),
    approver_user_id INTEGER REFERENCES users(id),
    created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_wf_transitions_tpl ON wf_transitions(template_id);

CREATE TABLE IF NOT EXISTS workflow_space_assignments (
    id          SERIAL PRIMARY KEY,
    template_id INTEGER NOT NULL REFERENCES workflow_templates(id) ON DELETE CASCADE,
    space_id    INTEGER NOT NULL REFERENCES spaces(id) ON DELETE CASCADE,
    UNIQUE (template_id, space_id)
);
