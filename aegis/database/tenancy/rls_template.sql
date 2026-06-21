-- AEGIS — Row-Level Security TEMPLATE (NOT a migration; not auto-applied).
--
-- Reference pattern for Phase 4 of MULTI_TENANCY.md. Apply per tenant-owned
-- table, as the schema owner, ONLY after the write/read paths set
-- aegis.tenant_id (otherwise every query returns zero rows and the app breaks).
--
-- Replace `risks` with the target table. Repeat for every tenant-owned table.

-- 1) Tenant registry (create once)
CREATE TABLE IF NOT EXISTS aegis.tenants (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    slug        VARCHAR(100) UNIQUE NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2) Add the tenant key (nullable first; backfill; then SET NOT NULL)
ALTER TABLE aegis.risks ADD COLUMN IF NOT EXISTS tenant_id BIGINT REFERENCES aegis.tenants(id);
CREATE INDEX IF NOT EXISTS idx_risks_tenant ON aegis.risks(tenant_id);

-- 3) Enable + FORCE RLS (FORCE makes it apply even to the table owner)
ALTER TABLE aegis.risks ENABLE ROW LEVEL SECURITY;
ALTER TABLE aegis.risks FORCE  ROW LEVEL SECURITY;

-- 4) Isolation policy keyed to the per-connection GUC set by Database::setTenant()
-- NULLIF(..,'') keeps the ::bigint cast total: an unset/empty GUC becomes NULL,
-- so `tenant_id = NULL` is NULL (deny — no rows) instead of raising 22P02. SQL OR
-- is not guaranteed to short-circuit, so never cast the raw setting directly.
DROP POLICY IF EXISTS tenant_isolation ON aegis.risks;
CREATE POLICY tenant_isolation ON aegis.risks
  USING      (tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint)
  WITH CHECK (tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint);

-- The app sets the GUC per request after authentication:
--   SELECT set_config('aegis.tenant_id', '<id>', false);
--
-- NOTE: This template shows the Phase 4 *strict* (deny-by-default) policy — when
-- the GUC is unset, zero rows are visible. Phase 3 (migration 028) ships a
-- *permissive-fallback* form first: it adds `current_setting(...) IS NULL OR
-- ... = ''` to both USING and WITH CHECK so single-tenant deployments, CLI
-- scripts, and cron keep working while the GUC is unset, and isolation engages
-- only once a request binds a tenant. Phase 4 drops the permissive clause.
