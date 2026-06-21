/**
 * users.js — Username/password user store for jessicarojas1.github.io
 *
 * SECURITY NOTE: This is a CLIENT-SIDE-ONLY demo gate (no backend). It does NOT
 * provide real authentication and is NOT a security control — anyone can read
 * this file and any value in localStorage via the browser. Treat every
 * account/role decision made here as advisory UI state only, never a security
 * boundary. Because of that, NO bootstrap/default credential is shipped in
 * source (a published password — or even a published hash — would be readable
 * and brute-forceable by anyone). Instead, on first run the operator creates
 * the initial admin account themselves and only its salted hash is stored.
 *
 * Users are stored in localStorage as
 *   { username, salt, passwordHash, role, created }
 * Passwords are hashed with SHA-256 over (salt + password) using the Web Crypto
 * API — no plaintext is ever stored. Legacy records without a `salt` field are
 * still verified against an unsalted SHA-256 for backward compatibility.
 * Account requests stored under 'rbac_requests'.
 *
 * First-run flow: when no user exists (`hasUsers()` is false) the login UI
 * shows a one-time "create initial admin" form (see roles.js) that calls
 * `createInitialAdmin(username, password)`.
 *
 * Public API:
 *   seed(), hasUsers(), getUsers(), authenticate(), createInitialAdmin(),
 *   addUser(), deleteUser(), changePassword(), updateRole(), sha256(),
 *   requestAccess(), getRequests(), approveRequest(), rejectRequest()
 */
const Users = (() => {

  const STORE_KEY = 'rbac_users';
  const REQ_KEY   = 'rbac_requests';

  /* ── SHA-256 via Web Crypto ─────────────────────────────────── */
  async function sha256(str) {
    if (typeof crypto === 'undefined' || !crypto.subtle) {
      throw new Error('Web Crypto API is unavailable. Please access this site over HTTPS (not file://).');
    }
    try {
      const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
      return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
    } catch (e) {
      throw new Error('Password hashing failed: ' + (e.message || String(e)));
    }
  }

  /* ── Random salt (hex) via Web Crypto ───────────────────────── */
  function newSalt() {
    if (typeof crypto === 'undefined' || !crypto.getRandomValues) {
      throw new Error('Web Crypto API is unavailable. Please access this site over HTTPS (not file://).');
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
  }

  /* Hash a password for storage with a fresh salt → { salt, passwordHash } */
  async function hashWithSalt(password) {
    const salt = newSalt();
    return { salt, passwordHash: await sha256(salt + password) };
  }

  /* Verify a password against a stored user record (salted or legacy). */
  async function verifyPassword(user, password) {
    if (!user) return false;
    if (user.salt) return (await sha256(user.salt + password)) === user.passwordHash;
    // Legacy record (no salt) — verify against unsalted SHA-256.
    return (await sha256(password)) === user.passwordHash;
  }

  /* ── Storage helpers ─────────────────────────────────────────── */
  function getUsers() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY) || '[]'); }
    catch { return []; }
  }

  function saveUsers(users) {
    localStorage.setItem(STORE_KEY, JSON.stringify(users));
  }

  /* True once at least one account exists. When false, the UI shows the
     first-run "create initial admin" setup form instead of the login form. */
  function hasUsers() { return getUsers().length > 0; }

  /* ── Seed — no bootstrap credential is shipped; nothing to seed ──── */
  async function seed() { return true; }

  /* ── Create the initial admin account (first-run only) ────────────
     Allowed only while no users exist, so it cannot be abused to mint
     extra admins later. Stores only the salted hash. */
  async function createInitialAdmin(username, password) {
    username = (username || '').trim();
    if (hasUsers()) return { ok: false, error: 'Setup already completed.' };
    if (!username || !password) return { ok: false, error: 'Username and password are required.' };
    if (password.length < 8) return { ok: false, error: 'Password must be at least 8 characters.' };
    const { salt, passwordHash } = await hashWithSalt(password);
    saveUsers([{ username, salt, passwordHash, role: 'admin', created: Date.now() }]);
    return { ok: true };
  }

  /* ── Authenticate against localStorage users ──────────────────── */
  async function authenticate(username, password) {
    if (!username || !password) return null;
    const uname = username.trim().toLowerCase();
    const users = getUsers();
    const user  = users.find(u => u.username.toLowerCase() === uname);
    if (!user) return null;
    return (await verifyPassword(user, password)) ? user : null;
  }

  /* ── Add user — returns { ok, error? } ──────────────────────── */
  async function addUser(username, password, role) {
    username = (username || '').trim();
    if (!username || !password || !role) return { ok: false, error: 'All fields required.' };
    const users = getUsers();
    if (users.find(u => u.username.toLowerCase() === username.toLowerCase()))
      return { ok: false, error: 'Username already exists.' };
    const { salt, passwordHash } = await hashWithSalt(password);
    users.push({ username, salt, passwordHash, role, created: Date.now() });
    saveUsers(users);
    return { ok: true };
  }

  /* ── Delete user ────────────────────────────────────────────── */
  function deleteUser(username) {
    saveUsers(getUsers().filter(u => u.username.toLowerCase() !== username.toLowerCase()));
  }

  /* ── Change password ────────────────────────────────────────── */
  async function changePassword(username, newPassword) {
    if (!newPassword) return { ok: false, error: 'Password required.' };
    const users = getUsers();
    const user  = users.find(u => u.username.toLowerCase() === username.toLowerCase());
    if (!user) return { ok: false, error: 'User not found.' };
    const { salt, passwordHash } = await hashWithSalt(newPassword);
    user.salt = salt;
    user.passwordHash = passwordHash;
    saveUsers(users);
    return { ok: true };
  }

  /* ── Update role ────────────────────────────────────────────── */
  function updateRole(username, role) {
    const users = getUsers();
    const user  = users.find(u => u.username.toLowerCase() === username.toLowerCase());
    if (!user) return { ok: false, error: 'User not found.' };
    user.role = role;
    saveUsers(users);
    return { ok: true };
  }

  /* ── Request access (public — from login modal) ──────────────── */
  async function requestAccess(username, password, note) {
    username = (username || '').trim();
    if (!username || !password) return { ok: false, error: 'Username and password are required.' };
    if (getUsers().find(u => u.username.toLowerCase() === username.toLowerCase()))
      return { ok: false, error: 'Username already exists.' };
    const reqs = getRequests();
    if (reqs.find(r => r.username.toLowerCase() === username.toLowerCase()))
      return { ok: false, error: 'A request for this username is already pending.' };
    const { salt, passwordHash } = await hashWithSalt(password);
    reqs.push({ username, salt, passwordHash, note: (note || '').trim(), requested: Date.now() });
    saveRequests(reqs);
    return { ok: true };
  }

  /* ── Request store ───────────────────────────────────────────── */
  function getRequests() {
    try { return JSON.parse(localStorage.getItem(REQ_KEY) || '[]'); }
    catch { return []; }
  }

  function saveRequests(reqs) {
    localStorage.setItem(REQ_KEY, JSON.stringify(reqs));
  }

  /* ── Approve request — creates the user account ─────────────── */
  function approveRequest(username, role) {
    const reqs = getRequests();
    const req  = reqs.find(r => r.username.toLowerCase() === username.toLowerCase());
    if (!req) return { ok: false, error: 'Request not found.' };
    const users = getUsers();
    if (users.find(u => u.username.toLowerCase() === username.toLowerCase())) {
      saveRequests(reqs.filter(r => r.username.toLowerCase() !== username.toLowerCase()));
      return { ok: false, error: 'Username already exists as a user.' };
    }
    users.push({ username: req.username, salt: req.salt, passwordHash: req.passwordHash, role: role || 'viewer', created: Date.now() });
    saveUsers(users);
    saveRequests(reqs.filter(r => r.username.toLowerCase() !== username.toLowerCase()));
    return { ok: true };
  }

  /* ── Reject request ─────────────────────────────────────────── */
  function rejectRequest(username) {
    saveRequests(getRequests().filter(r => r.username.toLowerCase() !== username.toLowerCase()));
    return { ok: true };
  }

  /* ── Public API ──────────────────────────────────────────────── */
  return {
    seed, hasUsers, getUsers, authenticate, createInitialAdmin,
    addUser, deleteUser, changePassword, updateRole, sha256,
    requestAccess, getRequests, approveRequest, rejectRequest,
  };

})();
