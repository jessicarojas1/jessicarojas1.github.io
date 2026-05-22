-- AEGIS GRC Database Schema
CREATE SCHEMA IF NOT EXISTS aegis;
SET search_path TO aegis;

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'viewer',
    department VARCHAR(255),
    job_title VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS api_keys (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    key_prefix VARCHAR(20) NOT NULL,
    key_hash VARCHAR(255) NOT NULL,
    permissions JSONB NOT NULL DEFAULT '["read"]',
    last_used TIMESTAMP,
    expires_at TIMESTAMP,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS standards (
    id SERIAL PRIMARY KEY,
    code VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    version VARCHAR(50),
    description TEXT,
    category VARCHAR(100),
    authority VARCHAR(255),
    url VARCHAR(500),
    is_builtin BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS compliance_packages (
    id SERIAL PRIMARY KEY,
    standard_id INTEGER REFERENCES standards(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    version VARCHAR(50),
    description TEXT,
    is_paid BOOLEAN NOT NULL DEFAULT FALSE,
    price DECIMAL(10,2),
    objectives_count INTEGER DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    imported_by INTEGER REFERENCES users(id),
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS compliance_objectives (
    id SERIAL PRIMARY KEY,
    package_id INTEGER REFERENCES compliance_packages(id) ON DELETE CASCADE,
    parent_id INTEGER REFERENCES compliance_objectives(id),
    code VARCHAR(100) NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    category VARCHAR(255),
    level INTEGER NOT NULL DEFAULT 1,
    weight DECIMAL(5,2) DEFAULT 1.0,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS control_implementations (
    id SERIAL PRIMARY KEY,
    objective_id INTEGER REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    status VARCHAR(50) NOT NULL DEFAULT 'not_started',
    implementation_notes TEXT,
    evidence TEXT,
    assigned_to INTEGER REFERENCES users(id),
    due_date DATE,
    last_reviewed TIMESTAMP,
    reviewed_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(objective_id)
);

CREATE TABLE IF NOT EXISTS audits (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    package_id INTEGER REFERENCES compliance_packages(id),
    audit_type VARCHAR(100) NOT NULL DEFAULT 'internal',
    frequency VARCHAR(50),
    status VARCHAR(50) NOT NULL DEFAULT 'planned',
    scheduled_date DATE,
    start_date DATE,
    completed_date DATE,
    auditor_id INTEGER REFERENCES users(id),
    created_by INTEGER REFERENCES users(id),
    notes TEXT,
    score DECIMAL(5,2),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_schedules (
    id SERIAL PRIMARY KEY,
    package_id INTEGER REFERENCES compliance_packages(id),
    frequency VARCHAR(50) NOT NULL DEFAULT 'annual',
    last_audit_date DATE,
    next_due_date DATE,
    assigned_auditor INTEGER REFERENCES users(id),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_items (
    id SERIAL PRIMARY KEY,
    audit_id INTEGER REFERENCES audits(id) ON DELETE CASCADE,
    objective_id INTEGER REFERENCES compliance_objectives(id),
    status VARCHAR(50) NOT NULL DEFAULT 'not_assessed',
    finding TEXT,
    evidence TEXT,
    notes TEXT,
    risk_level VARCHAR(50),
    remediation TEXT,
    remediation_due DATE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policies (
    id SERIAL PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    policy_number VARCHAR(100),
    description TEXT,
    content TEXT,
    version VARCHAR(50) NOT NULL DEFAULT '1.0',
    status VARCHAR(50) NOT NULL DEFAULT 'draft',
    category VARCHAR(255),
    owner_id INTEGER REFERENCES users(id),
    approver_id INTEGER REFERENCES users(id),
    review_frequency VARCHAR(50) DEFAULT 'annual',
    next_review_date DATE,
    approved_at TIMESTAMP,
    published_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policy_versions (
    id SERIAL PRIMARY KEY,
    policy_id INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    version VARCHAR(50) NOT NULL,
    content TEXT,
    change_summary TEXT,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS policy_mappings (
    id SERIAL PRIMARY KEY,
    policy_id INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    objective_id INTEGER REFERENCES compliance_objectives(id) ON DELETE CASCADE,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(policy_id, objective_id)
);

CREATE TABLE IF NOT EXISTS policy_reviews (
    id SERIAL PRIMARY KEY,
    policy_id INTEGER REFERENCES policies(id) ON DELETE CASCADE,
    reviewer_id INTEGER REFERENCES users(id),
    scheduled_date DATE,
    completed_date DATE,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    outcome VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risk_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    color VARCHAR(50) DEFAULT '#6366f1',
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risks (
    id SERIAL PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    risk_id VARCHAR(100),
    description TEXT,
    category_id INTEGER REFERENCES risk_categories(id),
    likelihood INTEGER NOT NULL DEFAULT 3 CHECK (likelihood BETWEEN 1 AND 5),
    impact INTEGER NOT NULL DEFAULT 3 CHECK (impact BETWEEN 1 AND 5),
    inherent_score INTEGER NOT NULL DEFAULT 0,
    residual_likelihood INTEGER CHECK (residual_likelihood BETWEEN 1 AND 5),
    residual_impact INTEGER CHECK (residual_impact BETWEEN 1 AND 5),
    residual_score INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(50) NOT NULL DEFAULT 'open',
    treatment_type VARCHAR(50),
    treatment_description TEXT,
    owner_id INTEGER REFERENCES users(id),
    review_date DATE,
    identified_date DATE NOT NULL DEFAULT CURRENT_DATE,
    tags JSONB DEFAULT '[]',
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risk_treatments (
    id SERIAL PRIMARY KEY,
    risk_id INTEGER REFERENCES risks(id) ON DELETE CASCADE,
    treatment_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    cost_estimate DECIMAL(12,2),
    effort VARCHAR(50),
    due_date DATE,
    status VARCHAR(50) NOT NULL DEFAULT 'planned',
    owner_id INTEGER REFERENCES users(id),
    completion_date DATE,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS risk_matrix_config (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT 'Default',
    rows INTEGER NOT NULL DEFAULT 5,
    cols INTEGER NOT NULL DEFAULT 5,
    row_label VARCHAR(50) NOT NULL DEFAULT 'Likelihood',
    col_label VARCHAR(50) NOT NULL DEFAULT 'Impact',
    row_labels JSONB NOT NULL DEFAULT '["Rare","Unlikely","Possible","Likely","Almost Certain"]',
    col_labels JSONB NOT NULL DEFAULT '["Negligible","Minor","Moderate","Major","Critical"]',
    thresholds JSONB NOT NULL DEFAULT '{"low":4,"medium":9,"high":14,"critical":25}',
    colors JSONB NOT NULL DEFAULT '{"low":"#22c55e","medium":"#f59e0b","high":"#f97316","critical":"#ef4444"}',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS workflows (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    trigger_type VARCHAR(100) NOT NULL,
    trigger_config JSONB NOT NULL DEFAULT '{}',
    actions JSONB NOT NULL DEFAULT '[]',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alerts (
    id SERIAL PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    title VARCHAR(500) NOT NULL,
    message TEXT,
    severity VARCHAR(50) NOT NULL DEFAULT 'info',
    user_id INTEGER REFERENCES users(id),
    related_type VARCHAR(100),
    related_id INTEGER,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    read_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alert_configs (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,
    trigger_config JSONB NOT NULL DEFAULT '{}',
    recipients JSONB NOT NULL DEFAULT '[]',
    channels JSONB NOT NULL DEFAULT '["in_app"]',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT,
    type VARCHAR(50) DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activity_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    action VARCHAR(255) NOT NULL,
    entity_type VARCHAR(100),
    entity_id INTEGER,
    changes JSONB,
    ip_address VARCHAR(50),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rate_limits (
    key VARCHAR(255) PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_permissions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    module VARCHAR(100) NOT NULL,
    permission VARCHAR(50) NOT NULL,
    granted_by INTEGER REFERENCES users(id),
    granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, module, permission)
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_co_package   ON compliance_objectives(package_id);
CREATE INDEX IF NOT EXISTS idx_co_parent    ON compliance_objectives(parent_id);
CREATE INDEX IF NOT EXISTS idx_ci_objective ON control_implementations(objective_id);
CREATE INDEX IF NOT EXISTS idx_ci_status    ON control_implementations(status);
CREATE INDEX IF NOT EXISTS idx_ai_audit     ON audit_items(audit_id);
CREATE INDEX IF NOT EXISTS idx_risks_status ON risks(status);
CREATE INDEX IF NOT EXISTS idx_risks_owner  ON risks(owner_id);
CREATE INDEX IF NOT EXISTS idx_pm_policy    ON policy_mappings(policy_id);
CREATE INDEX IF NOT EXISTS idx_pm_objective ON policy_mappings(objective_id);
CREATE INDEX IF NOT EXISTS idx_alerts_user  ON alerts(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_al_user      ON activity_log(user_id);
CREATE INDEX IF NOT EXISTS idx_al_entity    ON activity_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_up_user      ON user_permissions(user_id);
