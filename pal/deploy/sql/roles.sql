-- =============================================================================
-- PAL database roles: least-privilege separation (run as a DBA / superuser).
-- =============================================================================
-- Two roles:
--   owner role : owns the schema and tables; used ONLY by the migrate job
--                (DATABASE_URL_MIGRATIONS). It can alter tables, so it can disable
--                the audit triggers. Keep its credentials out of the running app.
--   app role   : what the web and worker processes connect as (DATABASE_URL).
--                Reads and writes data, but cannot alter or drop tables and cannot
--                UPDATE, DELETE or TRUNCATE the audit trail.
--
-- Idempotent. Run once before the first migration, and AGAIN AFTER EVERY MIGRATION
-- so new tables get the right grants and the audit revokes are re-applied:
--
--   psql "$ADMIN_URL" -v ON_ERROR_STOP=1 \
--        -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal \
--        -f deploy/sql/roles.sql
--
-- Passwords are deliberately not set here. Set them with \password, or use your
-- platform's identity integration, so they never land in a file or shell history.
-- =============================================================================

\set ON_ERROR_STOP on

SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT', :'owner_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'owner_role') \gexec

SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_role') \gexec

-- Database and schema ownership.
SELECT format('ALTER DATABASE %I OWNER TO %I', :'db_name', :'owner_role') \gexec
SELECT format('ALTER SCHEMA public OWNER TO %I', :'owner_role') \gexec
REVOKE ALL ON SCHEMA public FROM PUBLIC;
SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'app_role') \gexec
SELECT format('REVOKE CREATE ON SCHEMA public FROM %I', :'app_role') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', :'db_name', :'app_role') \gexec

-- Existing objects created by migrations must belong to the owner role.
SELECT format('ALTER TABLE public.%I OWNER TO %I', tablename, :'owner_role')
FROM pg_tables WHERE schemaname = 'public' AND tableowner <> :'owner_role' \gexec
SELECT format('ALTER SEQUENCE public.%I OWNER TO %I', sequencename, :'owner_role')
FROM pg_sequences WHERE schemaname = 'public' AND sequenceowner <> :'owner_role'
  AND NOT EXISTS (  -- identity sequences follow their table's owner
      SELECT 1 FROM pg_depend d JOIN pg_class c ON c.oid = d.objid
      WHERE c.relname = pg_sequences.sequencename AND d.deptype = 'i') \gexec
SELECT format('ALTER FUNCTION public.%I() OWNER TO %I', p.proname, :'owner_role')
FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
WHERE n.nspname = 'public' AND p.proname LIKE 'pal\_%' \gexec

-- Data access for the app role on current and future tables.
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO %I', :'app_role') \gexec
SELECT format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO %I', :'app_role') \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I', :'owner_role', :'app_role') \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO %I', :'owner_role', :'app_role') \gexec

-- The audit trail is append-only for the application.
SELECT format('REVOKE UPDATE, DELETE, TRUNCATE ON public.audit_event FROM %I', :'app_role')
WHERE EXISTS (SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = 'audit_event') \gexec

-- Migration bookkeeping is the owner's business only.
SELECT format('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.doctrine_migration_versions FROM %I', :'app_role')
WHERE EXISTS (SELECT 1 FROM pg_tables WHERE schemaname = 'public' AND tablename = 'doctrine_migration_versions') \gexec
