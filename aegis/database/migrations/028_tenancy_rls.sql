-- Migration 028 — Multi-tenancy Phase 3: read-path Row-Level Security
--
-- Enables RLS on every tenant-owned table (migration 027) with a *permissive-
-- fallback* isolation policy:
--
--   * When the `aegis.tenant_id` session GUC is UNSET or empty (single-tenant
--     deployments, CLI scripts, cron, background workers, and any request before
--     a tenant is bound), the policy is permissive — all rows are visible. So this
--     stays INERT for existing single-tenant installs; nothing changes.
--   * When a request binds a tenant via Database::setTenant() (the GUC is set),
--     reads and writes are isolated to that tenant in the database itself — even a
--     forgotten WHERE clause or a SQLi cannot cross the tenant boundary.
--
-- ENABLE + FORCE so the policy applies even to the table owner; the runtime app
-- must connect as a non-superuser role (superusers bypass RLS). The hard cutover
-- to deny-by-default (NULL GUC ⇒ no rows) plus NOT NULL is Phase 4.
--
-- Idempotent: ENABLE/FORCE are no-ops if already set; the policy is dropped and
-- recreated. Existence-checked so it is safe to re-run against any state.

DO $$
DECLARE
  t   text;
  tbls text[] := ARRAY[
    'users','risks','policies','audits','audit_findings','compliance_packages',
    'compliance_objectives','control_implementations','incidents','issues',
    'vendors','assets','threats','poam_items','kris','documents','bcp_plans',
    'privacy_records','account_reviews','awareness_programs','change_requests',
    'grc_projects','cui_inventory','odp_entries','ssp_plans','questionnaires'
  ];
BEGIN
  FOREACH t IN ARRAY tbls LOOP
    -- Only act on tables that exist AND actually carry tenant_id (migration 027).
    IF EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = 'aegis' AND table_name = t AND column_name = 'tenant_id'
    ) THEN
      EXECUTE format('ALTER TABLE aegis.%I ENABLE ROW LEVEL SECURITY', t);
      EXECUTE format('ALTER TABLE aegis.%I FORCE ROW LEVEL SECURITY', t);
      EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON aegis.%I', t);
      -- NULLIF(..,'') turns an unset/empty GUC into NULL so the ::bigint cast is
      -- only ever applied to a valid integer (or NULL). SQL OR is NOT guaranteed
      -- to short-circuit, so guarding with `= ''` is unsafe — Postgres may still
      -- evaluate `''::bigint` and raise 22P02. NULLIF makes the cast total.
      EXECUTE format($pol$
        CREATE POLICY tenant_isolation ON aegis.%I
          USING (
            NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
            OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint)
          WITH CHECK (
            NULLIF(current_setting('aegis.tenant_id', true), '') IS NULL
            OR tenant_id = NULLIF(current_setting('aegis.tenant_id', true), '')::bigint)
      $pol$, t);
    END IF;
  END LOOP;
END$$;
