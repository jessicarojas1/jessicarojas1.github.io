'use strict';
/* CITADEL backend — persisted user store + page-level RBAC.
 * Durable store: Postgres when DATABASE_URL is set (shared across instances),
 * otherwise a JSON file under CITADEL_DATA_DIR (free-tier default). Either way an
 * in-memory cache backs the synchronous read API; mutations write through to the
 * durable store. Passwords are scrypt-hashed. Seeds a default admin + JWT secret.
 * Mirrors the client PAGES/ROLES so the same permission ids apply end to end.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const db = require('./db');

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

function hashPw(password, salt) { return crypto.scryptSync(String(password), salt, 32).toString('hex'); }
function newSalt() { return crypto.randomBytes(16).toString('hex'); }
function uid() { return 'u' + Date.now().toString(36) + crypto.randomBytes(3).toString('hex'); }

let _db = null;

// Seed a fresh store skeleton: JWT secret + default admin (flagged to force a
// password change when the publicly-known default is used).
function seed(dbObj) {
  let changed = false;
  if (!dbObj.secret) { dbObj.secret = process.env.CITADEL_JWT_SECRET || crypto.randomBytes(32).toString('hex'); changed = true; }
  if (!dbObj.settings) { dbObj.settings = { enforce: false }; changed = true; }
  if (!dbObj.users.some(u => u.role === 'admin')) {
    const email = (process.env.CITADEL_ADMIN_EMAIL || 'admin@citadel.local').toLowerCase();
    const usingDefault = !process.env.CITADEL_ADMIN_PASSWORD;
    const pw = process.env.CITADEL_ADMIN_PASSWORD || 'citadel-admin';
    if (usingDefault && process.env.NODE_ENV === 'production') {
      console.warn('[citadel] SECURITY: seeding the DEFAULT admin password in production. ' +
        'Set CITADEL_ADMIN_PASSWORD (and change it after first login) — the default is publicly known.');
    }
    const salt = newSalt();
    dbObj.users.unshift({
      id: uid(), name: 'Administrator', email, role: 'admin', active: true,
      salt, pass: hashPw(pw, salt), permissions: Object.assign({}, ROLES.admin.perms),
      mustChange: usingDefault, createdAt: new Date().toISOString()
    });
    changed = true;
  }
  return changed;
}

/* ---- File-backed path (no DATABASE_URL) ---- */
function loadFile() {
  if (_db) return _db;
  try { _db = JSON.parse(fs.readFileSync(FILE, 'utf8')); } catch (e) { _db = null; }
  if (!_db || !Array.isArray(_db.users)) _db = { users: [], settings: { enforce: false }, secret: null };
  if (seed(_db)) saveFile();
  return _db;
}
let _warnedSave = false;
function saveFile() {
  try { fs.mkdirSync(DATA_DIR, { recursive: true }); fs.writeFileSync(FILE, JSON.stringify(_db, null, 2)); }
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
    createdAt: (r.created_at instanceof Date ? r.created_at.toISOString() : r.created_at)
  };
}
async function loadPg() {
  const s = await db.query('SELECT key, value FROM citadel_settings');
  let secret = null; const settingsRow = {};
  for (const r of s.rows) { if (r.key === 'secret') secret = r.value && r.value.v; else if (r.key === 'app') Object.assign(settingsRow, r.value || {}); }
  const u = await db.query('SELECT * FROM citadel_users ORDER BY created_at');
  _db = { users: u.rows.map(rowToUser), settings: Object.assign({ enforce: false }, settingsRow), secret };
  if (seed(_db)) await syncPg();   // persist a freshly-seeded secret/admin
  return _db;
}
// Full write-through of the (small) user set + settings + secret. Idempotent.
async function syncPg() {
  await db.query(`INSERT INTO citadel_settings(key,value) VALUES('secret',$1)
    ON CONFLICT(key) DO UPDATE SET value=$1`, [JSON.stringify({ v: _db.secret })]);
  await db.query(`INSERT INTO citadel_settings(key,value) VALUES('app',$1)
    ON CONFLICT(key) DO UPDATE SET value=$1`, [JSON.stringify(_db.settings || {})]);
  for (const u of _db.users) {
    await db.query(`INSERT INTO citadel_users
      (id,name,email,role,active,salt,pass,permissions,must_change_password,created_at)
      VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)
      ON CONFLICT(id) DO UPDATE SET
        name=$2,email=$3,role=$4,active=$5,salt=$6,pass=$7,permissions=$8,must_change_password=$9`,
      [u.id, u.name, u.email, u.role, u.active, u.salt, u.pass,
       JSON.stringify(u.permissions || {}), !!u.mustChange, u.createdAt || new Date().toISOString()]);
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

function strip(u) { if (!u) return null; const { pass, salt, ...rest } = u; return rest; }
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
function update(id, patch) {
  const db = load(); const u = db.users.find(x => x.id === id); if (!u) throw new Error('User not found.');
  if ('name' in patch) u.name = patch.name;
  if ('email' in patch) u.email = String(patch.email).trim().toLowerCase();
  if ('role' in patch && ROLES[patch.role]) { u.role = patch.role; if (patch.resetPerms) u.permissions = Object.assign({}, ROLES[patch.role].perms); }
  if ('active' in patch) u.active = !!patch.active;
  if ('permissions' in patch && patch.permissions) u.permissions = patch.permissions;
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
function setPassword(id, password) {
  const store = load(); const u = store.users.find(x => x.id === id); if (!u) throw new Error('User not found.');
  if (!password || String(password).length < 8) throw new Error('Password must be at least 8 characters.');
  u.salt = newSalt(); u.pass = hashPw(password, u.salt); u.mustChange = false; save();
}
// Self-service change: verify the current password, then set a new one.
function changeOwnPassword(id, current, next) {
  const store = load(); const u = store.users.find(x => x.id === id); if (!u) throw new Error('User not found.');
  const h = hashPw(current, u.salt); const a = Buffer.from(h), b = Buffer.from(u.pass);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) throw new Error('Current password is incorrect.');
  setPassword(id, next);
}
function verifyPassword(email, password) {
  const u = getByEmail(email);
  if (!u || !u.active) return null;
  const h = hashPw(password, u.salt);
  const a = Buffer.from(h), b = Buffer.from(u.pass);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  return strip(u);
}
function can(user, pageId) {
  if (!user) return false;
  if (user.role === 'admin') return true;
  return !!(user.permissions && user.permissions[pageId]);
}

module.exports = {
  PAGES, ROLES, init, secret, settings, setSetting,
  list, get, getByEmail: e => strip(getByEmail(e)), add, update, setPermission, remove, setPassword,
  changeOwnPassword, verifyPassword, can, backend: () => (db.enabled() ? 'postgres' : 'file'), _file: FILE
};
