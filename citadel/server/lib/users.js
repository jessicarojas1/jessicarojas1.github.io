'use strict';
/* CITADEL backend — persisted user store + page-level RBAC.
 * Durable store: Postgres when DATABASE_URL is set (shared across instances),
 * otherwise a JSON file under CITADEL_DATA_DIR (free-tier default). Either way an
 * in-memory cache backs the synchronous read API; mutations write through to the
 * durable store. Passwords are scrypt-hashed (PBKDF2-HMAC-SHA256 under FIPS mode;
 * hashes are self-describing so both verify). Seeds a default admin + JWT secret.
 * Mirrors the client PAGES/ROLES so the same permission ids apply end to end.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const db = require('./db');
const totp = require('./totp');
const secretbox = require('./secretbox');
const fips = require('./fips');

// Fields that must be encrypted at rest (TOTP seeds — possession yields valid
// 2FA codes). Sealed on write to the durable store, opened back to plaintext in
// the in-memory cache so the synchronous read API is unchanged.
const USER_SECRET_FIELDS = ['mfaSecret', 'mfaPending'];

const DATA_DIR = process.env.CITADEL_DATA_DIR || path.join(process.env.CITADEL_TMP || os.tmpdir(), 'citadel');
const FILE = path.join(DATA_DIR, 'users.json');

const PAGES = [
  { id: 'analyze', label: 'Run scans (upload/drop)', group: 'Analyzer' },
  { id: 'deepscan', label: 'Deep scan & scan-by-URL', group: 'Analyzer' },
  { id: 'tab-report', label: 'Report', group: 'Reports' },
  { id: 'tab-overview', label: 'Overview', group: 'Reports' },
  { id: 'tab-findings', label: 'Findings', group: 'Reports' },
  { id: 'tab-compliance', label: 'Compliance', group: 'Reports' },
  { id: 'tab-sbom', label: 'SBOM', group: 'Reports' },
  { id: 'tab-binary', label: 'Binaries', group: 'Reports' },
  { id: 'tab-quality', label: 'Quality', group: 'Reports' },
  { id: 'tab-deploy', label: 'Deployment', group: 'Reports' },
  { id: 'tab-aifix', label: 'AI Fix Prompt', group: 'Reports' },
  { id: 'tab-history', label: 'History', group: 'Reports' },
  { id: 'tab-export', label: 'Export', group: 'Reports' },
  { id: 'docs', label: 'Documentation', group: 'Docs' },
  { id: 'admin-users', label: 'Manage users', group: 'Admin' },
  { id: 'admin-perms', label: 'Manage permissions', group: 'Admin' },
  { id: 'admin-settings', label: 'Access settings', group: 'Admin' }
];
const ALL = PAGES.map(p => p.id);
const REPORTS = PAGES.filter(p => p.group === 'Reports').map(p => p.id);
const permsFrom = ids => { const o = {}; ALL.forEach(p => o[p] = ids.indexOf(p) >= 0); return o; };
const ROLES = {
  admin: { label: 'Administrator', perms: permsFrom(ALL) },
  analyst: { label: 'Security Analyst', perms: permsFrom(['analyze', 'deepscan', 'docs'].concat(REPORTS)) },
  auditor: { label: 'Auditor', perms: permsFrom(['docs', 'tab-report', 'tab-findings', 'tab-compliance', 'tab-sbom', 'tab-export', 'tab-history']) },
  viewer: { label: 'Viewer', perms: permsFrom(['docs', 'tab-report', 'tab-findings', 'tab-compliance', 'tab-export']) }
};

// Password hashing is KDF-agnostic and self-describing so the algorithm can
// switch (e.g. when FIPS mode turns on) without a schema change or breaking
// existing accounts. Stored forms:
//   - legacy scrypt:  bare 64-hex (no prefix)         — what older records hold
//   - pbkdf2 (FIPS):  "pbkdf2$<iterations>$<64-hex>"  — SP 800-132, FIPS-approved
function hashPw(password, salt) {
  if (fips.active()) {
    const iter = fips.pbkdf2Iterations();
    return 'pbkdf2$' + iter + '$' + crypto.pbkdf2Sync(String(password), salt, iter, 32, 'sha256').toString('hex');
  }
  return crypto.scryptSync(String(password), salt, 32).toString('hex');
}
// Recompute the stored hash's KDF over a candidate and timing-safe compare.
// Fails closed (false) on any KDF error — e.g. a legacy scrypt hash under a FIPS
// OpenSSL that refuses scrypt — rather than throwing into the auth path.
function verifyHash(password, salt, stored) {
  if (!stored) return false;
  let computed;
  try {
    if (stored.startsWith('pbkdf2$')) {
      const iter = parseInt(stored.split('$')[1], 10);
      if (!Number.isFinite(iter) || iter < 1) return false;
      computed = 'pbkdf2$' + iter + '$' + crypto.pbkdf2Sync(String(password), salt, iter, 32, 'sha256').toString('hex');
    } else {
      computed = crypto.scryptSync(String(password), salt, 32).toString('hex');
    }
  } catch (e) { return false; }
  const a = Buffer.from(computed), b = Buffer.from(stored);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
function newSalt() { return crypto.randomBytes(16).toString('hex'); }
function uid() { return 'u' + Date.now().toString(36) + crypto.randomBytes(3).toString('hex'); }

let _db = null;

// Seed a fresh store skeleton: JWT secret + default admin (flagged to force a
// password change when the publicly-known default is used).
function seed(dbObj) {
  let changed = false;
  // Signing key: prefer an env-provided secret and DO NOT persist it at rest
  // (reduces exposure if the store is read). Only a generated fallback is stored,
  // so sessions still survive restarts when no stable secret is configured.
  if (process.env.CITADEL_JWT_SECRET) {
    dbObj.secret = process.env.CITADEL_JWT_SECRET;          // in-memory only; not persisted
  } else if (!dbObj.secret) {
    dbObj.secret = crypto.randomBytes(32).toString('hex'); changed = true;
    if (process.env.NODE_ENV === 'production') console.warn('[citadel] SECURITY: no CITADEL_JWT_SECRET set — a random signing key was generated and stored AT REST. Set CITADEL_JWT_SECRET (ideally from a secrets manager) so the key is not persisted and survives restarts.');
  }
  // Secure-by-default: access control ON for a fresh store. An explicit,
  // audited opt-out (CITADEL_ALLOW_OPEN=1) is required to ship an open instance.
  if (!dbObj.settings) { dbObj.settings = { enforce: process.env.CITADEL_ALLOW_OPEN === '1' ? false : true }; changed = true; }
  if (!dbObj.users.some(u => u.role === 'admin')) {
    const email = (process.env.CITADEL_ADMIN_EMAIL || 'admin@citadel.local').toLowerCase();
    const usingDefault = !process.env.CITADEL_ADMIN_PASSWORD;
    let pw = process.env.CITADEL_ADMIN_PASSWORD || 'citadel-admin';
    // In production, never seed the publicly-known default: generate a random
    // strong first-boot password and print it ONCE. (Dev keeps the easy default.)
    if (usingDefault && process.env.NODE_ENV === 'production') {
      pw = crypto.randomBytes(15).toString('base64').replace(/[+/=]/g, '').slice(0, 18);
      console.warn('[citadel] SECURITY: no CITADEL_ADMIN_PASSWORD set — seeded a RANDOM first-boot admin password (you must change it on first login): ' + pw);
    }
    const salt = newSalt();
    dbObj.users.unshift({
      id: uid(), name: 'Administrator', email, role: 'admin', active: true,
      salt, pass: hashPw(pw, salt), permissions: Object.assign({}, ROLES.admin.perms),
      mustChange: true, createdAt: new Date().toISOString()
    });
    changed = true;
  }
  return changed;
}

/* ---- At-rest encryption helpers (envelope, see lib/secretbox.js) ---- */
// A storage-facing copy of _db with the JWT secret + per-user TOTP seeds sealed.
// Operates on a copy so the in-memory cache stays plaintext for the sync API.
function sealedSnapshot() {
  return {
    ..._db,
    secret: secretbox.seal(_db.secret),
    users: _db.users.map(u => {
      const c = { ...u };
      for (const f of USER_SECRET_FIELDS) if (c[f]) c[f] = secretbox.seal(c[f]);
      return c;
    })
  };
}
// Decrypt sealed fields in place on the in-memory cache after a load.
function hydrate(dbObj) {
  if (!dbObj) return dbObj;
  if (secretbox.isSealed(dbObj.secret)) dbObj.secret = secretbox.open(dbObj.secret);
  for (const u of (dbObj.users || [])) {
    for (const f of USER_SECRET_FIELDS) if (secretbox.isSealed(u[f])) u[f] = secretbox.open(u[f]);
  }
  return dbObj;
}

/* ---- File-backed path (no DATABASE_URL) ---- */
function loadFile() {
  if (_db) return _db;
  try { _db = JSON.parse(fs.readFileSync(FILE, 'utf8')); } catch (e) { _db = null; }
  if (!_db || !Array.isArray(_db.users)) _db = { users: [], settings: { enforce: false }, secret: null };
  hydrate(_db);
  if (seed(_db)) saveFile();
  return _db;
}
let _warnedSave = false;
function saveFile() {
  try { fs.mkdirSync(DATA_DIR, { recursive: true }); fs.writeFileSync(FILE, JSON.stringify(sealedSnapshot(), null, 2)); }
  catch (e) {
    if (!_warnedSave) {
      _warnedSave = true;
      console.warn('[citadel] WARNING: cannot persist user store to ' + FILE + ' (' + e.code + '). ' +
        'Accounts and the JWT secret will reset on restart. Set CITADEL_DATA_DIR to a writable persistent disk or DATABASE_URL.');
    }
  }
}

/* ---- Postgres path (DATABASE_URL set) — durable, shared across instances ---- */
function rowToUser(r) {
  return {
    id: r.id, name: r.name, email: r.email, role: r.role, active: r.active,
    salt: r.salt, pass: r.pass, permissions: r.permissions || {},
    mustChange: !!r.must_change_password,
    mfaEnabled: !!r.mfa_enabled, mfaSecret: secretbox.open(r.mfa_secret || null),
    mfaPending: secretbox.open(r.mfa_pending || null), mfaBackup: r.mfa_backup || [],
    createdAt: (r.created_at instanceof Date ? r.created_at.toISOString() : r.created_at)
  };
}
async function loadPg() {
  const s = await db.query('SELECT key, value FROM citadel_settings');
  let secret = null; const settingsRow = {};
  for (const r of s.rows) { if (r.key === 'secret') secret = secretbox.open(r.value && r.value.v); else if (r.key === 'app') Object.assign(settingsRow, r.value || {}); }
  const u = await db.query('SELECT * FROM citadel_users ORDER BY created_at');
  _db = { users: u.rows.map(rowToUser), settings: Object.assign({ enforce: false }, settingsRow), secret };
  if (seed(_db)) await syncPg();   // persist a freshly-seeded secret/admin
  return _db;
}
// Full write-through of the (small) user set + settings + secret. Idempotent.
async function syncPg() {
  await db.query(`INSERT INTO citadel_settings(key,value) VALUES('secret',$1)
    ON CONFLICT(key) DO UPDATE SET value=$1`, [JSON.stringify({ v: secretbox.seal(_db.secret) })]);
  await db.query(`INSERT INTO citadel_settings(key,value) VALUES('app',$1)
    ON CONFLICT(key) DO UPDATE SET value=$1`, [JSON.stringify(_db.settings || {})]);
  for (const u of _db.users) {
    await db.query(`INSERT INTO citadel_users
      (id,name,email,role,active,salt,pass,permissions,must_change_password,mfa_enabled,mfa_secret,mfa_pending,mfa_backup,created_at)
      VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14)
      ON CONFLICT(id) DO UPDATE SET
        name=$2,email=$3,role=$4,active=$5,salt=$6,pass=$7,permissions=$8,must_change_password=$9,
        mfa_enabled=$10,mfa_secret=$11,mfa_pending=$12,mfa_backup=$13`,
      [u.id, u.name, u.email, u.role, u.active, u.salt, u.pass,
       JSON.stringify(u.permissions || {}), !!u.mustChange,
       !!u.mfaEnabled, secretbox.seal(u.mfaSecret || null), secretbox.seal(u.mfaPending || null), JSON.stringify(u.mfaBackup || []),
       u.createdAt || new Date().toISOString()]);
  }
  const ids = _db.users.map(u => u.id);
  if (ids.length) await db.query('DELETE FROM citadel_users WHERE NOT (id = ANY($1))', [ids]);
  else await db.query('DELETE FROM citadel_users');
}

function load() { return _db || loadFile(); }
// Mutation persistence: PG write-through (fire-and-forget) or file save.
function save() {
  if (db.enabled()) { syncPg().catch(e => console.error(JSON.stringify({ level: 'error', src: 'users', msg: 'pg sync failed', err: e.message }))); }
  else { saveFile(); }
}

// One-time async bootstrap; must be awaited before serving requests.
async function init() {
  if (db.enabled()) { await loadPg(); } else { loadFile(); }
  return _db;
}

function strip(u) {
  if (!u) return null;
  const { pass, salt, mfaSecret, mfaPending, mfaBackup, ...rest } = u;
  rest.mfaEnabled = !!u.mfaEnabled;          // expose status, never the secret/backup codes
  return rest;
}
function secret() { return load().secret; }
function settings() { return Object.assign({ enforce: false }, load().settings); }
function setSetting(k, v) { const db = load(); db.settings[k] = v; save(); return settings(); }

function list() { return load().users.map(strip); }
function get(id) { return strip(load().users.find(u => u.id === id)); }
function getRaw(id) { return load().users.find(u => u.id === id); }
function getByEmail(email) { email = String(email || '').toLowerCase(); return load().users.find(u => u.email === email); }

function add({ name, email, role, password, permissions }) {
  const db = load();
  email = String(email || '').trim().toLowerCase();
  if (!email) throw new Error('Email is required.');
  if (db.users.some(u => u.email === email)) throw new Error('A user with that email already exists.');
  role = ROLES[role] ? role : 'viewer';
  const salt = newSalt();
  const u = {
    id: uid(), name: name || email, email, role, active: true,
    salt, pass: hashPw(password || crypto.randomBytes(8).toString('hex'), salt),
    permissions: permissions || Object.assign({}, ROLES[role].perms),
    createdAt: new Date().toISOString()
  };
  db.users.push(u); save(); return strip(u);
}
// Just-in-time provisioning for SSO/OIDC. Finds the user by email or creates one
// (random password — they authenticate via the IdP, not a local password).
function upsertSsoUser({ email, name, role }) {
  const store = load();
  email = String(email || '').trim().toLowerCase();
  if (!email) throw new Error('SSO identity missing email.');
  let u = store.users.find(x => x.email === email);
  if (u) {
    if (name && !u.name) u.name = name;
    u.sso = true; if (!u.active) u.active = true; save();
    return strip(u);
  }
  role = ROLES[role] ? role : 'viewer';
  const salt = newSalt();
  u = {
    id: uid(), name: name || email, email, role, active: true, sso: true,
    salt, pass: hashPw(crypto.randomBytes(24).toString('hex'), salt),
    permissions: Object.assign({}, ROLES[role].perms),
    createdAt: new Date().toISOString()
  };
  store.users.push(u); save();
  return strip(u);
}
function update(id, patch) {
  const db = load(); const u = db.users.find(x => x.id === id); if (!u) throw new Error('User not found.');
  if ('name' in patch) u.name = String(patch.name || '').trim() || u.name;
  if ('email' in patch) {
    const email = String(patch.email).trim().toLowerCase();
    if (!email || !email.includes('@')) throw new Error('A valid email is required.');
    if (db.users.some(x => x.id !== id && x.email === email)) throw new Error('A user with that email already exists.');
    u.email = email;
  }
  if ('role' in patch && ROLES[patch.role]) { u.role = patch.role; if (patch.resetPerms) u.permissions = Object.assign({}, ROLES[patch.role].perms); }
  if ('active' in patch) u.active = !!patch.active;
  if ('permissions' in patch && patch.permissions) {
    // Mass-assignment guard: accept ONLY known permission ids with boolean
    // values — never store arbitrary attacker-supplied keys/values.
    const clean = {};
    for (const p of ALL) clean[p] = !!patch.permissions[p];
    u.permissions = clean;
  }
  save(); return strip(u);
}
function setPermission(id, pageId, val) {
  const db = load(); const u = db.users.find(x => x.id === id); if (!u) throw new Error('User not found.');
  u.permissions = u.permissions || {}; u.permissions[pageId] = !!val; save(); return strip(u);
}
function remove(id) {
  const db = load(); const u = db.users.find(x => x.id === id); if (!u) return;
  if (u.role === 'admin' && db.users.filter(x => x.role === 'admin' && x.active).length <= 1) {
    throw new Error('Cannot remove the last active administrator.');
  }
  db.users = db.users.filter(x => x.id !== id); save();
}
function setPassword(id, password, forceChange) {
  const store = load(); const u = store.users.find(x => x.id === id); if (!u) throw new Error('User not found.');
  if (!password || String(password).length < 8) throw new Error('Password must be at least 8 characters.');
  // forceChange (admin resetting someone else) flags must-change so the user
  // sets their own password on next login and the admin never knows it. Self
  // service (changeOwnPassword) leaves it cleared.
  u.salt = newSalt(); u.pass = hashPw(password, u.salt); u.mustChange = !!forceChange; save();
}
// Self-service change: verify the current password, then set a new one.
function changeOwnPassword(id, current, next) {
  const store = load(); const u = store.users.find(x => x.id === id); if (!u) throw new Error('User not found.');
  if (!verifyHash(current, u.salt, u.pass)) throw new Error('Current password is incorrect.');
  setPassword(id, next);
}
function verifyPassword(email, password) {
  const u = getByEmail(email);
  if (!u || !u.active) return null;
  if (!verifyHash(password, u.salt, u.pass)) return null;
  return strip(u);
}
function can(user, pageId) {
  if (!user) return false;
  if (user.role === 'admin') return true;
  return !!(user.permissions && user.permissions[pageId]);
}

/* ---------------- MFA (TOTP) ---------------- */
function hashCode(c) { return crypto.createHash('sha256').update(String(c)).digest('hex'); }
function mfaEnabled(id) { const u = getRaw(id); return !!(u && u.mfaEnabled); }

// Step 1: mint a pending secret for enrollment (not active until verified).
function mfaBeginSetup(id) {
  const u = getRaw(id); if (!u) throw new Error('User not found.');
  u.mfaPending = totp.generateSecret(); save();
  return { secret: u.mfaPending, otpauth: totp.otpauthURL(u.mfaPending, u.email, 'CITADEL') };
}
// Step 2: verify a code against the pending secret, then activate + issue backup codes (shown once).
function mfaEnable(id, token) {
  const u = getRaw(id); if (!u) throw new Error('User not found.');
  if (!u.mfaPending) throw new Error('Start MFA setup first.');
  if (!totp.verify(u.mfaPending, token)) throw new Error('Invalid authenticator code.');
  u.mfaSecret = u.mfaPending; u.mfaPending = null; u.mfaEnabled = true;
  const plain = Array.from({ length: 10 }, () => crypto.randomBytes(5).toString('hex'));
  u.mfaBackup = plain.map(hashCode);
  save();
  return { backupCodes: plain };
}
function mfaDisable(id) {
  const u = getRaw(id); if (!u) throw new Error('User not found.');
  u.mfaEnabled = false; u.mfaSecret = null; u.mfaPending = null; u.mfaBackup = []; save();
}
// Verify a TOTP code OR consume a one-time backup code.
function mfaVerify(id, token) {
  const u = getRaw(id); if (!u || !u.mfaEnabled) return false;
  if (totp.verify(u.mfaSecret, token)) return true;
  const h = hashCode(String(token || '').replace(/\s+/g, ''));
  const idx = (u.mfaBackup || []).indexOf(h);
  if (idx >= 0) { u.mfaBackup.splice(idx, 1); save(); return true; }   // single-use
  return false;
}
function mfaStatus(id) { const u = getRaw(id); return { enabled: !!(u && u.mfaEnabled), backupRemaining: (u && u.mfaBackup ? u.mfaBackup.length : 0) }; }

module.exports = {
  PAGES, ROLES, init, secret, settings, setSetting,
  list, get, getByEmail: e => strip(getByEmail(e)), add, upsertSsoUser, update, setPermission, remove, setPassword,
  changeOwnPassword, verifyPassword, can,
  mfaEnabled, mfaBeginSetup, mfaEnable, mfaDisable, mfaVerify, mfaStatus,
  backend: () => (db.enabled() ? 'postgres' : 'file'), _file: FILE
};
