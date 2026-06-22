'use strict';
/* CITADEL — optional Postgres backing (durable, multi-instance state).
 *
 * Activates only when DATABASE_URL is set. When unset, the app keeps its
 * in-memory / JSON-file behavior (free-tier default) and this module reports
 * disabled — every caller falls back gracefully.
 *
 * Managed Postgres (Render/Supabase/RDS) generally requires TLS. By default we
 * enable TLS but do not verify the server certificate (rejectUnauthorized:false)
 * because managed providers often present chains that don't resolve against the
 * system trust store. For a verified connection set PGSSL_VERIFY=1 and/or supply
 * the provider CA via PGSSL_CA (a PEM string or a path to a .pem file). Tables
 * are created on init().
 */
let Pool = null;
try { ({ Pool } = require('pg')); } catch (e) { /* pg not installed -> stays disabled */ }
const fs = require('fs');
const { AsyncLocalStorage } = require('async_hooks');

const URL = process.env.DATABASE_URL || '';
let pool = null;

// Ambient per-request tenant context (schema-per-tenant isolation, H5). When a
// request runs inside runInTenant(schema, fn), query() transparently routes to
// that Postgres schema; outside any tenant scope (the default, single-tenant
// deployment) query() is exactly pool.query as before — zero behavior change.
const tenantCtx = new AsyncLocalStorage();
function currentSchema() { const s = tenantCtx.getStore(); return s && s.schema; }
function runInTenant(schema, fn) { return tenantCtx.run({ schema }, fn); }

// Quote (and strictly validate) a SQL identifier for safe interpolation into
// statements that can't be parameterized (schema names in SET search_path /
// CREATE SCHEMA). Belt-and-suspenders over the tenant-slug validation upstream:
// anything not matching a conservative identifier shape is rejected outright.
function quoteIdent(name) {
  if (typeof name !== 'string' || !/^[a-z_][a-z0-9_]{0,62}$/.test(name)) {
    throw new Error('unsafe SQL identifier: ' + String(name));
  }
  return '"' + name + '"';
}

// Build the pg `ssl` option from env. Verification is opt-in (PGSSL_VERIFY=1) or
// implied when a CA is provided; otherwise we keep the permissive managed-PG
// default so existing deployments don't break.
function sslOption(url) {
  const wantSsl = process.env.PGSSL === '0' ? false
    : (process.env.PGSSL === '1' || /sslmode=require/i.test(url) || /\.(render\.com|supabase\.co|rds\.amazonaws\.com)/i.test(url));
  if (!wantSsl) return false;
  let ca = process.env.PGSSL_CA || null;
  if (ca && !/-----BEGIN/.test(ca)) { try { ca = fs.readFileSync(ca, 'utf8'); } catch (e) { ca = null; } }
  // Secure-by-default: VERIFY the server certificate unless explicitly disabled.
  // Known managed providers (Render/Supabase/RDS) often present chains that don't
  // resolve against the system trust store, so they stay permissive (with a prod
  // warning) to avoid breaking existing deployments — supply PGSSL_CA / set
  // PGSSL_VERIFY=1 to verify them too.
  const managed = /\.(render\.com|supabase\.co|rds\.amazonaws\.com)/i.test(url);
  let verify;
  if (process.env.PGSSL_VERIFY === '1' || !!ca) verify = true;
  else if (process.env.PGSSL_VERIFY === '0') verify = false;
  else verify = !managed;
  if (!verify && process.env.NODE_ENV === 'production') {
    console.warn('[citadel] SECURITY: Postgres TLS certificate verification is OFF (managed-provider default). Supply PGSSL_CA or set PGSSL_VERIFY=1 to verify the server certificate (NIST SC-8).');
  }
  const opt = { rejectUnauthorized: verify };
  if (ca) opt.ca = ca;
  return opt;
}

if (URL && Pool) {
  pool = new Pool({
    connectionString: URL,
    ssl: sslOption(URL),
    max: parseInt(process.env.PG_POOL_MAX || '5', 10),
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 8000
  });
  pool.on('error', (e) => console.error(JSON.stringify({ level: 'error', src: 'db', msg: 'pool error', err: e.message })));
}

function enabled() { return !!pool; }

// Tenant-aware query. With no ambient tenant scope this is a plain pool.query
// (the single-tenant default — unchanged). Inside runInTenant() it checks out a
// dedicated client, scopes search_path to the tenant schema for the duration of
// the query, then resets it before returning the client to the pool so the
// search_path can never leak to the next checkout.
async function query(text, params) {
  const schema = currentSchema();
  if (!schema) return pool.query(text, params);
  const client = await pool.connect();
  try {
    await client.query('SET search_path TO ' + quoteIdent(schema) + ', public');
    return await client.query(text, params);
  } finally {
    try { await client.query('SET search_path TO public'); } catch (e) { /* reset best-effort */ }
    client.release();
  }
}

// Provision a tenant: create its schema and apply the full table DDL inside it.
// Idempotent (CREATE SCHEMA IF NOT EXISTS + the IF NOT EXISTS table DDL).
async function applySchemaTo(schema) {
  if (!pool) return false;
  const ident = quoteIdent(schema);
  const client = await pool.connect();
  try {
    await client.query('CREATE SCHEMA IF NOT EXISTS ' + ident);
    await client.query('SET search_path TO ' + ident);
    await client.query(SCHEMA);
  } finally {
    try { await client.query('SET search_path TO public'); } catch (e) { /* reset best-effort */ }
    client.release();
  }
  return true;
}

const SCHEMA = `
CREATE TABLE IF NOT EXISTS citadel_settings (
  key   text PRIMARY KEY,
  value jsonb NOT NULL
);
CREATE TABLE IF NOT EXISTS citadel_users (
  id            text PRIMARY KEY,
  name          text,
  email         text UNIQUE NOT NULL,
  role          text NOT NULL,
  active        boolean NOT NULL DEFAULT true,
  salt          text NOT NULL,
  pass          text NOT NULL,
  permissions   jsonb NOT NULL DEFAULT '{}'::jsonb,
  must_change_password boolean NOT NULL DEFAULT false,
  created_at    timestamptz NOT NULL DEFAULT now()
);
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
CREATE TABLE IF NOT EXISTS citadel_revoked (
  jti text PRIMARY KEY,
  exp bigint NOT NULL
);
CREATE TABLE IF NOT EXISTS citadel_audit (
  seq    bigserial PRIMARY KEY,
  ts     timestamptz NOT NULL DEFAULT now(),
  type   text NOT NULL,
  actor  text,
  ip     text,
  detail text,
  ok     boolean NOT NULL DEFAULT true
);
CREATE INDEX IF NOT EXISTS citadel_audit_ts_idx ON citadel_audit (seq DESC);
CREATE INDEX IF NOT EXISTS citadel_sessions_user_idx ON citadel_sessions (user_id);

CREATE TABLE IF NOT EXISTS citadel_scans (
  id        bigserial PRIMARY KEY,
  ts        timestamptz NOT NULL DEFAULT now(),
  user_id   text,
  user_email text,
  source    text,
  engine    text,
  grade     text,
  security  int,
  quality   int,
  findings  int,
  critical  int,
  high      int,
  files     int,
  report    jsonb NOT NULL
);
CREATE INDEX IF NOT EXISTS citadel_scans_ts_idx ON citadel_scans (id DESC);

-- Projects — named groupings a scan can be filed under (owner-scoped).
CREATE TABLE IF NOT EXISTS citadel_projects (
  id          bigserial PRIMARY KEY,
  user_id     text,
  name        text NOT NULL,
  description text,
  created_at  timestamptz NOT NULL DEFAULT now()
);

-- Shared finding triage state, keyed by the canonical finding fingerprint, so a
-- disposition (accepted / false-positive / remediated / n-a) set by one user is
-- visible to all and survives browsers. 'open' rows are deleted.
CREATE TABLE IF NOT EXISTS citadel_dispositions (
  fingerprint text PRIMARY KEY,
  state       text NOT NULL,
  actor       text,
  updated_at  timestamptz NOT NULL DEFAULT now()
);

-- MFA columns (idempotent for upgrades of an existing citadel_users table).
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_enabled boolean NOT NULL DEFAULT false;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_secret  text;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_pending text;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_backup  jsonb NOT NULL DEFAULT '[]'::jsonb;

-- Tamper-evident hash-chain columns on the audit log (idempotent for upgrades).
-- Each row binds the previous row's hash; verifyChain() re-walks to detect edits.
ALTER TABLE citadel_audit ADD COLUMN IF NOT EXISTS prev_hash text;
ALTER TABLE citadel_audit ADD COLUMN IF NOT EXISTS hash      text;

-- Optional human name for a saved scan so it can be found by name in history.
ALTER TABLE citadel_scans ADD COLUMN IF NOT EXISTS name text;

-- Project association for a scan (idempotent for upgrades of existing tables).
ALTER TABLE citadel_scans ADD COLUMN IF NOT EXISTS project_id   text;
ALTER TABLE citadel_scans ADD COLUMN IF NOT EXISTS project_name text;
CREATE INDEX IF NOT EXISTS citadel_scans_project_idx ON citadel_scans (project_id);
`;

async function init() {
  if (!pool) return false;
  await pool.query(SCHEMA);
  return true;
}

async function close() { if (pool) await pool.end(); }

module.exports = {
  enabled, query, init, close, SCHEMA,
  // Schema-per-tenant primitives (H5).
  runInTenant, currentSchema, applySchemaTo, quoteIdent
};
