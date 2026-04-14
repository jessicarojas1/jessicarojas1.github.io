/**
 * roles.js — Client-side RBAC for jessicarojas1.github.io
 * Role hierarchy: viewer (0) < reader (1) < editor (2) < admin (3)
 * State lives in sessionStorage (clears on tab close).
 * Login is username + password via users.js (Users module).
 * Root user (username: root / password: RootAdmin@2026!) always exists.
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
      if (uname) {
        uname.textContent = getUser();
        // Make username clickable to open change-password
        if (!uname.dataset.pwWired) {
          uname.dataset.pwWired = '1';
          uname.style.cursor = 'pointer';
          uname.title = 'Change password';
          uname.addEventListener('click', () => {
            const modalEl = document.getElementById('rbacLoginModal');
            if (!modalEl) return;
            const body  = modalEl.querySelector('.modal-body');
            const title = modalEl.querySelector('.modal-title');
            if (body)  body.innerHTML = CHANGE_PASSWORD_HTML;
            if (title) title.textContent = '🔑 Change Password';
            wireChangePasswordForm();
            if (typeof bootstrap !== 'undefined') {
              bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
          });
        }
      }
    } else {
      loginBtn.classList.remove('d-none');
      userArea?.classList.add('d-none');
      loginBtn.textContent = 'Login';
      loginBtn.title = '';
    }

    const trackerLink = document.getElementById('nav-tracker-link');
    if (trackerLink) trackerLink.style.display = isAtLeast('reader') ? '' : 'none';

    const daLink = document.getElementById('nav-dataanalysis-link');
    if (daLink) daLink.style.display = isAtLeast('reader') ? '' : 'none';
  }

  /* ================================================================
     MODAL CONTENT — swapped dynamically
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
    </form>
    <div class="d-flex justify-content-between mt-3">
      <button type="button" class="btn btn-link btn-sm p-0 text-secondary"
              id="rbac-forgot-password-link">
        Forgot password?
      </button>
      <button type="button" class="btn btn-link btn-sm p-0 text-secondary"
              id="rbac-request-access-link">
        Request access
      </button>
    </div>`;

  const REQUEST_FORM_HTML = `
    <div class="alert alert-info py-2 small mb-3">
      Submit a request to create an account. An admin will review and approve it.
    </div>
    <form id="rbac-request-form" novalidate>
      <div class="mb-3">
        <label for="req-username" class="form-label">Desired Username</label>
        <input type="text" class="form-control" id="req-username"
               autocomplete="username" maxlength="40" required />
      </div>
      <div class="mb-3">
        <label for="req-password" class="form-label">Password</label>
        <input type="password" class="form-control" id="req-password"
               autocomplete="new-password" required />
        <div class="form-text">Minimum 8 characters.</div>
      </div>
      <div class="mb-3">
        <label for="req-password2" class="form-label">Confirm Password</label>
        <input type="password" class="form-control" id="req-password2"
               autocomplete="new-password" required />
      </div>
      <div class="mb-3">
        <label for="req-note" class="form-label">Note <span class="text-secondary fw-normal">(optional)</span></label>
        <input type="text" class="form-control" id="req-note"
               placeholder="Why do you need access?" maxlength="200" />
      </div>
      <div class="alert alert-danger d-none py-2 small" id="req-error"
           role="alert" aria-live="polite"></div>
      <button type="submit" class="btn btn-primary w-100">Submit Request</button>
    </form>
    <div class="text-center mt-2">
      <button type="button" class="btn btn-link btn-sm p-0 text-secondary"
              id="rbac-back-to-login">Back to login</button>
    </div>`;

  const REQUEST_SENT_HTML = `
    <div class="text-center py-3">
      <div class="fs-1 mb-2">✅</div>
      <h3 class="h5 mb-1">Request submitted!</h3>
      <p class="text-secondary small mb-3">An admin will review your request and activate your account.</p>
      <button type="button" class="btn btn-outline-primary btn-sm"
              id="rbac-back-to-login-sent">Back to login</button>
    </div>`;

  const FORGOT_PASSWORD_HTML = `
    <div class="alert alert-info py-2 small mb-3">
      Password resets are managed by your site admin.
    </div>
    <p class="small text-secondary mb-3">
      Ask your admin to reset your password from the
      <strong>User Admin</strong> panel. They can set a new password for your account.
    </p>
    <p class="small text-secondary mb-0">
      Root account? The root password is set in <code>users.js</code> and cannot be changed from the UI.
    </p>
    <div class="text-center mt-3">
      <button type="button" class="btn btn-outline-secondary btn-sm"
              id="rbac-back-to-login-forgot">Back to login</button>
    </div>`;

  const CHANGE_PASSWORD_HTML = `
    <form id="rbac-change-pw-form" novalidate>
      <div class="mb-3">
        <label for="rbac-change-current" class="form-label">Current Password</label>
        <input type="password" class="form-control" id="rbac-change-current"
               autocomplete="current-password" required />
      </div>
      <div class="mb-3">
        <label for="rbac-change-new" class="form-label">New Password</label>
        <input type="password" class="form-control" id="rbac-change-new"
               autocomplete="new-password" placeholder="Minimum 8 characters" required />
      </div>
      <div class="mb-3">
        <label for="rbac-change-confirm" class="form-label">Confirm New Password</label>
        <input type="password" class="form-control" id="rbac-change-confirm"
               autocomplete="new-password" required />
      </div>
      <div class="alert alert-danger d-none py-2 small" id="rbac-change-error"
           role="alert" aria-live="polite"></div>
      <div class="alert alert-success d-none py-2 small" id="rbac-change-success"
           role="status" aria-live="polite"></div>
      <button type="submit" class="btn btn-primary w-100">Update Password</button>
    </form>
    <div class="text-center mt-2">
      <button type="button" class="btn btn-link btn-sm p-0 text-secondary"
              id="rbac-cancel-change-pw">Cancel</button>
    </div>`;

  /* Inject login form into the modal body */
  function refreshModalContent() {
    const modalEl = document.getElementById('rbacLoginModal');
    if (!modalEl) return;

    const body  = modalEl.querySelector('.modal-body');
    const title = modalEl.querySelector('.modal-title');
    if (!body) return;

    if (title) title.textContent = '🔒 Login';
    body.innerHTML = LOGIN_FORM_HTML;
    wireLoginForm();
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

      try {
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
      } catch (err) {
        submitBtn.disabled    = false;
        submitBtn.textContent = 'Login';
        if (errEl) {
          errEl.textContent = err.message || 'Login failed. Ensure you are on an HTTPS connection.';
          errEl.classList.remove('d-none');
        }
      }
    });

    // "Request Access" link — swap to request form
    document.getElementById('rbac-request-access-link')?.addEventListener('click', () => {
      const modalEl = document.getElementById('rbacLoginModal');
      const body    = modalEl?.querySelector('.modal-body');
      const title   = modalEl?.querySelector('.modal-title');
      if (!body) return;
      if (title) title.textContent = '📋 Request Access';
      body.innerHTML = REQUEST_FORM_HTML;
      wireRequestForm();
    });

    // "Forgot Password?" link
    document.getElementById('rbac-forgot-password-link')?.addEventListener('click', () => {
      const modalEl = document.getElementById('rbacLoginModal');
      const body    = modalEl?.querySelector('.modal-body');
      const title   = modalEl?.querySelector('.modal-title');
      if (!body) return;
      if (title) title.textContent = '🔑 Reset Password';
      body.innerHTML = FORGOT_PASSWORD_HTML;
      document.getElementById('rbac-back-to-login-forgot')?.addEventListener('click', refreshModalContent);
    });
  }

  /* ── Wire change-password form (for logged-in users) ────────── */
  function wireChangePasswordForm() {
    const form    = document.getElementById('rbac-change-pw-form');
    if (!form) return;
    const errEl   = document.getElementById('rbac-change-error');
    const succEl  = document.getElementById('rbac-change-success');

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const currentPw = document.getElementById('rbac-change-current').value;
      const newPw     = document.getElementById('rbac-change-new').value;
      const confirmPw = document.getElementById('rbac-change-confirm').value;
      const btn       = form.querySelector('[type="submit"]');

      const showErr  = msg => { if (errEl)  { errEl.textContent  = msg; errEl.classList.remove('d-none');  } };
      const showSucc = msg => { if (succEl) { succEl.textContent = msg; succEl.classList.remove('d-none'); } };
      const hideAll  = ()  => { errEl?.classList.add('d-none'); succEl?.classList.add('d-none'); };

      hideAll();
      if (newPw.length < 8) return showErr('New password must be at least 8 characters.');
      if (newPw !== confirmPw) return showErr('Passwords do not match.');

      btn.disabled = true; btn.textContent = 'Updating…';

      try {
        const verified = await Users.authenticate(getUser(), currentPw);
        if (!verified) {
          btn.disabled = false; btn.textContent = 'Update Password';
          return showErr('Current password is incorrect.');
        }
        const result = await Users.changePassword(getUser(), newPw);
        btn.disabled = false; btn.textContent = 'Update Password';
        if (result.ok) {
          form.reset();
          showSucc('Password updated successfully.');
          setTimeout(() => hideModal(), 1800);
        } else {
          showErr(result.error || 'Failed to update password.');
        }
      } catch (err) {
        btn.disabled = false; btn.textContent = 'Update Password';
        showErr(err.message || 'Update failed.');
      }
    });

    document.getElementById('rbac-cancel-change-pw')?.addEventListener('click', hideModal);
  }

  /* ── Wire request form ───────────────────────────────────── */
  function wireRequestForm() {
    const form  = document.getElementById('rbac-request-form');
    if (!form) return;
    const errEl = document.getElementById('req-error');

    const showErr = msg => {
      if (errEl) { errEl.textContent = msg; errEl.classList.remove('d-none'); }
    };

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const username = document.getElementById('req-username').value.trim();
      const pw       = document.getElementById('req-password').value;
      const pw2      = document.getElementById('req-password2').value;
      const note     = document.getElementById('req-note').value;
      const btn      = form.querySelector('[type="submit"]');

      if (!username)     return showErr('Username is required.');
      if (pw.length < 8) return showErr('Password must be at least 8 characters.');
      if (pw !== pw2)    return showErr('Passwords do not match.');

      if (typeof Users === 'undefined') return showErr('User module not loaded. Refresh and try again.');

      btn.disabled    = true;
      btn.textContent = 'Submitting…';

      try {
        const result = await Users.requestAccess(username, pw, note);
        btn.disabled    = false;
        btn.textContent = 'Submit Request';
        if (result.ok) {
          const body  = document.getElementById('rbacLoginModal')?.querySelector('.modal-body');
          const title = document.getElementById('rbacLoginModal')?.querySelector('.modal-title');
          if (body)  body.innerHTML = REQUEST_SENT_HTML;
          if (title) title.textContent = '📋 Request Access';
          document.getElementById('rbac-back-to-login-sent')?.addEventListener('click', refreshModalContent);
        } else {
          showErr(result.error);
        }
      } catch (err) {
        btn.disabled    = false;
        btn.textContent = 'Submit Request';
        showErr(err.message || 'Request failed. Ensure you are on an HTTPS connection.');
      }
    });

    document.getElementById('rbac-back-to-login')?.addEventListener('click', refreshModalContent);
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
      // Vanilla fallback: intercept login button click
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
