/**
 * users.js — Username/password user store for jessicarojas1.github.io
 *
 * ROOT USER — always available, never stored in localStorage:
 *   Username : root
 *   Password : RootAdmin@2026!
 *   Role     : admin
 *   Use this to bootstrap the system, then create your real admin account.
 *
 * Users are stored in localStorage as { username, passwordHash, role, created }.
 * Passwords are hashed with SHA-256 (Web Crypto API) — no plaintext stored.
 * Account requests stored under 'rbac_requests'.
 *
 * Public API:
 *   seed(), hasUsers(), getUsers(), authenticate(),
 *   addUser(), deleteUser(), changePassword(), updateRole(), sha256(),
 *   requestAccess(), getRequests(), approveRequest(), rejectRequest(),
 *   ROOT_USERNAME
 */
const Users = (() => {

  const STORE_KEY = 'rbac_users';
  const REQ_KEY   = 'rbac_requests';

  /* ── Root user ────────────────────────────────────────────────────
     Hardcoded bootstrap account. Never stored in localStorage.
     Cannot be deleted or modified via the UI.
       Username : root
       Password : RootAdmin@2026!                                    */
  const ROOT_USERNAME = 'root';
  const ROOT_PASSWORD = 'RootAdmin@2026!';
  const ROOT_ROLE     = 'admin';

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

  /* ── Storage helpers ─────────────────────────────────────────── */
  function getUsers() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY) || '[]'); }
    catch { return []; }
  }

  function saveUsers(users) {
    localStorage.setItem(STORE_KEY, JSON.stringify(users));
  }

  /* Root always exists — login modal always shows the login form */
  function hasUsers() { return true; }

  /* ── Seed — root handles bootstrap, no localStorage seeding ──── */
  async function seed() { return true; }

  /* ── Authenticate — root first, then localStorage users ─────── */
  async function authenticate(username, password) {
    if (!username || !password) return null;
    const uname = username.trim().toLowerCase();

    if (uname === ROOT_USERNAME) {
      const inputHash = await sha256(password);
      const rootHash  = await sha256(ROOT_PASSWORD);
      return inputHash === rootHash
        ? { username: ROOT_USERNAME, role: ROOT_ROLE, created: 0, isRoot: true }
        : null;
    }

    const users = getUsers();
    const user  = users.find(u => u.username.toLowerCase() === uname);
    if (!user) return null;
    const hash = await sha256(password);
    return hash === user.passwordHash ? user : null;
  }

  /* ── Add user — returns { ok, error? } ──────────────────────── */
  async function addUser(username, password, role) {
    username = (username || '').trim();
    if (!username || !password || !role) return { ok: false, error: 'All fields required.' };
    if (username.toLowerCase() === ROOT_USERNAME)
      return { ok: false, error: 'Username not available.' };
    const users = getUsers();
    if (users.find(u => u.username.toLowerCase() === username.toLowerCase()))
      return { ok: false, error: 'Username already exists.' };
    const hash = await sha256(password);
    users.push({ username, passwordHash: hash, role, created: Date.now() });
    saveUsers(users);
    return { ok: true };
  }

  /* ── Delete user (root protected) ───────────────────────────── */
  function deleteUser(username) {
    if ((username || '').toLowerCase() === ROOT_USERNAME) return;
    saveUsers(getUsers().filter(u => u.username.toLowerCase() !== username.toLowerCase()));
  }

  /* ── Change password (root protected) ───────────────────────── */
  async function changePassword(username, newPassword) {
    if ((username || '').toLowerCase() === ROOT_USERNAME)
      return { ok: false, error: 'Root password cannot be changed via the UI.' };
    if (!newPassword) return { ok: false, error: 'Password required.' };
    const users = getUsers();
    const user  = users.find(u => u.username.toLowerCase() === username.toLowerCase());
    if (!user) return { ok: false, error: 'User not found.' };
    user.passwordHash = await sha256(newPassword);
    saveUsers(users);
    return { ok: true };
  }

  /* ── Update role (root protected) ───────────────────────────── */
  function updateRole(username, role) {
    if ((username || '').toLowerCase() === ROOT_USERNAME)
      return { ok: false, error: 'Root role cannot be changed.' };
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
    if (username.toLowerCase() === ROOT_USERNAME)
      return { ok: false, error: 'Username not available.' };
    if (getUsers().find(u => u.username.toLowerCase() === username.toLowerCase()))
      return { ok: false, error: 'Username already exists.' };
    const reqs = getRequests();
    if (reqs.find(r => r.username.toLowerCase() === username.toLowerCase()))
      return { ok: false, error: 'A request for this username is already pending.' };
    const hash = await sha256(password);
    reqs.push({ username, passwordHash: hash, note: (note || '').trim(), requested: Date.now() });
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
    users.push({ username: req.username, passwordHash: req.passwordHash, role: role || 'viewer', created: Date.now() });
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
    seed, hasUsers, getUsers, authenticate,
    addUser, deleteUser, changePassword, updateRole, sha256,
    requestAccess, getRequests, approveRequest, rejectRequest,
    ROOT_USERNAME,
  };

})();
