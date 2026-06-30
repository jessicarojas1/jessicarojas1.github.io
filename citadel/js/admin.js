/* CITADEL — admin console logic (externalized for a strict CSP, no inline scripts). */
  (function () {
    'use strict';
    const auth = window.CITADEL && window.CITADEL.auth;
    const $ = (id) => document.getElementById(id);

    /* =====================================================================
     * Dual-mode data-access layer.
     * DA.mode === 'backend'  -> real JWT-based CITADEL backend (api/*)
     * DA.mode === 'local'    -> existing localStorage CITADEL.auth (unchanged)
     * The rest of the UI talks only to DA; constants (PAGES/ROLES) always come
     * from the client auth.js so labels/columns are identical in both modes.
     * ===================================================================== */
    const JWT_KEY = 'citadel.jwt';
    const SESSION_KEY = 'citadel.session';   // refresh token now lives in an httpOnly cookie
    try { localStorage.removeItem('citadel.refresh'); } catch (e) {}
    const DA = {
      mode: 'local',
      // shared constants (identical in both modes)
      PAGES: auth ? auth.PAGES : [],
      ROLES: auth ? auth.ROLES : {},
      DEFAULT_ADMIN: auth ? auth.DEFAULT_ADMIN : { email: '', password: '' },
      // backend caches
      _me: null,
      _users: [],
      _settings: { enforce: false },
      _sso: false,

      token() { try { return localStorage.getItem(JWT_KEY) || ''; } catch (e) { return ''; } },
      _setToken(t) { try { t ? localStorage.setItem(JWT_KEY, t) : localStorage.removeItem(JWT_KEY); } catch (e) {} },
      _hasSession() { try { return !!localStorage.getItem(SESSION_KEY); } catch (e) { return false; } },
      _setSession(on) { try { on ? localStorage.setItem(SESSION_KEY, '1') : localStorage.removeItem(SESSION_KEY); } catch (e) {} },
      _setTokens(out) { if (out && out.token) { this._setToken(out.token); this._setSession(true); } },
      async _refresh() {
        if (!this._hasSession()) return false;
        try {
          // The refresh token rides the httpOnly cookie (sent automatically).
          const res = await fetch('api/auth/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
          if (!res.ok) { if (res.status === 401) { this._setToken(''); this._setSession(false); } return false; }
          const j = await res.json(); if (j && j.token) { this._setToken(j.token); return true; } return false;
        } catch (e) { return false; }
      },

      async _api(path, opts, _retried) {
        opts = opts || {};
        const headers = Object.assign({}, opts.headers || {});
        if (opts.body != null) headers['Content-Type'] = 'application/json';
        const tok = this.token();
        if (tok) headers['Authorization'] = 'Bearer ' + tok;
        const res = await fetch(path, {
          method: opts.method || 'GET',
          headers,
          body: opts.body != null ? JSON.stringify(opts.body) : undefined
        });
        // Transparently refresh an expired access token once.
        if (res.status === 401 && !_retried && this._hasSession()) {
          if (await this._refresh()) return this._api(path, opts, true);
        }
        let data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        if (!res.ok) {
          const msg = (data && data.error) ? data.error : ('Request failed (' + res.status + ').');
          const err = new Error(msg); err.status = res.status; throw err;
        }
        return data;
      },

      async _refreshUsers() {
        if (this.mode !== 'backend') return;
        this._users = await this._api('api/users');
      },

      // Security audit trail (backend only). Returns { stats, events } or null.
      async audit(type, limit) {
        if (this.mode !== 'backend') return null;
        const q = [];
        if (type) q.push('type=' + encodeURIComponent(type));
        if (limit) q.push('limit=' + encodeURIComponent(limit));
        return this._api('api/audit' + (q.length ? '?' + q.join('&') : ''));
      },

      // Active sessions (backend only). Returns { stats, sessions } or null.
      async changePassword(current, next) {
        if (this.mode !== 'backend') throw new Error('Password change requires the backend.');
        return this._api('api/auth/password', { method: 'POST', body: { current, next } });
      },
      async mfaStatus() { return this._api('api/auth/mfa'); },
      async mfaSetup() { return this._api('api/auth/mfa/setup', { method: 'POST' }); },
      async mfaEnable(token) { return this._api('api/auth/mfa/enable', { method: 'POST', body: { token } }); },
      async mfaDisable(password) { return this._api('api/auth/mfa/disable', { method: 'POST', body: { password } }); },
      async sessions() { if (this.mode !== 'backend') return null; return this._api('api/sessions'); },
      async revokeSession(jti) { if (this.mode !== 'backend') return; await this._api('api/sessions/' + encodeURIComponent(jti), { method: 'DELETE' }); },
      async revokeUserSessions(id) { if (this.mode !== 'backend') return; await this._api('api/users/' + encodeURIComponent(id) + '/revoke-sessions', { method: 'POST' }); },

      /* ---- lifecycle ---- */
      async init() {
        // Probe the backend.
        try {
          const res = await fetch('api/health', { headers: { 'Accept': 'application/json' } });
          if (res.ok) {
            const health = await res.json().catch(() => null);
            if (health && health.auth && typeof health.auth === 'object') {
              this.mode = 'backend';
              this._sso = !!health.auth.sso;
            }
          }
        } catch (e) { /* unreachable -> stay local */ }

        if (this.mode === 'backend') {
          this._settings = { enforce: !!(this._settings && this._settings.enforce) };
          if (this.token()) {
            try {
              this._me = await this._api('api/auth/me');
              if (this._me && this._me.role === 'admin') {
                // A must-change admin can't load /api/users yet (403). Keep the
                // session and enter the console so the change-password form is
                // reachable — don't drop the token (that bounced the user back to
                // a fresh admin login from the main page).
                if (!this._me.mustChange) await this._refreshUsers();
              } else {
                // valid token but not an admin -> drop session
                this._me = null; this._setToken('');
              }
            } catch (e) {
              this._me = null; this._setToken('');
            }
          }
          try { this._settings = await this._api('api/auth/settings'); } catch (e) {}
          return;
        }
        // local mode
        await auth.ready;
      },

      /* ---- session ---- */
      current() {
        return this.mode === 'backend' ? this._me : auth.current();
      },

      async login(email, password) {
        if (this.mode === 'backend') {
          const out = await this._api('api/auth/login', { method: 'POST', body: { email, password } });
          if (out && out.mfaRequired) return { mfaRequired: true, mfaToken: out.mfaToken };
          return this._completeLogin(out);
        }
        return auth.loginByCreds(email, password);
      },
      // Finish an MFA login with a TOTP/backup code.
      async mfaVerify(mfaToken, code) {
        const out = await this._api('api/auth/mfa/verify', { method: 'POST', body: { mfaToken, code } });
        return this._completeLogin(out);
      },
      _completeLogin(out) {
        const user = out && out.user;
        if (!user || user.role !== 'admin') {
          const err = new Error('Invalid credentials or not an administrator.'); err.status = 401; throw err;
        }
        this._setTokens(out);
        this._me = user;
        // A must-change admin can't call /api/users yet (it returns 403 until the
        // password is rotated). Enter the console anyway so the change-password
        // form is reachable; the user list loads after the rotation. Without this
        // the login screen just shows "you must change your password" with no way
        // to actually change it — a dead end.
        if (user.mustChange) return Promise.resolve(user);
        return this._refreshUsers().then(() => user);
      },

      logout() {
        if (this.mode === 'backend') {
          // Tell the server to revoke the session + clear the httpOnly refresh cookie.
          try { fetch('api/auth/logout', { method: 'POST', headers: { Authorization: 'Bearer ' + this.token() } }); } catch (e) {}
          this._setToken(''); this._setSession(false); this._me = null; this._users = [];
        } else {
          auth.logout();
        }
      },

      /* ---- users ---- */
      listUsers() {
        return this.mode === 'backend' ? this._users.slice() : auth.listUsers();
      },

      async addUser(data) {
        if (this.mode === 'backend') {
          await this._api('api/users', { method: 'POST', body: data });
          await this._refreshUsers();
          return;
        }
        return auth.addUser(data);
      },

      async removeUser(id) {
        if (this.mode === 'backend') {
          await this._api('api/users/' + encodeURIComponent(id), { method: 'DELETE' });
          await this._refreshUsers();
          return;
        }
        return auth.removeUser(id);
      },

      async updateUser(id, patch) {
        if (this.mode === 'backend') {
          await this._api('api/users/' + encodeURIComponent(id), { method: 'PATCH', body: patch });
          await this._refreshUsers();
          // keep cached "me" in sync if we edited ourselves
          if (this._me && this._me.id === id) {
            const fresh = this._users.find((u) => u.id === id);
            if (fresh) this._me = fresh;
          }
          return;
        }
        return auth.updateUser(id, patch);
      },

      async setPermission(id, page, val) {
        if (this.mode === 'backend') {
          const u = this._users.find((x) => x.id === id);
          const perms = Object.assign({}, (u && u.permissions) || {});
          perms[page] = !!val;
          await this._api('api/users/' + encodeURIComponent(id), { method: 'PATCH', body: { permissions: perms } });
          await this._refreshUsers();
          return;
        }
        return auth.setPermission(id, page, val);
      },

      async setActive(id, val) {
        if (this.mode === 'backend') {
          await this._api('api/users/' + encodeURIComponent(id), { method: 'PATCH', body: { active: !!val } });
          await this._refreshUsers();
          return;
        }
        return auth.setActive(id, val);
      },

      async setPassword(id, password) {
        if (this.mode === 'backend') {
          await this._api('api/users/' + encodeURIComponent(id) + '/password', { method: 'POST', body: { password } });
          return;
        }
        return auth.setPassword(id, password);
      },

      /* ---- settings ---- */
      settings() {
        return this.mode === 'backend' ? this._settings : auth.settings();
      },

      async setSetting(k, val) {
        if (this.mode === 'backend') {
          if (k === 'enforce') {
            this._settings = await this._api('api/auth/settings', { method: 'PATCH', body: { enforce: !!val } });
          }
          return;
        }
        return auth.setSetting(k, val);
      }
    };

    /* ---------- theme toggle (same wiring as other pages) ---------- */
    function applyThemeIcon() {
      const t = document.documentElement.getAttribute('data-bs-theme');
      const ic = document.querySelector('#themeToggleBtn .theme-icon');
      if (ic) ic.textContent = t === 'dark' ? '☀️' : '🌙';
    }
    $('themeToggleBtn').addEventListener('click', () => {
      const cur = document.documentElement.getAttribute('data-bs-theme');
      const next = cur === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      try { localStorage.setItem('bsTheme', next); } catch (e) {}
      applyThemeIcon();
    });
    applyThemeIcon();

    /* ---------- helpers ---------- */
    function esc(s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[c]));
    }
    function fmtDate(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      return isNaN(d) ? '—' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }
    function roleLabel(role) {
      return (DA.ROLES[role] && DA.ROLES[role].label) || role;
    }

    /* ---------- view switching ---------- */
    function show(view) {
      $('login-view').classList.toggle('d-none', view !== 'login');
      $('console-view').classList.toggle('d-none', view !== 'console');
    }

    /* ---------- login ---------- */
    let _pendingMfa = null;
    function setupLogin() {
      $('login-hint').textContent = DA.mode === 'backend'
        ? 'Sign in with your CITADEL administrator account.'
        : 'Demo admin — ' + DA.DEFAULT_ADMIN.email + ' / ' + DA.DEFAULT_ADMIN.password;
      const ssoBtn = $('login-sso'); if (ssoBtn) ssoBtn.classList.toggle('d-none', !(DA.mode === 'backend' && DA._sso));
      const form = $('login-form');
      if (form.dataset.wired) return;
      form.dataset.wired = '1';
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const err = $('login-error');
        err.classList.add('d-none');
        const fail = (msg) => { err.textContent = msg || 'Invalid credentials or not an administrator.'; err.classList.remove('d-none'); };
        try {
          let user;
          if (_pendingMfa) {
            user = await DA.mfaVerify(_pendingMfa, ($('login-mfa-code').value || '').trim());
          } else {
            const r = await DA.login($('login-email').value.trim(), $('login-password').value);
            if (r && r.mfaRequired) {
              _pendingMfa = r.mfaToken;
              $('login-creds').classList.add('d-none'); $('login-passwrap').classList.add('d-none');
              $('login-mfawrap').classList.remove('d-none');
              $('login-btn-submit').innerHTML = '<i class="bi bi-shield-lock"></i> Verify';
              $('login-hint').textContent = 'Enter the code from your authenticator app (or a backup code).';
              $('login-mfa-code').focus();
              return;
            }
            user = r;
          }
          if (!user || user.role !== 'admin') { DA.logout(); fail(); return; }
          _pendingMfa = null; $('login-password').value = '';
          render();
        } catch (ex) { fail(ex && ex.message); }
      });
    }

    /* ---------- tab bar ---------- */
    function setupTabs() {
      const bar = $('admin-tabs');
      if (bar.dataset.wired) return;
      bar.dataset.wired = '1';
      bar.addEventListener('click', (e) => {
        const btn = e.target.closest('.tab-btn');
        if (!btn) return;
        bar.querySelectorAll('.tab-btn').forEach((b) => b.classList.toggle('active', b === btn));
        const target = btn.getAttribute('data-tab');
        document.querySelectorAll('#console-view .tab-panel').forEach((p) => {
          p.classList.toggle('d-none', p.id !== target);
        });
      });
    }

    /* ---------- Users tab ---------- */
    function renderRoleOptions(select, selected) {
      select.innerHTML = Object.keys(DA.ROLES).map((r) =>
        '<option value="' + esc(r) + '"' + (r === selected ? ' selected' : '') + '>' + esc(DA.ROLES[r].label) + '</option>'
      ).join('');
    }

    function renderUsers() {
      const me = DA.current();
      const users = DA.listUsers();
      const tbody = $('users-tbody');
      if (!users.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="admin-empty">No users.</td></tr>';
        return;
      }
      tbody.innerHTML = users.map((u) => {
        const isMe = me && u.id === me.id;
        const roleBadge = '<span class="badge text-bg-secondary role-badge">' +
          esc(DA.ROLES[u.role] ? DA.ROLES[u.role].label : u.role) + '</span>';
        const statusBadge = u.active
          ? '<span class="badge text-bg-success">Active</span>'
          : '<span class="badge text-bg-secondary">Disabled</span>';
        const toggleBtn = '<button class="btn btn-sm btn-outline-secondary" data-toggle-active="' + esc(u.id) + '" data-next="' + (u.active ? '0' : '1') + '">' +
          (u.active ? 'Disable' : 'Enable') + '</button>';
        const editBtn = '<button class="btn btn-sm btn-outline-secondary" data-edit-user="' + esc(u.id) + '" title="Edit name, email & role"><i class="bi bi-pencil"></i> Edit</button>';
        const resetBtn = '<button class="btn btn-sm btn-outline-secondary" data-reset-pass="' + esc(u.id) + '"><i class="bi bi-key"></i> Reset password</button>';
        const removeBtn = isMe
          ? '<button class="btn btn-sm btn-outline-danger" disabled title="You cannot remove your own account" aria-label="You cannot remove your own account"><i class="bi bi-trash"></i></button>'
          : '<button class="btn btn-sm btn-outline-danger" data-remove="' + esc(u.id) + '"><i class="bi bi-trash"></i></button>';
        return '<tr data-user-row="' + esc(u.id) + '">' +
          '<td data-cell="name">' + esc(u.name) + (isMe ? ' <span class="badge text-bg-light text-dark">you</span>' : '') + '</td>' +
          '<td class="text-body-secondary" data-cell="email">' + esc(u.email) + '</td>' +
          '<td data-cell="role">' + roleBadge + '</td>' +
          '<td><div class="d-flex align-items-center gap-2">' + statusBadge + toggleBtn + '</div></td>' +
          '<td class="text-body-secondary small">' + fmtDate(u.createdAt) + '</td>' +
          '<td class="text-end"><div class="d-inline-flex gap-1" data-cell="actions">' + editBtn + resetBtn + removeBtn + '</div></td>' +
          '</tr>';
      }).join('');
    }

    function wireUsers() {
      renderRoleOptions($('nu-role'), 'analyst');
      const addForm = $('add-user-form');
      if (!addForm.dataset.wired) {
        addForm.dataset.wired = '1';
        addForm.addEventListener('submit', async (e) => {
          e.preventDefault();
          const err = $('add-user-error');
          err.classList.add('d-none');
          try {
            await DA.addUser({
              name: $('nu-name').value.trim(),
              email: $('nu-email').value.trim(),
              role: $('nu-role').value,
              password: $('nu-pass').value
            });
            addForm.reset();
            renderRoleOptions($('nu-role'), 'analyst');
            render();
          } catch (ex) {
            err.textContent = ex && ex.message ? ex.message : 'Could not add user.';
            err.classList.remove('d-none');
          }
        });
      }

      const tbody = $('users-tbody');
      if (tbody.dataset.wired) return;
      tbody.dataset.wired = '1';
      // Role is edited inside the per-row Edit form now (saved together with
      // name/email), so there is no standalone role-select auto-save handler.
      tbody.addEventListener('click', async (e) => {
        const err = $('add-user-error');
        // ----- inline edit: name + email -----
        const editBtn = e.target.closest('[data-edit-user]');
        if (editBtn) {
          const id = editBtn.getAttribute('data-edit-user');
          const u = DA.listUsers().find((x) => x.id === id); if (!u) return;
          const row = tbody.querySelector('[data-user-row="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]') || editBtn.closest('tr');
          const nameCell = row.querySelector('[data-cell="name"]');
          const emailCell = row.querySelector('[data-cell="email"]');
          const roleCell = row.querySelector('[data-cell="role"]');
          const actions = row.querySelector('[data-cell="actions"]');
          nameCell.innerHTML = '<input class="form-control form-control-sm" data-edit-name maxlength="120" value="' + esc(u.name || '') + '">';
          emailCell.innerHTML = '<input class="form-control form-control-sm" type="email" data-edit-email maxlength="180" value="' + esc(u.email || '') + '">';
          if (roleCell) roleCell.innerHTML = '<select class="form-select form-select-sm minw-150" data-edit-role data-orig-role="' + esc(u.role) + '">' +
            Object.keys(DA.ROLES).map((r) =>
              '<option value="' + esc(r) + '"' + (r === u.role ? ' selected' : '') + '>' + esc(DA.ROLES[r].label) + '</option>'
            ).join('') + '</select>';
          actions.innerHTML = '<button class="btn btn-sm btn-primary" data-save-user="' + esc(id) + '"><i class="bi bi-check-lg"></i> Save</button>' +
            '<button class="btn btn-sm btn-outline-secondary" data-cancel-user="1">Cancel</button>';
          nameCell.querySelector('input').focus();
          return;
        }
        const cancelBtn = e.target.closest('[data-cancel-user]');
        if (cancelBtn) { renderUsers(); return; }
        const saveBtn = e.target.closest('[data-save-user]');
        if (saveBtn) {
          err.classList.add('d-none');
          const id = saveBtn.getAttribute('data-save-user');
          const row = saveBtn.closest('tr');
          const name = row.querySelector('[data-edit-name]').value.trim();
          const email = row.querySelector('[data-edit-email]').value.trim();
          const roleEl = row.querySelector('[data-edit-role]');
          const patch = { name, email };
          // Include role only when it actually changed; a role change resets the
          // user's page permissions to that role's defaults (resetPerms).
          if (roleEl && roleEl.value !== roleEl.getAttribute('data-orig-role')) {
            patch.role = roleEl.value; patch.resetPerms = true;
          }
          try {
            await DA.updateUser(id, patch);
            render();
          } catch (ex) {
            err.textContent = ex && ex.message ? ex.message : 'Could not update user.';
            err.classList.remove('d-none');
          }
          return;
        }
        const toggle = e.target.closest('[data-toggle-active]');
        if (toggle) {
          err.classList.add('d-none');
          try {
            await DA.setActive(toggle.getAttribute('data-toggle-active'), toggle.getAttribute('data-next') === '1');
            render();
          } catch (ex) {
            err.textContent = ex && ex.message ? ex.message : 'Could not update status.';
            err.classList.remove('d-none');
          }
          return;
        }
        const resetPass = e.target.closest('[data-reset-pass]');
        if (resetPass) {
          const id = resetPass.getAttribute('data-reset-pass');
          const me = DA.current();
          const isSelf = me && me.id === id;
          const pw = await CITADEL.ui.prompt(isSelf
            ? 'Enter your new password:'
            : 'Enter a temporary password for this user. They will be required to set their own at next sign-in.', '', { okLabel: 'Set password' });
          if (pw) {
            try {
              await DA.setPassword(id, pw);
              CITADEL.ui.toast(isSelf ? 'Password updated.' : 'Temporary password set. The user must change it at their next sign-in.', 'success');
            }
            catch (ex) { CITADEL.ui.toast(ex && ex.message ? ex.message : 'Could not update password.', 'error'); }
          }
          return;
        }
        const remove = e.target.closest('[data-remove]');
        if (remove) {
          if (!await CITADEL.ui.confirm('Remove this user? This cannot be undone.', { danger: true, okLabel: 'Remove user' })) return;
          try { await DA.removeUser(remove.getAttribute('data-remove')); render(); }
          catch (ex) { CITADEL.ui.toast(ex && ex.message ? ex.message : 'Could not remove user.', 'error'); }
        }
      });
    }

    /* ---------- Permissions tab ---------- */
    function renderPerms() {
      const users = DA.listUsers();
      const pages = DA.PAGES;
      // group order based on first appearance
      const groups = [];
      pages.forEach((p) => { if (!groups.includes(p.group)) groups.push(p.group); });

      // header: two rows — group header row + page label row
      let groupRow = '<tr><th class="user-col" rowspan="2">User</th>';
      groups.forEach((g) => {
        const count = pages.filter((p) => p.group === g).length;
        groupRow += '<th colspan="' + count + '" class="perm-group-head border-start">' + esc(g) + '</th>';
      });
      groupRow += '</tr>';

      let pageRow = '<tr>';
      groups.forEach((g) => {
        const inGroup = pages.filter((p) => p.group === g);
        inGroup.forEach((p, i) => {
          pageRow += '<th class="perm-col-label' + (i === 0 ? ' border-start' : '') + '" title="' + esc(p.label) + '">' + esc(p.label) + '</th>';
        });
      });
      pageRow += '</tr>';
      $('perms-thead').innerHTML = groupRow + pageRow;

      const tbody = $('perms-tbody');
      if (!users.length) {
        tbody.innerHTML = '<tr><td class="admin-empty" colspan="' + (pages.length + 1) + '">No users.</td></tr>';
        return;
      }
      tbody.innerHTML = users.map((u) => {
        const isAdmin = u.role === 'admin';
        let row = '<tr><td class="user-col">' +
          '<div class="d-flex flex-column">' +
            '<span>' + esc(u.name) + '</span>' +
            '<span class="d-flex align-items-center gap-2 mt-1">' +
              '<span class="badge text-bg-secondary role-badge">' + esc(u.role) + '</span>' +
              '<button class="btn btn-sm btn-outline-secondary py-0 px-1" data-reset-perms="' + esc(u.id) + '" data-role="' + esc(u.role) + '" title="Reset to role defaults" aria-label="Reset to role defaults"><i class="bi bi-arrow-counterclockwise"></i></button>' +
            '</span>' +
          '</div></td>';
        groups.forEach((g) => {
          const inGroup = pages.filter((p) => p.group === g);
          inGroup.forEach((p, i) => {
            const checked = isAdmin ? true : !!(u.permissions && u.permissions[p.id]);
            row += '<td class="matrix-cell' + (i === 0 ? ' border-start' : '') + '">' +
              '<input type="checkbox" class="form-check-input" ' +
                (checked ? 'checked ' : '') + (isAdmin ? 'disabled ' : '') +
                'data-perm-user="' + esc(u.id) + '" data-perm-page="' + esc(p.id) + '" ' +
                'aria-label="' + esc(u.name + ' — ' + p.label) + '">' +
              '</td>';
          });
        });
        row += '</tr>';
        return row;
      }).join('');
    }

    function wirePerms() {
      const tbody = $('perms-tbody');
      if (tbody.dataset.wired) return;
      tbody.dataset.wired = '1';
      tbody.addEventListener('change', async (e) => {
        const cb = e.target.closest('[data-perm-user]');
        if (cb) {
          try {
            await DA.setPermission(cb.getAttribute('data-perm-user'), cb.getAttribute('data-perm-page'), cb.checked);
          } catch (ex) {
            CITADEL.ui.toast(ex && ex.message ? ex.message : 'Could not update permission.', 'error');
            renderPerms();
          }
        }
      });
      tbody.addEventListener('click', async (e) => {
        const reset = e.target.closest('[data-reset-perms]');
        if (reset) {
          try {
            await DA.updateUser(reset.getAttribute('data-reset-perms'), { role: reset.getAttribute('data-role'), resetPerms: true });
            render();
          } catch (ex) {
            CITADEL.ui.toast(ex && ex.message ? ex.message : 'Could not reset permissions.', 'error');
          }
        }
      });
    }

    /* ---------- Access Settings tab ---------- */
    function renderSettings() {
      const s = DA.settings();
      const sw = $('enforce-switch');
      sw.checked = !!s.enforce;
      const pill = $('enforce-pill');
      pill.classList.toggle('on', !!s.enforce);
      pill.classList.toggle('off', !s.enforce);
      $('enforce-pill-text').textContent = s.enforce ? 'Enforced' : 'Open';

      const users = DA.listUsers();
      $('summary-user-count').textContent = users.length;
      const counts = {};
      Object.keys(DA.ROLES).forEach((r) => counts[r] = 0);
      users.forEach((u) => { counts[u.role] = (counts[u.role] || 0) + 1; });
      $('summary-roles').innerHTML = Object.keys(DA.ROLES).map((r) =>
        '<div class="d-flex justify-content-between border-top py-1 small">' +
          '<span>' + esc(DA.ROLES[r].label) + '</span>' +
          '<span class="badge text-bg-secondary">' + (counts[r] || 0) + '</span>' +
        '</div>'
      ).join('');
    }

    function wireSettings() {
      const sw = $('enforce-switch');
      if (sw.dataset.wired) return;
      sw.dataset.wired = '1';
      sw.addEventListener('change', async () => {
        try {
          await DA.setSetting('enforce', sw.checked);
        } catch (ex) {
          CITADEL.ui.toast(ex && ex.message ? ex.message : 'Could not update setting.', 'error');
        }
        renderSettings();
      });
    }

    /* ---------- Activity / Audit tab ---------- */
    function fmtTime(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      return isNaN(d) ? '—' : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    function auditBadge(type, ok) {
      const cls = !ok ? 'text-bg-danger'
        : (type === 'login.success' ? 'text-bg-success'
        : (type.indexOf('user.') === 0 || type.indexOf('settings') === 0 ? 'text-bg-primary' : 'text-bg-secondary'));
      return '<span class="badge ' + cls + '">' + esc(type) + '</span>';
    }
    let _lastAudit = [];
    async function renderAudit() {
      if (DA.mode !== 'backend') return;
      const tbody = $('audit-tbody'); const statsEl = $('audit-stats');
      if (!tbody) return;
      let data;
      try { data = await DA.audit($('audit-filter') ? $('audit-filter').value : '', 200); }
      catch (ex) {
        tbody.innerHTML = '<tr><td class="admin-empty" colspan="5">' + esc(ex && ex.message ? ex.message : 'Could not load activity.') + '</td></tr>';
        return;
      }
      if (!data) return;
      const events = data.events || [];
      _lastAudit = events;
      if (statsEl) statsEl.textContent = (data.stats ? (data.stats.total + ' event(s) buffered (capacity ' + data.stats.capacity + ')') : '');
      if (!events.length) {
        tbody.innerHTML = '<tr><td class="admin-empty" colspan="5">No activity recorded yet.</td></tr>';
        return;
      }
      tbody.innerHTML = events.map((e) =>
        '<tr>' +
          '<td class="text-nowrap small">' + esc(fmtTime(e.ts)) + '</td>' +
          '<td>' + auditBadge(e.type, e.ok) + '</td>' +
          '<td class="small">' + esc(e.actor || '—') + '</td>' +
          '<td class="small font-monospace">' + esc(e.ip || '—') + '</td>' +
          '<td class="small">' + esc(e.detail || '') + '</td>' +
        '</tr>'
      ).join('');
    }
    // Trigger a client-side file download (no server round-trip).
    function downloadFile(name, mime, text) {
      const blob = new Blob([text], { type: mime });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = name;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function toCsv(rows, cols) {
      const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
      const head = cols.map(q).join(',');
      const body = rows.map((r) => cols.map((c) => q(r[c])).join(',')).join('\r\n');
      return head + '\r\n' + body;
    }
    function exportAudit(fmt) {
      if (!_lastAudit.length) { CITADEL.ui.toast('Nothing to export yet — refresh the activity list first.', 'warning'); return; }
      const stamp = new Date().toISOString().replace(/[:.]/g, '-');
      if (fmt === 'csv') downloadFile('citadel-audit-' + stamp + '.csv', 'text/csv', toCsv(_lastAudit, ['seq', 'ts', 'type', 'actor', 'ip', 'detail', 'ok']));
      else downloadFile('citadel-audit-' + stamp + '.json', 'application/json', JSON.stringify(_lastAudit, null, 2));
    }
    function wireAudit() {
      const btn = $('audit-refresh'); const sel = $('audit-filter');
      const ej = $('audit-export-json'); const ec = $('audit-export-csv');
      if (btn && !btn.dataset.wired) { btn.dataset.wired = '1'; btn.addEventListener('click', renderAudit); }
      if (sel && !sel.dataset.wired) { sel.dataset.wired = '1'; sel.addEventListener('change', renderAudit); }
      if (ej && !ej.dataset.wired) { ej.dataset.wired = '1'; ej.addEventListener('click', () => exportAudit('json')); }
      if (ec && !ec.dataset.wired) { ec.dataset.wired = '1'; ec.addEventListener('click', () => exportAudit('csv')); }
    }

    /* ---------- Sessions tab ---------- */
    async function renderSessions() {
      if (DA.mode !== 'backend') return;
      const tbody = $('sessions-tbody'); const statsEl = $('sessions-stats');
      if (!tbody) return;
      let data;
      try { data = await DA.sessions(); }
      catch (ex) { tbody.innerHTML = '<tr><td class="admin-empty" colspan="7">' + esc(ex && ex.message ? ex.message : 'Could not load sessions.') + '</td></tr>'; return; }
      if (!data) return;
      const list = data.sessions || [];
      if (statsEl) statsEl.textContent = data.stats ? (data.stats.active + ' active, ' + data.stats.revoked + ' revoked (this process)') : '';
      if (!list.length) { tbody.innerHTML = '<tr><td class="admin-empty" colspan="7">No active sessions.</td></tr>'; return; }
      tbody.innerHTML = list.map((s) =>
        '<tr>' +
          '<td class="small">' + esc(s.email || '—') + (s.current ? ' <span class="badge text-bg-info">this session</span>' : '') + '</td>' +
          '<td><span class="badge text-bg-secondary role-badge">' + esc(s.role || '') + '</span></td>' +
          '<td class="small font-monospace">' + esc(s.ip || '—') + '</td>' +
          '<td class="small text-truncate maxw-220" title="' + esc(s.ua || '') + '">' + esc(s.ua || '—') + '</td>' +
          '<td class="text-nowrap small">' + esc(fmtTime(s.firstSeen)) + '</td>' +
          '<td class="text-nowrap small">' + esc(fmtTime(s.lastSeen)) + '</td>' +
          '<td class="text-end"><button class="btn btn-sm btn-outline-danger py-0 px-1" data-revoke-session="' + esc(s.jti) + '" title="Revoke this session"><i class="bi bi-x-circle"></i> Revoke</button></td>' +
        '</tr>'
      ).join('');
    }
    function wireSessions() {
      const btn = $('sessions-refresh'); const tbody = $('sessions-tbody');
      if (btn && !btn.dataset.wired) { btn.dataset.wired = '1'; btn.addEventListener('click', renderSessions); }
      if (tbody && !tbody.dataset.wired) {
        tbody.dataset.wired = '1';
        tbody.addEventListener('click', async (e) => {
          const r = e.target.closest('[data-revoke-session]');
          if (!r) return;
          if (!await CITADEL.ui.confirm('Revoke this session? The user will have to sign in again.', { danger: true, okLabel: 'Revoke' })) return;
          try { await DA.revokeSession(r.getAttribute('data-revoke-session')); renderSessions(); }
          catch (ex) { CITADEL.ui.toast(ex && ex.message ? ex.message : 'Could not revoke session.', 'error'); }
        });
      }
    }

    /* ---------- My account (self password change) ---------- */
    function renderMyAccount() {
      const card = $('my-account-card'); const banner = $('must-change-banner');
      const backend = DA.mode === 'backend';
      if (card) card.classList.toggle('d-none', !backend);
      const me = DA.current();
      if (banner) banner.classList.toggle('d-none', !(backend && me && me.mustChange));
    }
    function wireMyAccount() {
      const form = $('pw-form');
      if (!form || form.dataset.wired) return;
      form.dataset.wired = '1';
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = $('pw-msg');
        const cur = $('pw-current').value, next = $('pw-next').value;
        try {
          await DA.changePassword(cur, next);
          if (msg) { msg.className = 'small mt-2 text-success'; msg.textContent = 'Password updated.'; }
          form.reset();
          if (DA._me) DA._me.mustChange = false;   // refresh local flag
          const banner = $('must-change-banner'); if (banner) banner.classList.add('d-none');
          // The mustChange gate is now lifted — load users (which were blocked at
          // login) and re-render so the console is fully functional.
          try { await DA._refreshUsers(); render(); } catch (e) {}
        } catch (ex) {
          if (msg) { msg.className = 'small mt-2 text-danger'; msg.textContent = (ex && ex.message) || 'Could not change password.'; }
        }
      });
    }

    /* ---------- MFA management ---------- */
    async function renderMfa() {
      const card = $('mfa-card'); if (!card) return;
      const backend = DA.mode === 'backend';
      card.classList.toggle('d-none', !backend);
      if (!backend) return;
      $('mfa-setup-area').classList.add('d-none');
      $('mfa-backup-area').classList.add('d-none');
      let st;
      try { st = await DA.mfaStatus(); } catch (e) { return; }
      const status = $('mfa-status');
      if (st && st.enabled) {
        status.innerHTML = '<span class="text-success"><i class="bi bi-shield-check"></i> Enabled</span> — ' + esc(String(st.backupRemaining)) + ' backup code(s) remaining.';
        $('mfa-setup-btn').classList.add('d-none');
        $('mfa-disable-wrap').classList.remove('d-none');
      } else {
        status.innerHTML = '<span class="text-body-secondary"><i class="bi bi-shield-slash"></i> Not enabled</span> — protect your admin account with an authenticator app.';
        $('mfa-setup-btn').classList.remove('d-none');
        $('mfa-disable-wrap').classList.add('d-none');
      }
    }
    function wireMfa() {
      const card = $('mfa-card'); if (!card || card.dataset.wired) return;
      card.dataset.wired = '1';
      const msg = (t, ok) => { const m = $('mfa-msg'); if (m) { m.className = 'small mt-2 ' + (ok ? 'text-success' : 'text-danger'); m.textContent = t; } };
      $('mfa-setup-btn').addEventListener('click', async () => {
        try {
          const s = await DA.mfaSetup();
          $('mfa-secret').textContent = s.secret;
          const a = $('mfa-otpauth'); a.href = s.otpauth;
          $('mfa-setup-area').classList.remove('d-none');
          $('mfa-setup-btn').classList.add('d-none');
          msg('', true);
        } catch (e) { msg(e && e.message ? e.message : 'Could not start setup.'); }
      });
      $('mfa-enable-btn').addEventListener('click', async () => {
        try {
          const out = await DA.mfaEnable(($('mfa-code').value || '').trim());
          $('mfa-backup-codes').textContent = (out.backupCodes || []).join('\n');
          $('mfa-backup-area').classList.remove('d-none');
          $('mfa-setup-area').classList.add('d-none');
          $('mfa-code').value = '';
          await renderMfa();
          msg('MFA enabled.', true);
        } catch (e) { msg(e && e.message ? e.message : 'Could not enable MFA.'); }
      });
      $('mfa-disable-btn').addEventListener('click', async () => {
        try {
          await DA.mfaDisable(($('mfa-disable-pass').value || ''));
          $('mfa-disable-pass').value = '';
          await renderMfa();
          msg('MFA disabled.', true);
        } catch (e) { msg(e && e.message ? e.message : 'Could not disable MFA.'); }
      });
    }

    /* ---------- Branding ---------- */
    function normHex(v) { return (v && /^#?[0-9a-fA-F]{3,8}$/.test(v)) ? (v[0] === '#' ? v : '#' + v) : ''; }
    function updateBrandPreview() {
      const u = $('brand-logo-url'), n = $('brand-org-name');
      const pn = $('brand-preview-name'); if (pn) pn.textContent = (n && n.value.trim()) || 'CITADEL';
      const img = $('brand-preview-logo'), mark = $('brand-preview-mark');
      if (img) {
        let url = u && u.value.trim();
        // Only preview an http(s) / data:image URL — same allowlist the store enforces.
        if (url && !/^(https?:\/\/|data:image\/)/i.test(url)) url = '';
        if (url) {
          img.onerror = function () { img.classList.add('d-none'); if (mark) mark.classList.remove('d-none'); };
          img.onload = function () { img.classList.remove('d-none'); if (mark) mark.classList.add('d-none'); };
          img.src = url;
        } else { img.removeAttribute('src'); img.classList.add('d-none'); if (mark) mark.classList.remove('d-none'); }
      }
    }
    function renderBranding() {
      if (!window.CITADEL || !CITADEL.branding) return;
      const b = CITADEL.branding.get();
      if ($('brand-logo-url')) $('brand-logo-url').value = b.logoUrl || '';
      if ($('brand-org-name')) $('brand-org-name').value = (b.orgName && b.orgName !== 'CITADEL') ? b.orgName : '';
      if ($('brand-accent')) $('brand-accent').value = normHex(b.accent) || '#22b8ff';
      updateBrandPreview();
    }
    async function saveBranding(b) {
      CITADEL.branding.set(b); CITADEL.branding.apply(b);
      if (DA.mode === 'backend') { try { await CITADEL.branding.saveToBackend(b, { Authorization: 'Bearer ' + DA.token() }); } catch (e) {} }
    }
    function wireBranding() {
      const u = $('brand-logo-url'), n = $('brand-org-name'), a = $('brand-accent');
      [u, n, a].forEach((el) => { if (el && !el.dataset.wiredPv) { el.dataset.wiredPv = '1'; el.addEventListener('input', updateBrandPreview); } });
      const file = $('brand-logo-file');
      if (file && !file.dataset.wired) {
        file.dataset.wired = '1';
        file.addEventListener('change', () => {
          const f = file.files && file.files[0]; if (!f) return;
          if (f.size > 180 * 1024) { CITADEL.ui.toast('Image is too large (max ~180 KB). Use a URL for larger logos.', 'error'); file.value = ''; return; }
          const reader = new FileReader();
          reader.onload = () => { if (u) { u.value = String(reader.result || ''); updateBrandPreview(); } };
          reader.readAsDataURL(f);
        });
      }
      const save = $('brand-save');
      if (save && !save.dataset.wired) {
        save.dataset.wired = '1';
        save.addEventListener('click', async () => {
          await saveBranding({ logoUrl: u ? u.value.trim() : '', orgName: n ? n.value.trim() : '', accent: a ? a.value : '' });
          const sv = $('brand-saved'); if (sv) { sv.classList.remove('d-none'); setTimeout(() => sv.classList.add('d-none'), 1800); }
        });
      }
      const reset = $('brand-reset');
      if (reset && !reset.dataset.wired) {
        reset.dataset.wired = '1';
        reset.addEventListener('click', async () => { await saveBranding({ logoUrl: '', orgName: '', accent: '' }); renderBranding(); });
      }
    }

    /* ---------- logout ---------- */
    $('logout-btn').addEventListener('click', () => {
      DA.logout();
      render();
    });

    /* ---------- mode badge ---------- */
    function renderModeBadge() {
      const b = $('mode-badge');
      if (!b) return;
      if (DA.mode === 'backend') {
        b.textContent = 'Backend (JWT)';
        b.className = 'badge text-bg-success';
      } else {
        b.textContent = 'Local (browser)';
        b.className = 'badge text-bg-secondary';
      }
    }

    /* ---------- master render ---------- */
    function render() {
      renderModeBadge();
      const me = DA.current();
      if (!me || me.role !== 'admin') {
        show('login');
        setupLogin();
        return;
      }
      show('console');
      $('me-name').textContent = me.name;
      $('me-role').textContent = roleLabel(me.role);
      setupTabs();
      renderUsers(); wireUsers();
      renderPerms(); wirePerms();
      renderSettings(); wireSettings();
      renderMyAccount(); wireMyAccount();
      wireMfa(); renderMfa();
      renderBranding(); wireBranding();
      // Activity & Sessions tabs are backend-only (no server state in local mode).
      const backend = DA.mode === 'backend';
      const navAudit = $('nav-audit'); if (navAudit) navAudit.classList.toggle('d-none', !backend);
      const navSessions = $('nav-sessions'); if (navSessions) navSessions.classList.toggle('d-none', !backend);
      if (backend) { wireAudit(); renderAudit(); wireSessions(); renderSessions(); }
      // Default-password admin: jump straight to the Settings tab where the
      // change-password form + banner live, so the required action is in view.
      if (me.mustChange) {
        const t = $('admin-tabs') && $('admin-tabs').querySelector('.tab-btn[data-tab="tab-settings"]');
        if (t) t.click();
      }
    }

    /* ---------- boot ---------- */
    (async function boot() {
      if (!auth) { document.body.innerHTML = '<div class="container py-5">Access control API failed to load.</div>'; return; }
      try { await DA.init(); } catch (e) { /* fall through; render handles unauthenticated state */ }
      if (window.CITADEL && CITADEL.branding) { CITADEL.branding.apply(); try { await CITADEL.branding.syncFromBackend(); } catch (e) {} }
      render();
    })();
  })();
