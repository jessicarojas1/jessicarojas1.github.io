-- Integration test: prove the least-privilege runtime role (database/roles.sql)
-- can do DML but NOT DDL. Run as the superuser AFTER roles.sql has been applied
-- and the aegis_app password set. ON_ERROR_STOP aborts on an unexpected error.
\set ON_ERROR_STOP on
SET search_path TO aegis, public;

-- A scratch table owned by the superuser; grant DML to aegis_app like real tables.
CREATE TABLE IF NOT EXISTS aegis.roles_demo (id SERIAL PRIMARY KEY, v TEXT);
GRANT SELECT, INSERT, UPDATE, DELETE ON aegis.roles_demo TO aegis_app;
GRANT USAGE, SELECT ON SEQUENCE aegis.roles_demo_id_seq TO aegis_app;

-- Run the next block AS aegis_app.
SET ROLE aegis_app;

-- Assertion 1: DML is allowed.
DO $$
BEGIN
  INSERT INTO aegis.roles_demo (v) VALUES ('dml-ok');
END$$;

-- Assertion 2: DDL (CREATE) is denied for the runtime role.
DO $$
BEGIN
  BEGIN
    EXECUTE 'CREATE TABLE aegis.should_not_exist (id INT)';
    RAISE EXCEPTION 'least-privilege FAIL: runtime role was able to CREATE a table';
  EXCEPTION WHEN insufficient_privilege THEN
    NULL; -- expected
  END;
END$$;

-- Assertion 3: DROP is denied for the runtime role.
DO $$
BEGIN
  BEGIN
    EXECUTE 'DROP TABLE aegis.roles_demo';
    RAISE EXCEPTION 'least-privilege FAIL: runtime role was able to DROP a table';
  EXCEPTION WHEN insufficient_privilege THEN
    NULL; -- expected
  END;
END$$;

RESET ROLE;
DROP TABLE aegis.roles_demo;
\echo '[roles_test] runtime role is DML-only (no DDL/DROP). Verified.'
