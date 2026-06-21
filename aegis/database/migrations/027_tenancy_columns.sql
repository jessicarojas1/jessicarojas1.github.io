-- Migration 027 — Multi-tenancy Phase 2: tenant_id on primary entity tables
--
-- Adds tenant_id to the primary tenant-owned tables. DEFAULT 1 backfills every
-- existing row to the default tenant, so this stays INERT in a single-tenant
-- deployment (no isolation behavior changes; RLS is enabled later in Phase 4).
-- Existence-checked + IF NOT EXISTS, so it is safe and idempotent. Child/detail
-- tables (e.g. poam_milestones, policy_versions) and reference tables (standards,
-- settings) are intentionally deferred — they are added before Phase 4 enforcement
-- once the tenancy product decision is finalized.

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
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = 'aegis' AND table_name = t) THEN
      EXECUTE format(
        'ALTER TABLE aegis.%I ADD COLUMN IF NOT EXISTS tenant_id BIGINT NOT NULL DEFAULT 1 REFERENCES aegis.tenants(id)', t);
      EXECUTE format(
        'CREATE INDEX IF NOT EXISTS %I ON aegis.%I(tenant_id)', 'idx_' || t || '_tenant', t);
    END IF;
  END LOOP;
END$$;
