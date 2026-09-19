/* REDOUBT — IAM console client. Served from 'self'; no inline handlers. */
(function () {
  'use strict';
  var CFG = window.REDOUBT_IAM;
  if (!CFG) { return; }

  var els = {
    programSelect: document.getElementById('programSelect'),
    userSearch: document.getElementById('userSearch'),
    userList: document.getElementById('userList'),
    editor: document.getElementById('editor'),
    editorTitle: document.getElementById('editorTitle'),
    editorSub: document.getElementById('editorSub'),
    saveBtn: document.getElementById('saveBtn'),
    expandAll: document.getElementById('expandAll'),
    collapseAll: document.getElementById('collapseAll'),
    permCount: document.getElementById('permCount'),
    toast: document.getElementById('toast')
  };

  var state = { users: [], activeUser: null, original: {}, current: {}, dirty: false };

  function toast(msg, isErr) {
    els.toast.textContent = msg;
    els.toast.className = 'toast show' + (isErr ? ' err' : '');
    setTimeout(function () { els.toast.className = 'toast'; }, 2600);
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  function initials(name) {
    return (name || '?').split(/\s+/).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase();
  }

  if (els.programSelect) {
    els.programSelect.addEventListener('change', function () { document.getElementById('programForm').submit(); });
  }

  // --- load users --------------------------------------------------------
  function loadUsers() {
    fetch(CFG.endpoints.users + '?program_id=' + encodeURIComponent(CFG.programId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { state.users = data.users || []; renderUsers(); })
      .catch(function () { els.userList.innerHTML = '<div class="empty-state-sm">Failed to load users.</div>'; });
  }

  function renderUsers() {
    var q = (els.userSearch.value || '').toLowerCase();
    var list = state.users.filter(function (u) {
      return !q || (u.name || '').toLowerCase().indexOf(q) >= 0 || (u.email || '').toLowerCase().indexOf(q) >= 0;
    });
    if (!list.length) { els.userList.innerHTML = '<div class="empty-state-sm">No users.</div>'; return; }
    els.userList.innerHTML = '';
    list.forEach(function (u) {
      var card = document.createElement('div');
      card.className = 'user-card' + (state.activeUser && state.activeUser.id === u.id ? ' active' : '');
      card.innerHTML =
        '<span class="avatar">' + esc(initials(u.name)) + '</span>' +
        '<span class="user-meta"><span class="nm">' + esc(u.name) + '</span>' +
        '<span class="dp">' + esc((u.roles || []).join(', ')) + (u.is_us_person === false ? ' · non-US' : '') + '</span></span>';
      card.addEventListener('click', function () { selectUser(u); });
      els.userList.appendChild(card);
    });
  }

  els.userSearch.addEventListener('input', renderUsers);

  // --- select user + load perms -----------------------------------------
  function selectUser(u) {
    if (state.dirty && !window.confirm('Discard unsaved changes?')) { return; }
    state.activeUser = u; state.dirty = false; setSaveEnabled(false);
    renderUsers();
    els.editor.innerHTML = '<div class="empty-state-sm">Loading permissions…</div>';
    els.editorTitle.textContent = u.name;
    els.editorSub.textContent = (u.email || '') + '  ·  ' + (u.roles || []).join(', ');
    fetch(CFG.endpoints.user + '?program_id=' + encodeURIComponent(CFG.programId) + '&user_id=' + encodeURIComponent(u.id),
      { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.original = data.state || {};
        state.current = Object.assign({}, state.original);
        renderEditor();
      })
      .catch(function () { els.editor.innerHTML = '<div class="empty-state-sm">Failed to load permissions.</div>'; });
  }

  // --- render accordions -------------------------------------------------
  var EFFECTS = [['role', 'Role'], ['grant', 'Grant'], ['deny', 'Deny']];

  function renderEditor() {
    var cat = CFG.catalog, first = true, html = '';
    Object.keys(cat).forEach(function (modKey) {
      var mod = cat[modKey], keys = Object.keys(mod.actions);
      var granted = keys.filter(function (k) { var s = state.current[k]; return s === 'role' || s === 'grant'; }).length;
      html += '<div class="mod-acc' + (first ? ' open' : '') + '" data-mod="' + esc(modKey) + '">' +
        '<div class="mod-head"><span class="t">' + esc(mod.icon) + ' ' + esc(mod.label) + '</span>' +
        '<span style="display:flex;gap:8px;align-items:center">' +
        '<span class="mod-count"><span class="cnt">' + granted + '</span>/' + keys.length + '</span>' +
        '<button class="btn btn-sm" type="button" data-act="grantall">Grant all</button>' +
        '<button class="btn btn-sm" type="button" data-act="clearall">Clear</button></span></div>' +
        '<div class="mod-body">';
      keys.forEach(function (k) {
        html += permRow(k, mod.actions[k]);
      });
      html += '</div></div>';
      first = false;
    });
    els.editor.innerHTML = html;
    wireEditor();
    updateCount();
  }

  function permRow(key, label) {
    var s = state.current[key] || 'none';
    var dot = s === 'grant' ? 'dot-grant' : (s === 'role' ? 'dot-role' : 'dot-none');
    var seg = '<span class="seg" data-key="' + esc(key) + '">';
    EFFECTS.forEach(function (e) {
      var on = (s === e[0]) || (s === 'none' && e[0] === 'role' ? false : false);
      seg += '<button type="button" data-eff="' + e[0] + '" class="' + (s === e[0] ? 'on' : '') + '">' + e[1] + '</button>';
    });
    seg += '</span>';
    return '<div class="perm-row"><span><i class="dot ' + dot + '"></i> ' +
      '<span class="perm-name">' + esc(label) + '</span> <span class="perm-key">' + esc(key) + '</span></span>' + seg + '</div>';
  }

  function wireEditor() {
    // accordion toggles
    els.editor.querySelectorAll('.mod-head').forEach(function (h) {
      h.addEventListener('click', function (e) {
        if (e.target.closest('button')) { return; }
        h.parentNode.classList.toggle('open');
      });
    });
    // grant all / clear per module
    els.editor.querySelectorAll('[data-act]').forEach(function (b) {
      b.addEventListener('click', function () {
        var acc = b.closest('.mod-acc'), modKey = acc.getAttribute('data-mod');
        var keys = Object.keys(CFG.catalog[modKey].actions);
        keys.forEach(function (k) { state.current[k] = b.getAttribute('data-act') === 'grantall' ? 'grant' : 'role'; });
        markDirty(); renderEditor();
      });
    });
    // segmented effect buttons
    els.editor.querySelectorAll('.seg').forEach(function (seg) {
      seg.querySelectorAll('button').forEach(function (btn) {
        btn.addEventListener('click', function () {
          state.current[seg.getAttribute('data-key')] = btn.getAttribute('data-eff');
          markDirty(); renderEditor();
        });
      });
    });
  }

  function updateCount() {
    var eff = 0;
    Object.keys(state.current).forEach(function (k) { if (state.current[k] === 'role' || state.current[k] === 'grant') { eff++; } });
    els.permCount.textContent = eff + ' / ' + CFG.total + ' permissions effective';
  }

  function markDirty() { state.dirty = true; setSaveEnabled(true); }
  function setSaveEnabled(on) {
    els.saveBtn.disabled = !on;
    els.saveBtn.textContent = on ? 'Save changes •' : 'Save changes';
  }

  els.expandAll.addEventListener('click', function () {
    els.editor.querySelectorAll('.mod-acc').forEach(function (a) { a.classList.add('open'); });
  });
  els.collapseAll.addEventListener('click', function () {
    els.editor.querySelectorAll('.mod-acc').forEach(function (a) { a.classList.remove('open'); });
  });

  // --- save --------------------------------------------------------------
  els.saveBtn.addEventListener('click', function () {
    if (!state.activeUser) { return; }
    var changes = {};
    Object.keys(state.current).forEach(function (k) {
      if (state.current[k] !== state.original[k]) { changes[k] = state.current[k]; }
    });
    if (!Object.keys(changes).length) { toast('No changes'); return; }
    els.saveBtn.disabled = true;
    fetch(CFG.endpoints.save, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CFG.csrf },
      body: JSON.stringify({ program_id: CFG.programId, user_id: state.activeUser.id, changes: changes })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok && res.j.ok) {
          CFG.csrf = res.j.csrf || CFG.csrf;
          state.original = Object.assign({}, state.current);
          state.dirty = false; setSaveEnabled(false);
          toast('Saved ' + res.j.applied + ' change(s)');
        } else {
          setSaveEnabled(true);
          toast('Save failed: ' + (res.j.error || 'error'), true);
        }
      }).catch(function () { setSaveEnabled(true); toast('Save failed', true); });
  });

  loadUsers();
})();
