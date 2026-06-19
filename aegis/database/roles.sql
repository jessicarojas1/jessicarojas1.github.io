-- AEGIS — Database least-privilege roles
-- NIST 800-53 AC-6 / CMMC AC.L2-3.1.5 (least privilege), SC-8/CM-5.
--
-- PURPOSE
--   Separate the privileged OWNER/migration role (DDL: CREATE/ALTER/DROP) from
--   the RUNTIME role the application connects as (DML only). A SQL-injection or
--   application compromise then cannot alter or drop schema objects, add triggers,
--   or disable constraints — it is confined to the data operations the app needs.
--
-- HOW TO USE
--   1. Apply the schema + migrations as the OWNER (superuser or the schema owner),
--      e.g. via install.php or docker/initdb.sh.  The owner keeps DDL rights and
--      is used ONLY for installs/migrations — never as the app's connection.
--   2. Run THIS script ONCE as that owner/superuser to create the runtime role.
--   3. Point the application at the runtime role:
--        DATABASE_URL=postgres://aegis_app:<password>@host:5432/aegis
--      (or DB_USER=aegis_app / DB_PASS=...).  Keep the owner creds for migrations
--      in a separate, restricted secret used only by the installer/CI.
--
-- NOTE
--   index.php contains idempotent runtime "self-healing" ALTER TABLE guards. Under
--   the DML-only runtime role these become harmless no-ops (they fail closed and
--   are caught). Apply real schema changes through migrations run as the owner.

-- ---------------------------------------------------------------------------
-- 1) Runtime application role (DML only, no DDL, no DROP/TRUNCATE)
-- ---------------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'aegis_app') THEN
    -- Set a strong password out-of-band:  ALTER ROLE aegis_app PASSWORD '...';
    CREATE ROLE aegis_app LOGIN;
  END IF;
END$$;

-- Connect + read the schema, but NOT create objects in it.
GRANT USAGE ON SCHEMA aegis, public TO aegis_app;

-- DML on existing tables/sequences.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES    IN SCHEMA aegis TO aegis_app;
GRANT USAGE, SELECT, UPDATE          ON ALL SEQUENCES IN SCHEMA aegis TO aegis_app;

-- Same DML on tables/sequences created by FUTURE migrations (run as owner).
ALTER DEFAULT PRIVILEGES IN SCHEMA aegis
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES    TO aegis_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA aegis
  GRANT USAGE, SELECT, UPDATE          ON SEQUENCES TO aegis_app;

-- Defense in depth: the runtime role must never hold DDL/ownership.
-- (CREATE on the schema is not granted above; nothing further to revoke for a
--  fresh role, but be explicit in case the role pre-existed.)
REVOKE CREATE ON SCHEMA aegis FROM aegis_app;

-- ---------------------------------------------------------------------------
-- 2) OPTIONAL: make the audit log append-only (WORM) at the database level
-- ---------------------------------------------------------------------------
-- Strongly recommended for CUI / regulated / legal-hold deployments. With this,
-- even a fully compromised application cannot modify or delete audit history —
-- complementing the keyed HMAC chain (which detects tampering) by PREVENTING it.
--
-- Trade-off: the admin "audit log retention/prune" feature (a DELETE on
-- activity_log) will then fail for the app role. Run retention as a separate,
-- audited maintenance role on a schedule instead. Uncomment to enable:
--
--   REVOKE UPDATE, DELETE, TRUNCATE ON aegis.activity_log FROM aegis_app;
--   -- INSERT + SELECT remain, so the app can still append and read the log.
