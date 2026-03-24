/**
 * roles.js — Client-side RBAC for jessicarojas1.github.io
 * Role hierarchy: viewer (0) < reader (1) < editor (2) < admin (3)
 * State lives in sessionStorage (clears on tab close).
 * Passwords verified via SHA-256 (Web Crypto API).
 */
const RBAC = (() => {

  /* ── Role registry ────────────────────────────────────────── */
  const REGISTRY = {
    viewer: {
      level: 0,
      label: 'Viewer',
      badgeClass: 'bg-secondary',
      passwordHash: null,            // no password — default role
      description: 'Read-only access to public pages',
    },
    reader: {
      level: 1,
      label: 'Reader',
      badgeClass: 'bg-info text-dark',
      passwordHash: '194356b2d6b486907be3fae505f2e5c6eb2ba9170a556477a4c828555817a636',
      description: 'Enhanced read access including compliance details',
    },
    editor: {
      level: 2,
      label: 'Editor',
      badgeClass: 'bg-warning text-dark',
      passwordHash: 'eb4956a674e3111f274bec2f1e234d76015986c3c374790275b1e3a389570ab2',
      description: 'Can edit compliance tracker, projects, and blog posts',
    },
    admin: {
      level: 3,
      label: 'Admin',
      badgeClass: 'bg-danger',
      passwordHash: '04445e6487736590d1ef50186b414e737e0164683cbbec64e00e73c000fd3bef',
      description: 'Full access — manage all content and data',
    },
  };

  const KEY_ROLE = 'rbac_role';
  const KEY_USER = 'rbac_username';

  /* ── SHA-256 via Web Crypto ───────────────────────────────── */
  async function sha256(str) {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
    return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
  }

  /* ── State accessors ─────────────────────────────────────── */
  function getRole()  { return REGISTRY[sessionStorage.getItem(KEY_ROLE)] ? sessionStorage.getItem(KEY_ROLE) : 'viewer'; }
  function getLevel() { return REGISTRY[getRole()].level; }
  function getUser()  { return sessionStorage.getItem(KEY_USER) || 'Guest'; }
  function getDef()   { return REGISTRY[getRole()]; }

  function isAtLeast(roleName) {
    const req = REGISTRY[roleName];
    return req ? getLevel() >= req.level : false;
  }

  /* ── Login ───────────────────────────────────────────────── */
  async function login(roleName, password, username) {
    const def = REGISTRY[roleName];
    if (!def) return { ok: false, error: 'Unknown role.' };

    if (def.passwordHash !== null) {
      const hash = await sha256(password);
      if (hash !== def.passwordHash) return { ok: false, error: 'Incorrect password.' };
    }

    sessionStorage.setItem(KEY_ROLE, roleName);
    sessionStorage.setItem(KEY_USER, (username || def.label).trim() || def.label);
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
   * data-role-min="editor"   → element hidden entirely below that role
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
      if (!enabled) {
        el.title = `Requires ${req.label} role or higher`;
      } else {
        el.title = '';
      }
    });

    updateNav();
  }

  /* ── Navbar badge ─────────────────────────────────────────── */
  function updateNav() {
    const def      = getDef();
    const role     = getRole();
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

    const roleSelect  = document.getElementById('rbac-role-select');
    const pwGroup     = document.getElementById('rbac-pw-group');
    const errEl       = document.getElementById('rbac-login-error');

    // Show/hide password field based on role selection
    roleSelect?.addEventListener('change', () => {
      const needsPw = REGISTRY[roleSelect.value]?.passwordHash !== null;
      if (pwGroup) pwGroup.style.display = needsPw ? '' : 'none';
      if (errEl) { errEl.textContent = ''; errEl.classList.add('d-none'); }
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const roleName = roleSelect.value;
      const password = document.getElementById('rbac-password-input').value;
      const username = document.getElementById('rbac-username-input').value;
      const submitBtn = form.querySelector('[type="submit"]');

      submitBtn.disabled = true;
      submitBtn.textContent = 'Verifying…';

      const result = await login(roleName, password, username);

      submitBtn.disabled = false;
      submitBtn.textContent = 'Login';

      if (result.ok) {
        const modalEl = document.getElementById('rbacLoginModal');
        bootstrap.Modal.getInstance(modalEl)?.hide();
        form.reset();
        if (pwGroup) pwGroup.style.display = 'none';
        if (errEl) { errEl.textContent = ''; errEl.classList.add('d-none'); }
      } else {
        if (errEl) {
          errEl.textContent = result.error;
          errEl.classList.remove('d-none');
        }
      }
    });

    document.getElementById('rbac-logout-btn')?.addEventListener('click', logout);
  }

  /* ── Init ────────────────────────────────────────────────── */
  function init() {
    applyDOM();
    initLoginForm();
  }

  /* ── Public API ──────────────────────────────────────────── */
  return { init, getRole, getLevel, getUser, isAtLeast, login, logout, applyDOM, REGISTRY };

})();

document.addEventListener('DOMContentLoaded', () => RBAC.init());
