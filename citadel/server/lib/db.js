'use strict';
/* CITADEL — optional Postgres backing (durable, multi-instance state).
 *
 * Activates only when DATABASE_URL is set. When unset, the app keeps its
 * in-memory / JSON-file behavior (free-tier default) and this module reports
 * disabled — every caller falls back gracefully.
 *
 * Managed Postgres (Render/Supabase/RDS) generally requires TLS; we enable it
 * with rejectUnauthorized:false unless PGSSL=0. Tables are created on init().
 */
let Pool = null;
try { ({ Pool } = require('pg')); } catch (e) { /* pg not installed -> stays disabled */ }

const URL = process.env.DATABASE_URL || '';
let pool = null;

if (URL && Pool) {
  const wantSsl = process.env.PGSSL === '0' ? false
    : (process.env.PGSSL === '1' || /sslmode=require/i.test(URL) || /\.(render\.com|supabase\.co|rds\.amazonaws\.com)/i.test(URL));
  pool = new Pool({
    connectionString: URL,
    ssl: wantSsl ? { rejectUnauthorized: false } : false,
    max: parseInt(process.env.PG_POOL_MAX || '5', 10),
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 8000
  });
  pool.on('error', (e) => console.error(JSON.stringify({ level: 'error', src: 'db', msg: 'pool error', err: e.message })));
}

function enabled() { return !!pool; }
async function query(text, params) { return pool.query(text, params); }

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

-- MFA columns (idempotent for upgrades of an existing citadel_users table).
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_enabled boolean NOT NULL DEFAULT false;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_secret  text;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_pending text;
ALTER TABLE citadel_users ADD COLUMN IF NOT EXISTS mfa_backup  jsonb NOT NULL DEFAULT '[]'::jsonb;
`;

async function init() {
  if (!pool) return false;
  await pool.query(SCHEMA);
  return true;
}

async function close() { if (pool) await pool.end(); }

module.exports = { enabled, query, init, close, SCHEMA };
