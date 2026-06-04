-- GRC Projects
CREATE TABLE IF NOT EXISTS aegis.grc_projects (
    id               SERIAL PRIMARY KEY,
    project_code     VARCHAR(20) UNIQUE NOT NULL,
    title            VARCHAR(255) NOT NULL,
    description      TEXT,
    status           VARCHAR(30) DEFAULT 'planning',  -- planning/active/on_hold/completed/cancelled
    priority         VARCHAR(20) DEFAULT 'medium',    -- low/medium/high/critical
    start_date       DATE,
    end_date         DATE,
    budget_planned   NUMERIC(12,2),
    budget_actual    NUMERIC(12,2),
    project_lead     INTEGER REFERENCES aegis.users(id),
    created_by       INTEGER REFERENCES aegis.users(id),
    created_at       TIMESTAMP DEFAULT NOW(),
    updated_at       TIMESTAMP DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS aegis.grc_project_tasks (
    id           SERIAL PRIMARY KEY,
    project_id   INTEGER NOT NULL REFERENCES aegis.grc_projects(id) ON DELETE CASCADE,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    status       VARCHAR(20) DEFAULT 'todo',  -- todo/in_progress/done
    assigned_to  INTEGER REFERENCES aegis.users(id),
    due_date     DATE,
    created_at   TIMESTAMP DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS aegis.grc_project_links (
    id           SERIAL PRIMARY KEY,
    project_id   INTEGER NOT NULL REFERENCES aegis.grc_projects(id) ON DELETE CASCADE,
    entity_type  VARCHAR(50) NOT NULL,  -- risk/control/issue/finding
    entity_id    INTEGER NOT NULL,
    UNIQUE(project_id, entity_type, entity_id)
);
CREATE INDEX IF NOT EXISTS idx_grc_project_tasks ON aegis.grc_project_tasks(project_id);
CREATE INDEX IF NOT EXISTS idx_grc_project_links ON aegis.grc_project_links(project_id);

-- CUI Inventory (Controlled Unclassified Information)
CREATE TABLE IF NOT EXISTS aegis.cui_inventory (
    id                  SERIAL PRIMARY KEY,
    inventory_number    VARCHAR(20) UNIQUE NOT NULL,
    data_description    TEXT NOT NULL,
    cui_category        VARCHAR(100),    -- e.g. ITAR, PHI, PII, Export Controlled, etc.
    asset_id            INTEGER REFERENCES aegis.assets(id) ON DELETE SET NULL,
    system_name         VARCHAR(255),
    location_description TEXT,
    storage_type        VARCHAR(50) DEFAULT 'database',  -- database/file_share/cloud/email/paper/other
    access_controls     TEXT,
    is_encrypted        BOOLEAN DEFAULT FALSE,
    encryption_details  TEXT,
    data_owner          VARCHAR(255),
    created_by          INTEGER REFERENCES aegis.users(id),
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_cui_inventory_type ON aegis.cui_inventory(storage_type);
