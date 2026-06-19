-- Integration test: prove the Row-Level Security pattern from
-- database/tenancy/rls_template.sql actually isolates tenants against a live
-- PostgreSQL. Run with ON_ERROR_STOP=1; any failed assertion aborts (exit != 0).
\set ON_ERROR_STOP on

CREATE SCHEMA IF NOT EXISTS aegis;
SET search_path TO aegis, public;

-- Minimal fixtures mirroring the template.
DROP TABLE IF EXISTS rls_demo;
CREATE TABLE rls_demo (id SERIAL PRIMARY KEY, tenant_id BIGINT NOT NULL, label TEXT);

ALTER TABLE rls_demo ENABLE ROW LEVEL SECURITY;
ALTER TABLE rls_demo FORCE  ROW LEVEL SECURITY;   -- applies to the owner too
CREATE POLICY tenant_isolation ON rls_demo
  USING      (tenant_id = current_setting('aegis.tenant_id', true)::bigint)
  WITH CHECK (tenant_id = current_setting('aegis.tenant_id', true)::bigint);

-- Seed as tenant 1, then tenant 2 (WITH CHECK forces the GUC to match).
SELECT set_config('aegis.tenant_id', '1', false);
INSERT INTO rls_demo (tenant_id, label) VALUES (1, 'one-A'), (1, 'one-B');
SELECT set_config('aegis.tenant_id', '2', false);
INSERT INTO rls_demo (tenant_id, label) VALUES (2, 'two-A');

-- Assertion 1: tenant 2 sees only its own row.
SELECT set_config('aegis.tenant_id', '2', false);
DO $$
DECLARE c INT;
BEGIN
  SELECT count(*) INTO c FROM rls_demo;
  IF c <> 1 THEN RAISE EXCEPTION 'RLS leak: tenant 2 saw % rows (expected 1)', c; END IF;
END$$;

-- Assertion 2: tenant 1 sees only its two rows.
SELECT set_config('aegis.tenant_id', '1', false);
DO $$
DECLARE c INT;
BEGIN
  SELECT count(*) INTO c FROM rls_demo;
  IF c <> 2 THEN RAISE EXCEPTION 'RLS leak: tenant 1 saw % rows (expected 2)', c; END IF;
END$$;

-- Assertion 3: WITH CHECK blocks writing another tenant's row.
SELECT set_config('aegis.tenant_id', '1', false);
DO $$
BEGIN
  BEGIN
    INSERT INTO rls_demo (tenant_id, label) VALUES (2, 'cross-tenant');
    RAISE EXCEPTION 'RLS WITH CHECK did not block a cross-tenant insert';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN
    -- expected: policy rejected the write
    NULL;
  END;
END$$;

DROP TABLE rls_demo;
\echo '[rls_test] RLS tenant isolation verified.'
