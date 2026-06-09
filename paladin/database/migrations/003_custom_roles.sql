-- Custom roles: admin-defined named roles with their own module×action permission sets.
CREATE TABLE IF NOT EXISTS custom_roles (
    id          SERIAL PRIMARY KEY,
    role_key    VARCHAR(40) UNIQUE NOT NULL,
    name        VARCHAR(80) NOT NULL,
    description TEXT,
    created_by  INTEGER REFERENCES users(id),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS custom_role_permissions (
    id          SERIAL PRIMARY KEY,
    role_id     INTEGER NOT NULL REFERENCES custom_roles(id) ON DELETE CASCADE,
    module      VARCHAR(40) NOT NULL,
    permission  VARCHAR(40) NOT NULL,
    UNIQUE (role_id, module, permission)
);
CREATE INDEX IF NOT EXISTS idx_custom_role_perms_role ON custom_role_permissions(role_id);
