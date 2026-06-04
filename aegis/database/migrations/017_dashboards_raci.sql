-- Migration 017: Custom Dashboards, RACI Matrix, Shared Responsibility Matrix

CREATE TABLE IF NOT EXISTS aegis.custom_dashboards (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    description TEXT,
    is_shared   BOOLEAN DEFAULT FALSE,
    owner_id    INTEGER REFERENCES aegis.users(id) ON DELETE CASCADE,
    created_at  TIMESTAMP DEFAULT NOW(),
    updated_at  TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aegis.dashboard_widgets (
    id           SERIAL PRIMARY KEY,
    dashboard_id INTEGER NOT NULL REFERENCES aegis.custom_dashboards(id) ON DELETE CASCADE,
    widget_type  VARCHAR(50) NOT NULL,
    title        VARCHAR(255) NOT NULL,
    config       JSONB DEFAULT '{}',
    position     INTEGER DEFAULT 0,
    created_at   TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS aegis.raci_assignments (
    id           SERIAL PRIMARY KEY,
    package_id   INTEGER NOT NULL REFERENCES aegis.compliance_packages(id) ON DELETE CASCADE,
    objective_id INTEGER NOT NULL REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES aegis.users(id) ON DELETE CASCADE,
    raci_role    VARCHAR(20) NOT NULL,
    UNIQUE(package_id, objective_id, user_id, raci_role)
);

CREATE TABLE IF NOT EXISTS aegis.shared_responsibility (
    id             SERIAL PRIMARY KEY,
    package_id     INTEGER NOT NULL REFERENCES aegis.compliance_packages(id) ON DELETE CASCADE,
    objective_id   INTEGER NOT NULL REFERENCES aegis.compliance_objectives(id) ON DELETE CASCADE,
    responsibility VARCHAR(20) DEFAULT 'customer',
    provider_name  VARCHAR(255),
    customer_notes TEXT,
    provider_notes TEXT,
    UNIQUE(package_id, objective_id)
);

CREATE INDEX IF NOT EXISTS idx_dashboard_widgets   ON aegis.dashboard_widgets(dashboard_id);
CREATE INDEX IF NOT EXISTS idx_raci_package        ON aegis.raci_assignments(package_id);
CREATE INDEX IF NOT EXISTS idx_shared_resp_package ON aegis.shared_responsibility(package_id);
