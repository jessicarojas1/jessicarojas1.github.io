/**
 * roles.js — Client-side RBAC for jessicarojas1.github.io
 * Role hierarchy: viewer (0) < reader (1) < editor (2) < admin (3)
 * State lives in sessionStorage (clears on tab close).
 * Login is username + password via users.js (Users module).
 * First run: if no users exist the login modal shows a Create Admin form.
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

  /* ── Hide the modal (Bootstrap or fallback) ──────────────── */
  function hideModal() {
    const modalEl = document.getElementById('rbacLoginModal');
    if (!modalEl) return;
    if (typeof bootstrap !== 'undefined') {
      const inst = bootstrap.Modal.getInstance(modalEl);
      if (inst) inst.hide();
    } else {
      modalEl.classList.remove('show');
      modalEl.style.display = '';
      document.body.style.overflow = '';
    }
  }

  /* ── DOM enforcement ─────────────────────────────────────── */
  function applyDOM() {
    const level = getLevel();

    document.querySelectorAll('[data-role-min]').forEach(el => {
      const req = REGISTRY[el.dataset.roleMin];
      if (!req) return;
      const show = level >= req.level;
      el.style.display = show ? '' : 'none';
      el.setAttribute('aria-hidden', show ? 'false' : 'true');
    });

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

    const loginBtn = document.getElementById('rbac-login-btn');
    const userArea = document.getElementById('rbac-user-area');
    const badge    = document.getElementById('rbac-role-badge');
    const uname    = document.getElementById('rbac-username');

    if (!loginBtn) return;

    if (loggedIn) {
      loginBtn.classList.add('d-none');
      userArea?.classList.remove('d-none');
      if (badge) { badge.className = `badge ${def.badgeClass} me-1`; badge.textContent = def.label; }
      if (uname) uname.textContent = getUser();
    } else {
      loginBtn.classList.remove('d-none');
      userArea?.classList.add('d-none');
      // Reflect first-run state on the button label
      if (typeof Users !== 'undefined' && !Users.hasUsers()) {
        loginBtn.textContent = 'Setup Account';
        loginBtn.title = 'No accounts exist — click to create the first admin account';
      } else {
        loginBtn.textContent = 'Login';
        loginBtn.title = '';
      }
    }

    const trackerLink = document.getElementById('nav-tracker-link');
    if (trackerLink) trackerLink.style.display = isAtLeast('reader') ? '' : 'none';

    const daLink = document.getElementById('nav-dataanalysis-link');
    if (daLink) daLink.style.display = isAtLeast('reader') ? '' : 'none';
  }

  /* ================================================================
     MODAL CONTENT — swapped dynamically based on user-store state
     ================================================================ */

  const LOGIN_FORM_HTML = `
    <form id="rbac-login-form" novalidate>
      <div class="mb-3">
        <label for="rbac-username-input" class="form-label">Username</label>
        <input type="text" class="form-control" id="rbac-username-input"
               autocomplete="username" placeholder="Enter your username"
               maxlength="40" required />
      </div>
      <div class="mb-3">
        <label for="rbac-password-input" class="form-label">Password</label>
        <input type="password" class="form-control" id="rbac-password-input"
               autocomplete="current-password" required />
      </div>
      <div class="alert alert-danger d-none py-2 small" id="rbac-login-error"
           role="alert" aria-live="polite"></div>
      <button type="submit" class="btn btn-primary w-100">Login</button>
    </form>`;

  const SETUP_FORM_HTML = `
    <div class="alert alert-info py-2 small mb-3">
      <strong>First-time setup.</strong> No accounts exist yet.
      Create the first admin account to get started.
    </div>
    <form id="rbac-setup-form" novalidate>
      <div class="mb-3">
        <label for="setup-username" class="form-label">Username</label>
        <input type="text" class="form-control" id="setup-username"
               autocomplete="username" maxlength="40" required />
      </div>
      <div class="mb-3">
        <label for="setup-password" class="form-label">Password</label>
        <input type="password" class="form-control" id="setup-password"
               autocomplete="new-password" required />
        <div class="form-text">Minimum 8 characters.</div>
      </div>
      <div class="mb-3">
        <label for="setup-password2" class="form-label">Confirm Password</label>
        <input type="password" class="form-control" id="setup-password2"
               autocomplete="new-password" required />
      </div>
      <div class="alert alert-danger d-none py-2 small" id="setup-error"
           role="alert" aria-live="polite"></div>
      <button type="submit" class="btn btn-primary w-100">Create Admin Account</button>
    </form>`;

  /* Inject the right form into the modal body and wire it up */
  function refreshModalContent() {
    const modalEl = document.getElementById('rbacLoginModal');
    if (!modalEl) return;

    const body   = modalEl.querySelector('.modal-body');
    const title  = modalEl.querySelector('.modal-title');
    if (!body) return;

    const noUsers = typeof Users !== 'undefined' && !Users.hasUsers();

    if (noUsers) {
      if (title) title.textContent = '🔑 Create Admin Account';
      body.innerHTML = SETUP_FORM_HTML;
      wireSetupForm();
    } else {
      if (title) title.textContent = '🔒 Login';
      body.innerHTML = LOGIN_FORM_HTML;
      wireLoginForm();
    }
  }

  /* ── Wire login form ─────────────────────────────────────── */
  function wireLoginForm() {
    const form  = document.getElementById('rbac-login-form');
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
        hideModal();
        form.reset();
        if (errEl) { errEl.textContent = ''; errEl.classList.add('d-none'); }
      } else {
        if (errEl) { errEl.textContent = result.error; errEl.classList.remove('d-none'); }
      }
    });
  }

  /* ── Wire setup (first-run) form ─────────────────────────── */
  function wireSetupForm() {
    const form  = document.getElementById('rbac-setup-form');
    if (!form) return;
    const errEl = document.getElementById('setup-error');

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const username = document.getElementById('setup-username').value.trim();
      const pw       = document.getElementById('setup-password').value;
      const pw2      = document.getElementById('setup-password2').value;

      const showErr = msg => {
        if (errEl) { errEl.textContent = msg; errEl.classList.remove('d-none'); }
      };

      if (!username)       return showErr('Username is required.');
      if (pw.length < 8)   return showErr('Password must be at least 8 characters.');
      if (pw !== pw2)      return showErr('Passwords do not match.');

      const submitBtn = form.querySelector('[type="submit"]');
      submitBtn.disabled    = true;
      submitBtn.textContent = 'Creating…';

      if (typeof Users === 'undefined') {
        submitBtn.disabled    = false;
        submitBtn.textContent = 'Create Admin Account';
        return showErr('User module not loaded. Refresh and try again.');
      }

      const result = await Users.addUser(username, pw, 'admin');
      if (!result.ok) {
        submitBtn.disabled    = false;
        submitBtn.textContent = 'Create Admin Account';
        return showErr(result.error);
      }

      // Auto-login with the new account
      const loginResult = await login(username, pw);
      submitBtn.disabled    = false;
      submitBtn.textContent = 'Create Admin Account';

      if (loginResult.ok) {
        hideModal();
        updateNav();
      } else {
        showErr('Account created but auto-login failed. Please log in manually.');
        refreshModalContent(); // switch back to login form
      }
    });
  }

  /* ── initLoginForm: called once on init ─────────────────── */
  function initLoginForm() {
    const modalEl = document.getElementById('rbacLoginModal');
    if (!modalEl) return;

    // Wire logout button (always present in nav, outside modal)
    document.getElementById('rbac-logout-btn')?.addEventListener('click', logout);

    // Bootstrap: refresh modal content each time it opens
    if (typeof bootstrap !== 'undefined') {
      modalEl.addEventListener('show.bs.modal', refreshModalContent);
    } else {
      // Vanilla fallback: intercept login button click to check state first
      const loginBtn = document.getElementById('rbac-login-btn');
      if (loginBtn) {
        loginBtn.removeAttribute('data-bs-toggle');
        loginBtn.removeAttribute('data-bs-target');
        loginBtn.addEventListener('click', () => {
          refreshModalContent();
          modalEl.style.display = 'flex';
          modalEl.classList.add('show');
          modalEl.style.backgroundColor = 'rgba(0,0,0,0.5)';
          document.body.style.overflow = 'hidden';
        });
        const closeModal = () => {
          modalEl.classList.remove('show');
          modalEl.style.display = '';
          document.body.style.overflow = '';
        };
        modalEl.querySelector('.btn-close')?.addEventListener('click', closeModal);
        modalEl.addEventListener('click', e => { if (e.target === modalEl) closeModal(); });
      }
    }

    // Render the correct form for the initial state (in case modal is
    // already open or has stale content from a previous navigation)
    refreshModalContent();
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
