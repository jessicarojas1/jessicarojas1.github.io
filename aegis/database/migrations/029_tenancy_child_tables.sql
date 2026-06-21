-- Migration 029 — Multi-tenancy Phase 4 (coverage): tenant_id + RLS on child tables
--
-- Extends tenancy from the 26 primary entities (migrations 027/028) to their
-- child/detail and link tables, so the tenant boundary is complete before any
-- future deny-by-default enforce step. Same INERT pattern: tenant_id DEFAULT 1
-- backfills existing rows; the tenant_isolation policy is permissive while the
-- aegis.tenant_id GUC is unset/empty (single-tenant, CLI, cron). Idempotent:
-- existence-checked, ADD COLUMN IF NOT EXISTS, DROP POLICY IF EXISTS + recreate.
--
-- Intentionally deferred (tenancy is a product decision, and several are touched
-- pre-auth): auth/session/token tables, global/reference/system tables, per-user
-- prefs/dashboards, and the workflow/approval/automation/webhook/reporting engine.

DO $$
DECLARE
  t   text;
  tbls text[] := ARRAY[
    'audit_schedules','audit_items','finding_updates',
    'policy_versions','policy_mappings','policy_reviews','policy_attestations',
    'policy_attestation_campaigns',
    'risk_score_history','risk_control_links','risk_related_links','risk_treatments',
    'risk_acceptances','risk_bowtie_causes','risk_bowtie_consequences',
    'risk_bowtie_barriers','risk_scenarios','risk_reviews','risk_review_items',
    'risk_exceptions','treatment_plans','treatment_milestones',
    'incident_updates','incident_sla_events','issue_updates',
    'vendor_assessments','vendor_contracts',
    'asset_risk_links','threat_risk_links',
    'poam_milestones','kri_values','document_versions',
    'bcp_plan_sections','bcp_exercises',
    'data_subject_requests','account_review_items','awareness_assignments',
    'ssp_packages','ssp_control_statements',
    'questionnaire_questions','questionnaire_assignments','questionnaire_responses',
    'questionnaire_answers','change_request_updates',
    'grc_project_tasks','grc_project_links',
    'control_mappings','control_tests','raci_assignments','shared_responsibility',
    'evidence','evidence_files'
  ];
BEGIN
  FOREACH t IN ARRAY tbls LOOP
    IF EXISTS (SELECT 1 FROM information_schema.tables
               WHERE table_schema = 'aegis' AND table_name = t) THEN
      EXECUTE format(
        'ALTER TABLE aegis.%I ADD COLUMN IF NOT EXISTS tenant_id BIGINT NOT NULL DEFAULT 1 REFERENCES aegis.tenants(id)', t);
      EXECUTE format(
        'CREATE INDEX IF NOT EXISTS %I ON aegis.%I(tenant_id)', 'idx_' || t || '_tenant', t);
      EXECUTE format('ALTER TABLE aegis.%I ENABLE ROW LEVEL SECURITY', t);
      EXECUTE format('ALTER TABLE aegis.%I FORCE ROW LEVEL SECURITY', t);
      EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON aegis.%I', t);
      -- NULLIF(..,'') keeps the ::bigint cast total: an unset/empty GUC becomes
      -- NULL (permissive) instead of raising 22P02. See migration 028.
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
