/* CITADEL — Users, Roles & page-level Access Control (client-side).
 * A self-contained RBAC layer stored in localStorage: manage users, assign
 * roles, and grant/deny permission at the page level. Passwords are salted +
 * SHA-256 hashed via Web Crypto. window.CITADEL.auth
 *
 * NOTE: client-side auth is for demonstration & UX gating, not a security
 * boundary — the real trust boundary is the deep-scan backend. Enforce server
 * checks there for anything sensitive.
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const UKEY = 'citadel.users.v1';
  const SKEY = 'citadel.session.v1';
  const CKEY = 'citadel.acl.v1';

  // The granular, page-level permission catalog.
  const PAGES = [
    { id: 'analyze',         label: 'Run scans (upload/drop)', group: 'Analyzer' },
    { id: 'deepscan',        label: 'Deep scan & scan-by-URL', group: 'Analyzer' },
    { id: 'tab-report',      label: 'Report',        group: 'Reports' },
    { id: 'tab-overview',    label: 'Overview',      group: 'Reports' },
    { id: 'tab-findings',    label: 'Findings',      group: 'Reports' },
    { id: 'tab-compliance',  label: 'Compliance',    group: 'Reports' },
    { id: 'tab-sbom',        label: 'SBOM',          group: 'Reports' },
    { id: 'tab-binary',      label: 'Binaries',      group: 'Reports' },
    { id: 'tab-quality',     label: 'Quality',       group: 'Reports' },
    { id: 'tab-deploy',      label: 'Deployment',    group: 'Reports' },
    { id: 'tab-aifix',       label: 'AI Fix Prompt', group: 'Reports' },
    { id: 'tab-history',     label: 'History',       group: 'Reports' },
    { id: 'tab-export',      label: 'Export',        group: 'Reports' },
    { id: 'docs',            label: 'Documentation', group: 'Docs' },
    { id: 'admin-users',     label: 'Manage users',  group: 'Admin' },
    { id: 'admin-perms',     label: 'Manage permissions', group: 'Admin' },
    { id: 'admin-settings',  label: 'Access settings', group: 'Admin' }
  ];
  const ALL = PAGES.map(p => p.id);

  function permsFrom(ids) { const o = {}; ALL.forEach(p => o[p] = ids.indexOf(p) >= 0); return o; }
  const REPORTS = PAGES.filter(p => p.group === 'Reports').map(p => p.id);

  const ROLES = {
    admin:   { label: 'Administrator', perms: permsFrom(ALL) },
    analyst: { label: 'Security Analyst', perms: permsFrom(['analyze', 'deepscan', 'docs'].concat(REPORTS)) },
    auditor: { label: 'Auditor', perms: permsFrom(['docs', 'tab-report', 'tab-findings', 'tab-compliance', 'tab-sbom', 'tab-export', 'tab-history']) },
    viewer:  { label: 'Viewer', perms: permsFrom(['docs', 'tab-report', 'tab-findings', 'tab-compliance', 'tab-export']) }
  };

  /* ---------- storage ---------- */
  function read(k, d) { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch (e) { return d; } }
  function write(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function users() { return read(UKEY, []); }
  function saveUsers(u) { write(UKEY, u); }

  /* ---------- crypto ---------- */
  function rndHex(n) { const a = new Uint8Array(n); (root.crypto || {}).getRandomValues ? crypto.getRandomValues(a) : a.forEach((_, i) => a[i] = Math.floor(Math.random() * 256)); return [...a].map(b => b.toString(16).padStart(2, '0')).join(''); }
  async function hash(password, salt) {
    const data = new TextEncoder().encode(salt + '|' + password);
    if (root.crypto && crypto.subtle) {
      const buf = await crypto.subtle.digest('SHA-256', data);
      return [...new Uint8Array(buf)].map(b => b.toString(16).padStart(2, '0')).join('');
    }
    // Fallback (non-crypto) — demo only.
    let h = 0; for (let i = 0; i < data.length; i++) { h = (h * 31 + data[i]) >>> 0; } return 'x' + h.toString(16);
  }

  /* ---------- seed default admin ---------- */
  const DEFAULT_ADMIN = { email: 'admin@citadel.local', password: 'citadel-admin' };
  const ready = (async function init() {
    let list = users();
    if (!list.some(u => u.role === 'admin')) {
      const salt = rndHex(8);
      list.unshift({
        id: 'u' + Date.now(), name: 'Administrator', email: DEFAULT_ADMIN.email,
        role: 'admin', active: true, salt, pass: await hash(DEFAULT_ADMIN.password, salt),
        permissions: Object.assign({}, ROLES.admin.perms), createdAt: new Date().toISOString()
      });
      saveUsers(list);
    }
    if (read(CKEY, null) === null) write(CKEY, { enforce: false });
    return true;
  })();

  /* ---------- public API ---------- */
  function strip(u) { if (!u) return null; const { pass, salt, ...rest } = u; return rest; }
  function listUsers() { return users().map(strip); }
  function getUser(id) { return strip(users().find(u => u.id === id)); }

  async function addUser({ name, email, role, password, permissions }) {
    const list = users();
    email = String(email || '').trim().toLowerCase();
    if (!email || list.some(u => u.email === email)) throw new Error('A user with that email already exists.');
    role = ROLES[role] ? role : 'viewer';
    const salt = rndHex(8);
    const u = {
      id: 'u' + Date.now() + rndHex(2), name: name || email, email, role, active: true,
      salt, pass: await hash(password || rndHex(8), salt),
      permissions: permissions || Object.assign({}, ROLES[role].perms),
      createdAt: new Date().toISOString()
    };
    list.push(u); saveUsers(list); return strip(u);
  }
  function removeUser(id) {
    let list = users();
    const u = list.find(x => x.id === id);
    if (u && u.role === 'admin' && list.filter(x => x.role === 'admin' && x.active).length <= 1) {
      throw new Error('Cannot remove the last active administrator.');
    }
    saveUsers(list.filter(x => x.id !== id));
    const s = read(SKEY, null); if (s === id) write(SKEY, null);
  }
  function updateUser(id, patch) {
    const list = users(); const u = list.find(x => x.id === id); if (!u) return;
    if ('name' in patch) u.name = patch.name;
    if ('email' in patch) u.email = String(patch.email).trim().toLowerCase();
    if ('role' in patch && ROLES[patch.role]) { u.role = patch.role; if (patch.resetPerms) u.permissions = Object.assign({}, ROLES[patch.role].perms); }
    if ('active' in patch) u.active = !!patch.active;
    if ('permissions' in patch) u.permissions = patch.permissions;
    saveUsers(list);
  }
  function setPermission(id, pageId, val) {
    const list = users(); const u = list.find(x => x.id === id); if (!u) return;
    u.permissions = u.permissions || {}; u.permissions[pageId] = !!val; saveUsers(list);
  }
  function setActive(id, val) { updateUser(id, { active: val }); }
  async function setPassword(id, password) {
    const list = users(); const u = list.find(x => x.id === id); if (!u) return;
    u.salt = rndHex(8); u.pass = await hash(password, u.salt); saveUsers(list);
  }

  async function authenticate(email, password) {
    email = String(email || '').trim().toLowerCase();
    const u = users().find(x => x.email === email);
    if (!u || !u.active) return null;
    const h = await hash(password, u.salt);
    return h === u.pass ? strip(u) : null;
  }
  async function loginByCreds(email, password) {
    const u = await authenticate(email, password);
    if (u) { write(SKEY, u.id); }
    return u;
  }
  function login(id) { write(SKEY, id); }
  function logout() { write(SKEY, null); }
  function current() { const id = read(SKEY, null); return id ? getUser(id) : null; }

  function can(pageId, user) {
    user = user || current();
    if (!user) return false;
    if (user.role === 'admin') return true;
    return !!(user.permissions && user.permissions[pageId]);
  }

  function settings() { return read(CKEY, { enforce: false }); }
  function setSetting(k, v) { const s = settings(); s[k] = v; write(CKEY, s); }

  CITADEL.auth = {
    ready, PAGES, ROLES, DEFAULT_ADMIN,
    listUsers, getUser, addUser, removeUser, updateUser, setPermission, setActive, setPassword,
    authenticate, loginByCreds, login, logout, current, can, settings, setSetting
  };
})(window);
