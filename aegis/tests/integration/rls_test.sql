-- Integration test: prove the Row-Level Security pattern from
-- database/tenancy/rls_template.sql actually isolates tenants against a live
-- PostgreSQL. Run with ON_ERROR_STOP=1; any failed assertion aborts (exit != 0).
--
-- IMPORTANT: superusers (and BYPASSRLS roles) ALWAYS bypass RLS, even with FORCE.
-- So the isolation assertions MUST run as the non-superuser runtime role
-- (aegis_app). This mirrors production: RLS only protects when the app connects
-- as the least-privilege role — never as a superuser. The table is created and
-- seeded as the owner (RLS bypassed there, which is fine for setup).
\set ON_ERROR_STOP on

CREATE SCHEMA IF NOT EXISTS aegis;
SET search_path TO aegis, public;

DROP TABLE IF EXISTS rls_demo;
CREATE TABLE rls_demo (id SERIAL PRIMARY KEY, tenant_id BIGINT NOT NULL, label TEXT);

ALTER TABLE rls_demo ENABLE ROW LEVEL SECURITY;
ALTER TABLE rls_demo FORCE  ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON rls_demo
  USING      (tenant_id = current_setting('aegis.tenant_id', true)::bigint)
  WITH CHECK (tenant_id = current_setting('aegis.tenant_id', true)::bigint);

-- The runtime role needs DML on the table (default privileges from roles.sql may
-- already cover this; grant explicitly so the test is self-contained).
GRANT SELECT, INSERT ON rls_demo TO aegis_app;
GRANT USAGE, SELECT ON SEQUENCE rls_demo_id_seq TO aegis_app;

-- Seed as the owner (superuser bypasses RLS — fine for setup).
INSERT INTO rls_demo (tenant_id, label) VALUES (1, 'one-A'), (1, 'one-B'), (2, 'two-A');

-- From here on, act as the non-superuser runtime role so RLS is enforced.
SET ROLE aegis_app;

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
DO $$
BEGIN
  BEGIN
    INSERT INTO rls_demo (tenant_id, label) VALUES (2, 'cross-tenant');
    RAISE EXCEPTION 'RLS WITH CHECK did not block a cross-tenant insert';
  EXCEPTION WHEN check_violation OR insufficient_privilege THEN
    NULL; -- expected: policy rejected the write
  END;
END$$;

RESET ROLE;
DROP TABLE rls_demo;
\echo '[rls_test] RLS tenant isolation verified (enforced for the non-superuser runtime role).'
