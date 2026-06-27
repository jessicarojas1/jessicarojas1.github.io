-- ============================================================================
-- CITADEL — database schema (PostgreSQL)
-- ----------------------------------------------------------------------------
-- This is a MANUAL-SETUP REFERENCE. The AUTHORITATIVE installer is the backend
-- itself: citadel/server/lib/db.js holds the canonical `SCHEMA` constant and
-- runs it on boot via init() whenever DATABASE_URL is set. Keep this file in
-- sync with that constant whenever a migration/column is added.
--
-- Everything here is fully IDEMPOTENT (CREATE TABLE/INDEX IF NOT EXISTS,
-- ALTER TABLE ... ADD COLUMN IF NOT EXISTS), so it is safe to run repeatedly
-- against a fresh OR an existing database — including a SHARED database, since
-- every object is prefixed `citadel_` and nothing is ever dropped or truncated.
--
-- Usage:  psql "$DATABASE_URL" -f citadel/database/schema.sql
-- When DATABASE_URL is unset, CITADEL runs on its JSON-file store and needs
-- none of this.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Settings — key/value app configuration (e.g. { enforce, branding }).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_settings (
  key   text PRIMARY KEY,
  value jsonb NOT NULL
);

-- ----------------------------------------------------------------------------
-- Users — accounts, roles, permission overrides, password hash + MFA.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_users (
  id                   text PRIMARY KEY,
  name                 text,
  email                text UNIQUE NOT NULL,
  role                 text NOT NULL,
  active               boolean NOT NULL DEFAULT true,
  salt                 text NOT NULL,
  pass                 text NOT NULL,
  permissions          jsonb NOT NULL DEFAULT '{}'::jsonb,
  must_change_password boolean NOT NULL DEFAULT false,
  created_at           timestamptz NOT NULL DEFAULT now()
);

-- MFA columns (idempotent for upgrades of an existing citadel_users table).
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_enabled boolean NOT NULL DEFAULT false;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_secret  text;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_pending text;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_backup  jsonb NOT NULL DEFAULT '[]'::jsonb;

-- ----------------------------------------------------------------------------
-- Sessions & revocation — active JWT sessions and the revoked-jti denylist.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_sessions (
  jti        text PRIMARY KEY,
  user_id    text,
  email      text,
  role       text,
  ip         text,
  ua         text,
  iat        bigint,
  exp        bigint,
  first_seen timestamptz NOT NULL DEFAULT now(),
  last_seen  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS citadel_sessions_user_idx ON citadel_sessions (user_id);

CREATE TABLE IF NOT EXISTS citadel_revoked (
  jti text PRIMARY KEY,
  exp bigint NOT NULL
);

-- ----------------------------------------------------------------------------
-- Audit log — security-relevant events (logins, lockouts, scans, admin acts).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_audit (
  seq    bigserial PRIMARY KEY,
  ts     timestamptz NOT NULL DEFAULT now(),
  type   text NOT NULL,
  actor  text,
  ip     text,
  detail text,
  ok     boolean NOT NULL DEFAULT true,
  -- Tamper-evident hash chain: hash = SHA-256(prev_hash + canonical(row)).
  -- Altering or deleting any row breaks the chain; verified via verifyChain().
  prev_hash text,
  hash      text
);
CREATE INDEX IF NOT EXISTS citadel_audit_ts_idx ON citadel_audit (seq DESC);
-- Idempotent upgrade path for an existing citadel_audit table.
ALTER TABLE citadel_audit ADD COLUMN IF NOT EXISTS prev_hash text;
ALTER TABLE citadel_audit ADD COLUMN IF NOT EXISTS hash      text;

-- ----------------------------------------------------------------------------
-- Scan history — per-scan summary + full report JSON for re-download.
-- Pruned by the app to CITADEL_SCAN_HISTORY_MAX most-recent rows.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_scans (
  id         bigserial PRIMARY KEY,
  ts         timestamptz NOT NULL DEFAULT now(),
  user_id    text,
  user_email text,
  source     text,
  engine     text,
  grade      text,
  security   int,
  quality    int,
  findings   int,
  critical   int,
  high       int,
  files      int,
  report     jsonb NOT NULL
);
CREATE INDEX IF NOT EXISTS citadel_scans_ts_idx ON citadel_scans (id DESC);
-- Optional human name for a saved scan so it can be found by name in history.
ALTER TABLE citadel_scans ADD COLUMN IF NOT EXISTS name text;
-- Project association for a scan (idempotent for upgrades of existing tables).
ALTER TABLE citadel_scans ADD COLUMN IF NOT EXISTS project_id   text;
ALTER TABLE citadel_scans ADD COLUMN IF NOT EXISTS project_name text;
CREATE INDEX IF NOT EXISTS citadel_scans_project_idx ON citadel_scans (project_id);
-- Compact readiness summary so a readiness trend can be read from history
-- without fetching each full report (idempotent for upgrades).
ALTER TABLE citadel_scans ADD COLUMN IF NOT EXISTS readiness_decision text;
ALTER TABLE citadel_scans ADD COLUMN IF NOT EXISTS readiness_overall  int;

-- ----------------------------------------------------------------------------
-- Projects — named groupings a scan can be filed under (owner-scoped).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_projects (
  id          bigserial PRIMARY KEY,
  user_id     text,
  name        text NOT NULL,
  description text,
  created_at  timestamptz NOT NULL DEFAULT now()
);

-- ----------------------------------------------------------------------------
-- Finding dispositions — shared triage state keyed by canonical fingerprint.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_dispositions (
  fingerprint text PRIMARY KEY,
  state       text NOT NULL,
  actor       text,
  updated_at  timestamptz NOT NULL DEFAULT now()
);

-- Reviewer note stored alongside a disposition (idempotent for upgrades).
ALTER TABLE citadel_dispositions ADD COLUMN IF NOT EXISTS note text;

-- ----------------------------------------------------------------------------
-- Dependency-approval workflow — shared sign-off decision for a package, keyed
-- by `ecosystem|name` (lowercased). status approved/restricted/prohibited/pending,
-- with separate security/license approval flags. 'pending' resets are deleted.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_dep_approvals (
  key              text PRIMARY KEY,
  status           text NOT NULL,
  justification    text,
  approver         text,
  security_approved boolean NOT NULL DEFAULT false,
  license_approved  boolean NOT NULL DEFAULT false,
  updated_at       timestamptz NOT NULL DEFAULT now()
);

-- ----------------------------------------------------------------------------
-- Threat-model overlay — per-project reviewer additions/edits/deletions layered
-- over the generated STRIDE model, stored whole as a JSON blob keyed by project.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_threatmodel (
  project_id text PRIMARY KEY,
  data       jsonb NOT NULL,
  actor      text,
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- ----------------------------------------------------------------------------
-- Tenant registry (schema-per-tenant multi-tenancy; OPT-IN, CITADEL_MULTITENANT=1)
-- Lives in the public schema and maps a tenant slug to its dedicated Postgres
-- schema (citadel_t_<slug>), which holds its own copy of all the tables above.
-- Created/managed by citadel/server/lib/tenancy.js. Single-tenant deployments
-- (the default) never touch this and use the public-schema tables directly.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citadel_tenants (
  slug        text PRIMARY KEY,
  name        text,
  schema_name text NOT NULL,
  active      boolean NOT NULL DEFAULT true,
  created_at  timestamptz NOT NULL DEFAULT now()
);
