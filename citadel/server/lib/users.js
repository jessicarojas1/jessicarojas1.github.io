'use strict';
/* CITADEL backend — persisted user store + page-level RBAC.
 * JSON-file backed (CITADEL_DATA_DIR, default under the scratch dir). Passwords
 * are scrypt-hashed. Seeds a default admin and a stable JWT secret on first run.
 * Mirrors the client PAGES/ROLES so the same permission ids apply end to end.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');

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
function load() {
  if (_db) return _db;
  try { _db = JSON.parse(fs.readFileSync(FILE, 'utf8')); } catch (e) { _db = null; }
  if (!_db || !Array.isArray(_db.users)) _db = { users: [], settings: { enforce: false }, secret: null };
  let changed = false;
  if (!_db.secret) { _db.secret = process.env.CITADEL_JWT_SECRET || crypto.randomBytes(32).toString('hex'); changed = true; }
  if (!_db.settings) { _db.settings = { enforce: false }; changed = true; }
  if (!_db.users.some(u => u.role === 'admin')) {
    const email = (process.env.CITADEL_ADMIN_EMAIL || 'admin@citadel.local').toLowerCase();
    const pw = process.env.CITADEL_ADMIN_PASSWORD || 'citadel-admin';
    const salt = newSalt();
    _db.users.unshift({
      id: uid(), name: 'Administrator', email, role: 'admin', active: true,
      salt, pass: hashPw(pw, salt), permissions: Object.assign({}, ROLES.admin.perms),
      createdAt: new Date().toISOString()
    });
    changed = true;
  }
  if (changed) save();
  return _db;
}
function save() { try { fs.mkdirSync(DATA_DIR, { recursive: true }); fs.writeFileSync(FILE, JSON.stringify(_db, null, 2)); } catch (e) { /* ephemeral fs */ } }

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
  const db = load(); const u = db.users.find(x => x.id === id); if (!u) throw new Error('User not found.');
  u.salt = newSalt(); u.pass = hashPw(password, u.salt); save();
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
  PAGES, ROLES, secret, settings, setSetting,
  list, get, getByEmail: e => strip(getByEmail(e)), add, update, setPermission, remove, setPassword,
  verifyPassword, can, _file: FILE
};
