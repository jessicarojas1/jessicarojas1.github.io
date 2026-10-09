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
  var selectedInfoEl = document.getElementById('selectedUserInfo');
  var saveBtn = document.getElementById('saveBtn');

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
        setDirty(false);
      });
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

  loadUsers();
})();
