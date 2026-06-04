-- POA&M: Plans of Action & Milestones
CREATE TABLE IF NOT EXISTS aegis.poam_items (
    id                      SERIAL PRIMARY KEY,
    poam_number             VARCHAR(20) UNIQUE NOT NULL,
    title                   VARCHAR(255) NOT NULL,
    weakness_description    TEXT,
    resource_requirements   TEXT,
    scheduled_completion    DATE,
    status                  VARCHAR(20) DEFAULT 'open',
    objective_id            INTEGER REFERENCES aegis.compliance_objectives(id) ON DELETE SET NULL,
    package_id              INTEGER REFERENCES aegis.compliance_packages(id) ON DELETE SET NULL,
    owner_id                INTEGER REFERENCES aegis.users(id),
    created_by              INTEGER REFERENCES aegis.users(id),
    created_at              TIMESTAMP DEFAULT NOW(),
    updated_at              TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aegis.poam_milestones (
    id              SERIAL PRIMARY KEY,
    poam_id         INTEGER NOT NULL REFERENCES aegis.poam_items(id) ON DELETE CASCADE,
    description     TEXT NOT NULL,
    due_date        DATE,
    is_complete     BOOLEAN DEFAULT FALSE,
    completed_at    TIMESTAMP,
    created_at      TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_poam_items_status     ON aegis.poam_items(status);
CREATE INDEX IF NOT EXISTS idx_poam_items_package_id ON aegis.poam_items(package_id);
CREATE INDEX IF NOT EXISTS idx_poam_milestones_poam  ON aegis.poam_milestones(poam_id);

-- ODP Center: Organizationally Defined Parameters
CREATE TABLE IF NOT EXISTS aegis.odp_entries (
    id               SERIAL PRIMARY KEY,
    objective_id     INTEGER NOT NULL REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE,
    parameter_name   VARCHAR(255) NOT NULL,
    parameter_value  TEXT,
    notes            TEXT,
    updated_by       INTEGER REFERENCES aegis.users(id),
    updated_at       TIMESTAMP DEFAULT NOW(),
    UNIQUE(objective_id, parameter_name)
);

CREATE INDEX IF NOT EXISTS idx_odp_entries_objective ON aegis.odp_entries(objective_id);
