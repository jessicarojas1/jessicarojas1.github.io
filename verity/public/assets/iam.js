(function () {
  'use strict';
  var boot = window.VERITY_IAM;
  if (!boot) {
    return;
  }

  var csrf = boot.csrf;
  var userListEl = document.getElementById('userList');
  var userSearchEl = document.getElementById('userSearch');
  var editorEl = document.getElementById('editor');
  var detailsEl = document.getElementById('userDetails');
  var selectedInfoEl = document.getElementById('selectedUserInfo');
  var saveBtn = document.getElementById('saveBtn');
  var currentUserId = boot.currentUserId; // the signed-in admin's own id — cannot self-disable

  // Cryptographically random password generator (Math.random is NOT suitable
  // for this — it generates real account credentials). No ambiguous-looking
  // characters (0/O, 1/l/I) since this is meant to be read and typed by a
  // human, not pasted automatically.
  function generatePassword() {
    var charset = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#%+=';
    var bytes = new Uint32Array(20);
    crypto.getRandomValues(bytes);
    var out = '';
    for (var i = 0; i < bytes.length; i++) {
      out += charset[bytes[i] % charset.length];
    }
    return out;
  }

  var allUsers = [];
  var selectedUserId = null;
  var currentStates = {};   // permission_key -> 'role'|'grant'|'deny'|'none'
  var originalStates = {};
  var dirty = false;

  function toast(msg, kind) {
    var box = document.getElementById('toast');
    var el = document.createElement('div');
    el.className = 'msg' + (kind ? ' ' + kind : '');
    el.textContent = msg;
    box.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('show'); });
    setTimeout(function () {
      el.classList.remove('show');
      setTimeout(function () { el.remove(); }, 200);
    }, 3000);
  }

  function setDirty(v) {
    dirty = v;
    saveBtn.disabled = !v;
    saveBtn.textContent = v ? 'Save changes *' : 'Save changes';
  }

  function initials(name) {
    return (name || '?').split(' ').map(function (p) { return p[0]; }).join('').slice(0, 2).toUpperCase();
  }

  function renderUserList() {
    var q = (userSearchEl.value || '').toLowerCase();
    userListEl.innerHTML = '';
    allUsers
      .filter(function (u) { return !q || (u.name + ' ' + u.email).toLowerCase().indexOf(q) !== -1; })
      .forEach(function (u) {
        var row = document.createElement('div');
        row.className = 'iam-user-item' + (u.id === selectedUserId ? ' active' : '');

        var avatar = document.createElement('span');
        avatar.className = 'iam-avatar';
        avatar.textContent = initials(u.name); // textContent, not innerHTML — safe even though initials() isn't HTML-escaped

        var name = document.createElement('div');
        name.textContent = u.name;
        var roles = document.createElement('div');
        roles.className = 'hint';
        roles.textContent = u.roles.join(', ') || 'No roles';
        var info = document.createElement('span');
        info.appendChild(name);
        info.appendChild(roles);

        row.appendChild(avatar);
        row.appendChild(info);
        row.addEventListener('click', function () { selectUser(u.id); });
        userListEl.appendChild(row);
      });
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function loadUsers() {
    fetch(boot.endpoints.users, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        allUsers = data.users || [];
        renderUserList();
      });
  }

  function selectUser(id) {
    selectedUserId = id;
    renderUserList();
    fetch(boot.endpoints.user + '?id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        currentStates = Object.assign({}, data.states);
        originalStates = Object.assign({}, data.states);
        var u = allUsers.filter(function (x) { return x.id === id; })[0];
        selectedInfoEl.textContent = u ? (u.name + ' — ' + u.email) : '';
        renderEditor(data.user.roles || []);
        renderUserDetails(data.user);
        setDirty(false);
      });
  }

  function renderUserDetails(u) {
    if (!boot.canManage) {
      detailsEl.innerHTML = '';
      return;
    }
    var isSelf = u.id === currentUserId;
    var peopleOptions = '<option value="">—</option>' + boot.people.map(function (p) {
      return '<option value="' + p.id + '"' + (p.id === u.personId ? ' selected' : '') + '>' + escapeHtml(p.name) + '</option>';
    }).join('');
    var statusOptions = ['active', 'invited', 'disabled'].map(function (s) {
      var disabledAttr = (isSelf && s !== 'active') ? ' disabled' : '';
      return '<option value="' + s + '"' + (s === u.status ? ' selected' : '') + disabledAttr + '>' + s + '</option>';
    }).join('');

    detailsEl.innerHTML =
      '<fieldset class="mb-14"><legend>User Details</legend>' +
      '<div class="field-row">' +
      '<div class="field"><label for="ud_name">Display Name</label><input type="text" id="ud_name" value="' + escapeHtml(u.displayName) + '"></div>' +
      '<div class="field"><label for="ud_email">Email</label><input type="email" id="ud_email" value="' + escapeHtml(u.email) + '"></div>' +
      '</div>' +
      '<div class="field-row">' +
      '<div class="field"><label for="ud_person">Linked Identity</label><select id="ud_person">' + peopleOptions + '</select></div>' +
      '<div class="field"><label for="ud_status">Status</label><select id="ud_status"' + (isSelf ? ' disabled' : '') + '>' + statusOptions + '</select>' +
      (isSelf ? '<p class="hint">You cannot change your own status.</p>' : '') + '</div>' +
      '</div>' +
      '<button type="button" class="btn sm" id="saveDetailsBtn">Save Details</button>' +
      (isSelf ? '' : ' <button type="button" class="btn sm" id="applyStatusBtn">Update Status</button>') +
      '</fieldset>' +
      '<fieldset class="mb-14"><legend>Reset Password</legend>' +
      '<div class="field-row">' +
      '<div class="field"><label for="ud_password">New Password</label><input type="text" id="ud_password" minlength="' + boot.minPasswordLength + '"></div>' +
      '<div class="field field-end"><button type="button" class="btn sm" id="generateResetPassword">Generate</button></div>' +
      '</div>' +
      '<p class="hint">No email delivery in this build — share the new password with the user directly.</p>' +
      '<button type="button" class="btn sm danger" id="setPasswordBtn">Set Password</button>' +
      '</fieldset>' +
      '<fieldset class="mb-14"><legend>Multi-Factor Authentication</legend>' +
      '<p>Status: <span class="badge ' + (u.mfaEnabled ? 'b-ok">Enabled' : 'b-neutral">Not enabled') + '</span></p>' +
      (u.mfaEnabled
        ? '<p class="hint">Resetting clears their enrollment — they set it up again from scratch next time they sign in. Use this if they lost their device and exhausted their recovery codes.</p>' +
          '<button type="button" class="btn sm danger" id="resetMfaBtn">Reset MFA</button>'
        : '') +
      '</fieldset>';

    document.getElementById('generateResetPassword').addEventListener('click', function () {
      document.getElementById('ud_password').value = generatePassword();
    });
    document.getElementById('saveDetailsBtn').addEventListener('click', function () { saveUserDetails(u.id); });
    document.getElementById('setPasswordBtn').addEventListener('click', function () { resetPassword(u.id); });
    var applyStatusBtn = document.getElementById('applyStatusBtn');
    if (applyStatusBtn) {
      applyStatusBtn.addEventListener('click', function () { applyStatus(u.id); });
    }
    var resetMfaBtn = document.getElementById('resetMfaBtn');
    if (resetMfaBtn) {
      resetMfaBtn.addEventListener('click', function () { resetMfa(u.id); });
    }
  }

  function saveUserDetails(userId) {
    fetch(boot.endpoints.updateDetails, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        _csrf: csrf,
        user_id: userId,
        display_name: document.getElementById('ud_name').value,
        email: document.getElementById('ud_email').value,
        person_id: document.getElementById('ud_person').value,
      }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          csrf = data.csrf;
          toast('User details saved.', 'ok');
          loadUsers();
        } else {
          toast(data.error || 'Save failed.', 'err');
        }
      })
      .catch(function () { toast('Save failed — network error.', 'err'); });
  }

  function resetPassword(userId) {
    var newPassword = document.getElementById('ud_password').value;
    if (!newPassword) {
      toast('Enter or generate a password first.', 'err');
      return;
    }
    fetch(boot.endpoints.resetPassword, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ _csrf: csrf, user_id: userId, new_password: newPassword }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          csrf = data.csrf;
          document.getElementById('ud_password').value = '';
          toast('Password updated.', 'ok');
        } else {
          toast(data.error || 'Password reset failed.', 'err');
        }
      })
      .catch(function () { toast('Password reset failed — network error.', 'err'); });
  }

  function resetMfa(userId) {
    if (!window.confirm('Reset this user\'s multi-factor authentication? They will need to set it up again from scratch.')) {
      return;
    }
    fetch(boot.endpoints.resetMfa, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ _csrf: csrf, user_id: userId }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          csrf = data.csrf;
          toast('MFA reset.', 'ok');
          selectUser(userId);
        } else {
          toast(data.error || 'MFA reset failed.', 'err');
        }
      })
      .catch(function () { toast('MFA reset failed — network error.', 'err'); });
  }

  function applyStatus(userId) {
    var status = document.getElementById('ud_status').value;
    if (!window.confirm('Set this user\'s status to "' + status + '"?')) {
      return;
    }
    fetch(boot.endpoints.setStatus, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ _csrf: csrf, user_id: userId, status: status }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          csrf = data.csrf;
          toast('Status updated.', 'ok');
          loadUsers();
        } else {
          toast(data.error || 'Status update failed.', 'err');
        }
      })
      .catch(function () { toast('Status update failed — network error.', 'err'); });
  }

  function cycleState(key) {
    var order = ['role', 'grant', 'deny'];
    var idx = order.indexOf(currentStates[key]);
    currentStates[key] = order[(idx + 1) % order.length];
    renderEditor(currentRoles());
    setDirty(JSON.stringify(currentStates) !== JSON.stringify(originalStates));
  }

  var roleCheckboxes = {};
  function currentRoles() {
    return Object.keys(roleCheckboxes).filter(function (k) { return roleCheckboxes[k]; });
  }

  function renderEditor(activeRoles) {
    roleCheckboxes = {};
    activeRoles.forEach(function (r) { roleCheckboxes[r] = true; });

    if (!selectedUserId) {
      editorEl.innerHTML = '';
      return;
    }
    var html = '';
    if (boot.canManage) {
      html += '<fieldset class="mb-14"><legend>Roles</legend><div class="field-row">';
      boot.roles.forEach(function (r) {
        html += '<label class="check-inline">' +
          '<input type="checkbox" class="role-cb w-auto" data-role="' + r.key + '"' +
          (roleCheckboxes[r.key] ? ' checked' : '') + '>' + escapeHtml(r.label) + '</label>';
      });
      html += '</div></fieldset>';
    }

    Object.keys(boot.catalog).forEach(function (modKey) {
      var mod = boot.catalog[modKey];
      var keys = Object.keys(mod.actions);
      var granted = keys.filter(function (k) { return currentStates[k] && currentStates[k] !== 'none'; }).length;
      html += '<details class="iam-module" open><summary>' + mod.icon + ' ' + escapeHtml(mod.label) +
        '<span class="count">' + granted + '/' + keys.length + '</span></summary>';
      keys.forEach(function (k) {
        var state = currentStates[k] || 'none';
        html += '<div class="iam-action-row"><span>' + escapeHtml(mod.actions[k]) + '</span>' +
          '<span class="state">' + (boot.canManage
            ? '<button type="button" class="state-btn active-' + state + '" data-perm="' + k + '">' + state + '</button>'
            : '<span class="badge b-neutral">' + state + '</span>') + '</span></div>';
      });
      html += '</details>';
    });
    editorEl.innerHTML = html;

    editorEl.querySelectorAll('.state-btn').forEach(function (btn) {
      btn.addEventListener('click', function () { cycleState(btn.getAttribute('data-perm')); });
    });
    editorEl.querySelectorAll('.role-cb').forEach(function (cb) {
      cb.addEventListener('change', function () {
        roleCheckboxes[cb.getAttribute('data-role')] = cb.checked;
        setDirty(true);
      });
    });
  }

  document.getElementById('expandAll').addEventListener('click', function () {
    editorEl.querySelectorAll('details').forEach(function (d) { d.open = true; });
  });
  document.getElementById('collapseAll').addEventListener('click', function () {
    editorEl.querySelectorAll('details').forEach(function (d) { d.open = false; });
  });
  userSearchEl.addEventListener('input', renderUserList);

  saveBtn.addEventListener('click', function () {
    if (!selectedUserId) {
      return;
    }
    saveBtn.disabled = true;
    var changes = {};
    Object.keys(currentStates).forEach(function (k) {
      if (currentStates[k] !== originalStates[k]) {
        changes[k] = currentStates[k];
      }
    });
    fetch(boot.endpoints.save, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ _csrf: csrf, user_id: selectedUserId, roles: currentRoles(), changes: changes }),
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.ok) {
          csrf = data.csrf;
          originalStates = Object.assign({}, currentStates);
          setDirty(false);
          toast('Saved ' + data.applied + ' permission change(s).', 'ok');
          loadUsers();
        } else {
          toast(data.error || 'Save failed.', 'err');
          saveBtn.disabled = false;
        }
      })
      .catch(function () {
        toast('Save failed — network error.', 'err');
        saveBtn.disabled = false;
      });
  });

  var generateInviteBtn = document.getElementById('generatePassword');
  if (generateInviteBtn) {
    generateInviteBtn.addEventListener('click', function () {
      document.getElementById('new_user_password').value = generatePassword();
    });
  }

  loadUsers();
})();
