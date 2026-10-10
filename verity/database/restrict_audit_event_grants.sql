-- VERITY — restrict audit_event to INSERT/SELECT at the database-role level
-- =============================================================================
-- Manual-setup reference script. database/schema.sql cannot express this
-- itself (it depends on the deployment's role setup, not the schema) — see
-- docs/SECURITY.md's "Auditability" section for the full writeup, and the
-- callout at the bottom of database/schema.sql.
--
-- WHAT THIS DOES
-- `audit_event` is append-only by application convention today (no code
-- path ever issues UPDATE/DELETE against it), but that convention is not
-- enforced at the database layer. This script closes that gap for the one
-- role you name below: it revokes every privilege that role holds on
-- audit_event, then grants back exactly INSERT and SELECT. Idempotent and
-- safe to re-run.
--
-- VERIFIED BEHAVIOR (tested against a real PostgreSQL 16 instance before
-- this script was written, not assumed): REVOKE takes effect even when the
-- named role is the table's OWNER — ownership does not exempt a role from
-- explicit DML privilege checks in PostgreSQL. After this script runs, that
-- role's own UPDATE/DELETE statements against audit_event fail with
-- "permission denied for table audit_event", exactly like any other
-- unprivileged role.
--
-- WHAT THIS DOES **NOT** DO — read before relying on this as your only
-- control. If the role you name is also the table's OWNER (the common case
-- when one role runs both schema.sql/seed.php and ordinary app traffic — the
-- typical single-DATABASE_URL setup this app ships with), that role retains
-- its inherent, ownership-derived authority to GRANT itself privileges back
-- on its own object — also verified directly: `GRANT UPDATE ON audit_event
-- TO <owner_role>;` succeeds with no superuser needed, even right after this
-- script's REVOKE. That means this script is a real safety net against an
-- accidental UPDATE/DELETE from a future bug in application code (which
-- only ever issues ordinary DML, never GRANT statements), but it is **not**
-- defense-in-depth against a fully arbitrary-SQL-execution compromise (e.g.
-- a hypothetical future SQL-injection bug) — that class of attacker could
-- simply re-run GRANT before tampering with the log. Real defense-in-depth
-- against that threat model requires the application's runtime role to be a
-- role that does NOT own audit_event (or any table) — a role with no
-- inherent GRANT authority to undo this on its own. That is a larger change
-- (provisioning a second, non-owner role; splitting which connection string
-- is used for schema/seed operations vs. ordinary request traffic) and is
-- intentionally not done by this script or assumed on your behalf — it is a
-- decision for whoever owns this deployment's database role layout. This
-- script still provides real, verified value in the meantime: it is simply
-- scoped to "stop an application bug," not "survive a full compromise."
--
-- USAGE
--   psql "$DATABASE_URL" -v app_role=your_runtime_role_name \
--     -f database/restrict_audit_event_grants.sql
--
-- If you use the DB_SCHEMA isolation feature (Config::dbSchema() — sharing
-- this database with another app), also pass the schema name:
--   psql "$DATABASE_URL" -v app_role=your_runtime_role_name \
--     -v app_schema=verity -f database/restrict_audit_event_grants.sql
-- (app_schema defaults to "public" when not passed.)
-- =============================================================================

\set ON_ERROR_STOP on
\if :{?app_role}
\else
  \warn 'app_role not set — re-run with: psql "$DATABASE_URL" -v app_role=your_runtime_role_name -f database/restrict_audit_event_grants.sql'
  \quit
\endif
\if :{?app_schema}
\else
  \set app_schema public
\endif

SELECT format('Restricting %I.audit_event for role %I ...', :'app_schema', :'app_role') AS status \gset
\echo :status

REVOKE ALL PRIVILEGES ON TABLE :"app_schema".audit_event FROM :"app_role";
GRANT INSERT, SELECT ON TABLE :"app_schema".audit_event TO :"app_role";

-- Verify the result rather than assuming the GRANT above did what it says —
-- print exactly what this role can now do on audit_event.
SELECT grantee, privilege_type
FROM information_schema.table_privileges
WHERE table_schema = :'app_schema' AND table_name = 'audit_event' AND grantee = :'app_role'
ORDER BY privilege_type;

\echo 'Done. Confirm the result above reads exactly INSERT, SELECT — nothing else — for this role.'
\echo 'Reminder: if this role OWNS audit_event, it can still GRANT itself privileges back (see the header comment above). This script stops an application bug, not a full SQL-injection-class compromise.'
