/**
 * users.js — Username/password user store for jessicarojas1.github.io
 * Users are stored in localStorage as { username, passwordHash, role, created }.
 * Passwords are hashed with SHA-256 (Web Crypto API) — no plaintext ever stored.
 *
 * Default seeded admin on first load:
 *   username : admin
 *   password : Admin@2026!
 *   role     : admin
 *
 * Public API: Users.seed(), Users.getUsers(), Users.authenticate(),
 *             Users.addUser(), Users.deleteUser(), Users.changePassword(),
 *             Users.updateRole(), Users.sha256()
 */
const Users = (() => {

  const STORE_KEY   = 'rbac_users';
  const DEFAULT_PW  = 'Basketball#44.';
  const DEFAULT_USER = 'jrojas1';

  /* ── SHA-256 via Web Crypto ─────────────────────────────────── */
  async function sha256(str) {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
    return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
  }

  /* ── Storage helpers ─────────────────────────────────────────── */
  function getUsers() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY) || '[]'); }
    catch { return []; }
  }

  function saveUsers(users) {
    localStorage.setItem(STORE_KEY, JSON.stringify(users));
  }

  /* ── Seed default admin on first run ────────────────────────── */
  async function seed() {
    const users = getUsers();
    if (!users.length) {
      const hash = await sha256(DEFAULT_PW);
      saveUsers([{
        username    : DEFAULT_USER,
        passwordHash: hash,
        role        : 'admin',
        created     : Date.now(),
      }]);
    }
  }

  /* ── Authenticate — returns user object or null ─────────────── */
  async function authenticate(username, password) {
    if (!username || !password) return null;
    const users = getUsers();
    const user  = users.find(u => u.username.toLowerCase() === username.trim().toLowerCase());
    if (!user) return null;
    const hash = await sha256(password);
    return hash === user.passwordHash ? user : null;
  }

  /* ── Add user — returns { ok, error? } ──────────────────────── */
  async function addUser(username, password, role) {
    username = (username || '').trim();
    if (!username || !password || !role) return { ok: false, error: 'All fields required.' };
    const users = getUsers();
    if (users.find(u => u.username.toLowerCase() === username.toLowerCase())) {
      return { ok: false, error: 'Username already exists.' };
    }
    const hash = await sha256(password);
    users.push({ username, passwordHash: hash, role, created: Date.now() });
    saveUsers(users);
    return { ok: true };
  }

  /* ── Delete user ─────────────────────────────────────────────── */
  function deleteUser(username) {
    saveUsers(getUsers().filter(u => u.username.toLowerCase() !== username.toLowerCase()));
  }

  /* ── Change password — returns { ok, error? } ───────────────── */
  async function changePassword(username, newPassword) {
    if (!newPassword) return { ok: false, error: 'Password required.' };
    const users = getUsers();
    const user  = users.find(u => u.username.toLowerCase() === username.toLowerCase());
    if (!user) return { ok: false, error: 'User not found.' };
    user.passwordHash = await sha256(newPassword);
    saveUsers(users);
    return { ok: true };
  }

  /* ── Update role — returns { ok, error? } ───────────────────── */
  function updateRole(username, role) {
    const users = getUsers();
    const user  = users.find(u => u.username.toLowerCase() === username.toLowerCase());
    if (!user) return { ok: false, error: 'User not found.' };
    user.role = role;
    saveUsers(users);
    return { ok: true };
  }

  /* ── Public API ──────────────────────────────────────────────── */
  return { seed, getUsers, authenticate, addUser, deleteUser, changePassword, updateRole, sha256 };

})();
