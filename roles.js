/**
 * roles.js — Client-side RBAC for jessicarojas1.github.io
 * Role hierarchy: viewer (0) < reader (1) < editor (2) < admin (3)
 * State lives in sessionStorage (clears on tab close).
 * Login is now username + password via users.js (Users module).
 */
const RBAC = (() => {

  /* ── Role registry ────────────────────────────────────────── */
  const REGISTRY = {
    viewer: {
      level      : 0,
      label      : 'Viewer',
      badgeClass : 'bg-secondary',
      description: 'Read-only access to public pages',
    },
    reader: {
      level      : 1,
      label      : 'Reader',
      badgeClass : 'bg-info text-dark',
      description: 'Enhanced read access including compliance details',
    },
    editor: {
      level      : 2,
      label      : 'Editor',
      badgeClass : 'bg-warning text-dark',
      description: 'Can edit compliance tracker, projects, and blog posts',
    },
    admin: {
      level      : 3,
      label      : 'Admin',
      badgeClass : 'bg-danger',
      description: 'Full access — manage all content and data',
    },
  };

  const KEY_ROLE = 'rbac_role';
  const KEY_USER = 'rbac_username';

  /* ── State accessors ─────────────────────────────────────── */
  function getRole()  { return REGISTRY[sessionStorage.getItem(KEY_ROLE)] ? sessionStorage.getItem(KEY_ROLE) : 'viewer'; }
  function getLevel() { return REGISTRY[getRole()].level; }
  function getUser()  { return sessionStorage.getItem(KEY_USER) || 'Guest'; }
  function getDef()   { return REGISTRY[getRole()]; }

  function isAtLeast(roleName) {
    const req = REGISTRY[roleName];
    return req ? getLevel() >= req.level : false;
  }

  /* ── Login via Users module ──────────────────────────────── */
  async function login(username, password) {
    if (typeof Users === 'undefined') {
      return { ok: false, error: 'User module not loaded. Refresh and try again.' };
    }
    const user = await Users.authenticate(username, password);
    if (!user) return { ok: false, error: 'Invalid username or password.' };

    const def = REGISTRY[user.role];
    if (!def) return { ok: false, error: 'Invalid role assigned to this account.' };

    sessionStorage.setItem(KEY_ROLE, user.role);
    sessionStorage.setItem(KEY_USER, user.username);
    applyDOM();
    return { ok: true };
  }

  /* ── Logout ──────────────────────────────────────────────── */
  function logout() {
    sessionStorage.removeItem(KEY_ROLE);
    sessionStorage.removeItem(KEY_USER);
    applyDOM();
  }

  /* ── DOM enforcement ─────────────────────────────────────── */
  /**
   * data-role-min="editor"        → element hidden entirely below that role
   * data-role-min-enable="editor" → element visible but disabled below that role
   * Called after login/logout AND after any dynamic content is added.
   */
  function applyDOM() {
    const level = getLevel();

    // Show/hide entire elements
    document.querySelectorAll('[data-role-min]').forEach(el => {
      const req = REGISTRY[el.dataset.roleMin];
      if (!req) return;
      const show = level >= req.level;
      el.style.display = show ? '' : 'none';
      el.setAttribute('aria-hidden', show ? 'false' : 'true');
    });

    // Enable/disable interactive controls
    document.querySelectorAll('[data-role-min-enable]').forEach(el => {
      const req = REGISTRY[el.dataset.roleMinEnable];
      if (!req) return;
      const enabled = level >= req.level;
      el.disabled = !enabled;
      el.classList.toggle('rbac-disabled', !enabled);
      el.title = enabled ? '' : `Requires ${req.label} role or higher`;
    });

    updateNav();
  }

  /* ── Navbar badge ─────────────────────────────────────────── */
  function updateNav() {
    const def      = getDef();
    const loggedIn = !!sessionStorage.getItem(KEY_ROLE);

    const loginBtn  = document.getElementById('rbac-login-btn');
    const userArea  = document.getElementById('rbac-user-area');
    const badge     = document.getElementById('rbac-role-badge');
    const uname     = document.getElementById('rbac-username');

    if (!loginBtn) return;

    if (loggedIn) {
      loginBtn.classList.add('d-none');
      userArea?.classList.remove('d-none');
      if (badge) { badge.className = `badge ${def.badgeClass} me-1`; badge.textContent = def.label; }
      if (uname) uname.textContent = getUser();
    } else {
      loginBtn.classList.remove('d-none');
      userArea?.classList.add('d-none');
    }

    // Tracker nav link visibility
    const trackerLink = document.getElementById('nav-tracker-link');
    if (trackerLink) trackerLink.style.display = isAtLeast('reader') ? '' : 'none';
  }

  /* ── Wire up login form ───────────────────────────────────── */
  function initLoginForm() {
    const form = document.getElementById('rbac-login-form');
    if (!form) return;

    const errEl = document.getElementById('rbac-login-error');

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const username  = document.getElementById('rbac-username-input').value.trim();
      const password  = document.getElementById('rbac-password-input').value;
      const submitBtn = form.querySelector('[type="submit"]');

      if (!username || !password) {
        if (errEl) { errEl.textContent = 'Username and password are required.'; errEl.classList.remove('d-none'); }
        return;
      }

      submitBtn.disabled    = true;
      submitBtn.textContent = 'Verifying…';

      const result = await login(username, password);

      submitBtn.disabled    = false;
      submitBtn.textContent = 'Login';

      if (result.ok) {
        const modalEl = document.getElementById('rbacLoginModal');
        bootstrap.Modal.getInstance(modalEl)?.hide();
        form.reset();
        if (errEl) { errEl.textContent = ''; errEl.classList.add('d-none'); }
      } else {
        if (errEl) { errEl.textContent = result.error; errEl.classList.remove('d-none'); }
      }
    });

    document.getElementById('rbac-logout-btn')?.addEventListener('click', logout);
  }

  /* ── Init ────────────────────────────────────────────────── */
  async function init() {
    if (typeof Users !== 'undefined') await Users.seed();
    applyDOM();
    initLoginForm();
  }

  /* ── Public API ──────────────────────────────────────────── */
  return { init, getRole, getLevel, getUser, isAtLeast, login, logout, applyDOM, REGISTRY };

})();

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => RBAC.init());
} else {
  RBAC.init();
}
