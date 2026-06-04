-- System Security Plans
CREATE TABLE IF NOT EXISTS aegis.ssp_plans (
    id                      SERIAL PRIMARY KEY,
    title                   VARCHAR(255) NOT NULL,
    system_name             VARCHAR(255),
    system_description      TEXT,
    system_owner            VARCHAR(255),
    system_owner_email      VARCHAR(255),
    information_owner       VARCHAR(255),
    authorizing_official    VARCHAR(255),
    authorization_boundary  TEXT,
    network_architecture    TEXT,
    data_flow               TEXT,
    operational_status      VARCHAR(50) DEFAULT 'operational',
    system_type             VARCHAR(50) DEFAULT 'major_application',
    confidentiality_impact  VARCHAR(20) DEFAULT 'moderate',
    integrity_impact        VARCHAR(20) DEFAULT 'moderate',
    availability_impact     VARCHAR(20) DEFAULT 'moderate',
    authorization_date      DATE,
    next_review_date        DATE,
    created_by              INTEGER REFERENCES aegis.users(id),
    created_at              TIMESTAMP DEFAULT NOW(),
    updated_at              TIMESTAMP DEFAULT NOW()
);

-- Packages linked to an SSP (many-to-many)
CREATE TABLE IF NOT EXISTS aegis.ssp_packages (
    id         SERIAL PRIMARY KEY,
    ssp_id     INTEGER NOT NULL REFERENCES aegis.ssp_plans(id) ON DELETE CASCADE,
    package_id INTEGER NOT NULL REFERENCES aegis.compliance_packages(id) ON DELETE CASCADE,
    UNIQUE(ssp_id, package_id)
);

-- Per-control SSP narrative (supplements control_implementations)
CREATE TABLE IF NOT EXISTS aegis.ssp_control_statements (
    id                    SERIAL PRIMARY KEY,
    ssp_id                INTEGER NOT NULL REFERENCES aegis.ssp_plans(id) ON DELETE CASCADE,
    objective_id          INTEGER NOT NULL REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE,
    implementation_statement TEXT,
    responsible_roles     TEXT,
    objective_responses   TEXT,
    UNIQUE(ssp_id, objective_id)
);

CREATE INDEX IF NOT EXISTS idx_ssp_packages_ssp     ON aegis.ssp_packages(ssp_id);
CREATE INDEX IF NOT EXISTS idx_ssp_statements_ssp   ON aegis.ssp_control_statements(ssp_id);
