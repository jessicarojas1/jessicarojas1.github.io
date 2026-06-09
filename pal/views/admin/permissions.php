<?php
$pageTitle    = 'Permissions';
$activeModule = 'admin_permissions';
$breadcrumbs  = [['Administration', '/admin'], ['Permissions', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Permissions</h1><p class="page-subtitle">Granular module &times; action access control (IAM)</p></div>
</div>

<?php if (empty($users)): ?>
  <div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-people"></i><p>No users to configure.</p><a href="/admin/users/create" class="btn btn-sm btn-primary">Create a user</a></div></div></div>
<?php else: ?>
<div class="iam">
  <!-- ── Left pane: user list ── -->
  <div class="iam-list">
    <div class="form-group" style="margin:0 0 10px">
      <input type="search" id="iamUserSearch" class="form-control" placeholder="Search users…" autocomplete="off">
    </div>
    <div id="iamUserList">
    <?php foreach ($users as $u): $uid = (int)$u['id']; ?>
      <a href="/admin/users/<?= $uid ?>/permissions" class="iam-user <?= $uid === (int)$selected['id'] ? 'active' : '' ?>" data-name="<?= Security::h(strtolower($u['name'] . ' ' . $u['email'])) ?>">
        <?= View::avatar($u['name']) ?>
        <div style="min-width:0">
          <div class="iam-user-name"><?= Security::h($u['name']) ?></div>
          <div class="form-hint" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= Security::h($u['department'] ?: $u['email']) ?></div>
        </div>
        <span class="badge badge-blue" style="margin-left:auto"><?= Security::h(Auth::roleLabel($u['role'])) ?></span>
      </a>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- ── Right pane: permission editor ── -->
  <div class="card" id="iamEditor" data-user-id="<?= (int)$selected['id'] ?>">
    <div class="card-header">
      <div class="card-header-left">
        <span class="card-title"><i class="bi bi-shield-lock-fill"></i> <?= Security::h($selected['name']) ?></span>
        <span class="form-hint"><?= Security::h($selected['email']) ?> · <?= Security::h(Auth::roleLabel($selected['role'])) ?></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <span id="iamDirty" class="badge badge-warning" hidden>Unsaved changes</span>
        <span class="form-hint">Total granted: <strong id="iamTotal">0</strong></span>
        <button type="button" class="btn btn-sm btn-ghost" id="iamExpandAll"><i class="bi bi-arrows-expand"></i> Expand All</button>
        <button type="button" class="btn btn-sm btn-ghost" id="iamCollapseAll"><i class="bi bi-arrows-collapse"></i> Collapse All</button>
        <button type="button" class="btn btn-sm btn-primary" id="iamSave"><i class="bi bi-save"></i> Save</button>
      </div>
    </div>
    <div class="card-body">
      <?php if ($selected['role'] === 'admin'): ?>
        <div class="alert-box warning" style="margin-bottom:16px"><i class="bi bi-info-circle-fill"></i> System Administrators implicitly have <strong>all</strong> permissions. This editor is informational for admin accounts.</div>
      <?php endif; ?>

      <?php $mi = 0; foreach ($catalog as $module => $actions):
        [$icon, $hex] = Auth::moduleMeta($module);
        $defCount = 0; $totalGranted = 0;
        foreach ($actions as $a) {
          $key = $module . '.' . $a;
          $isDefault = in_array($key, $defaults, true);
          $isExplicit = in_array($key, $explicit, true);
          if ($isDefault) { $defCount++; $totalGranted++; }
          elseif ($isExplicit) { $totalGranted++; }
        }
        $total = count($actions);
        $open  = $mi < 3;
        $mi++;
      ?>
      <div class="perm-module" data-module="<?= Security::h($module) ?>">
        <div class="perm-module-head" data-perm-toggle>
          <span class="perm-module-icon" style="background:<?= Security::h($hex) ?>;color:#fff"><i class="bi <?= Security::h($icon) ?>"></i></span>
          <span class="perm-module-label"><?= Security::h(ucfirst($module)) ?></span>
          <span class="badge badge-gray perm-count"><span class="perm-count-n"><?= $totalGranted ?></span>/<?= $total ?></span>
          <span class="perm-actions-batch" style="margin-left:auto;display:flex;gap:6px">
            <button type="button" class="btn btn-sm btn-ghost perm-grant-all"><i class="bi bi-check2-all"></i> Grant All</button>
            <button type="button" class="btn btn-sm btn-ghost perm-clear-all"><i class="bi bi-x"></i> Clear All</button>
          </span>
          <i class="bi bi-chevron-<?= $open ? 'up' : 'down' ?> perm-chevron"></i>
        </div>
        <div class="perm-actions"<?= $open ? '' : ' hidden' ?>>
          <?php foreach ($actions as $a):
            $key = $module . '.' . $a;
            $isDefault = in_array($key, $defaults, true);
            $isExplicit = in_array($key, $explicit, true);
            $checked = $isDefault || $isExplicit;
          ?>
          <label class="perm-chk">
            <?php if ($isDefault): ?>
              <span class="perm-dot" style="background:var(--success)" title="Role default"></span>
              <input type="checkbox" checked disabled data-perm="<?= Security::h($key) ?>" data-default="1">
              <span class="perm-chk-label"><?= Security::h($a) ?></span>
              <span class="form-hint" style="margin-left:auto">role default</span>
            <?php else: ?>
              <span class="perm-dot" style="background:<?= $checked ? 'var(--warning)' : 'var(--border, #cbd5e1)' ?>"></span>
              <input type="checkbox" <?= $checked ? 'checked' : '' ?> data-perm="<?= Security::h($key) ?>" data-explicit="1">
              <span class="perm-chk-label"><?= Security::h($a) ?></span>
            <?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
(function () {
  var editor = document.getElementById('iamEditor');
  if (!editor) return;
  var userId = parseInt(editor.getAttribute('data-user-id'), 10);
  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var csrf = csrfMeta ? csrfMeta.content : '';
  var dirty = false;

  function toast(msg, isError) {
    var t = document.createElement('div');
    t.className = 'alert-box ' + (isError ? 'error' : 'success');
    t.style.position = 'fixed';
    t.style.right = '20px';
    t.style.bottom = '20px';
    t.style.zIndex = '9999';
    t.style.maxWidth = '360px';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3200);
  }

  function setDirty(v) {
    dirty = v;
    var ind = document.getElementById('iamDirty');
    if (ind) ind.hidden = !v;
  }

  function updateCounts() {
    var total = 0;
    editor.querySelectorAll('.perm-module').forEach(function (mod) {
      var n = mod.querySelectorAll('input[type=checkbox]:checked').length;
      total += n;
      var cn = mod.querySelector('.perm-count-n');
      if (cn) cn.textContent = String(n);
    });
    var t = document.getElementById('iamTotal');
    if (t) t.textContent = String(total);
  }

  function refreshDot(cb) {
    var label = cb.closest('.perm-chk');
    if (!label) return;
    var dot = label.querySelector('.perm-dot');
    if (!dot || cb.hasAttribute('data-default')) return;
    dot.style.background = cb.checked ? 'var(--warning)' : 'var(--border, #cbd5e1)';
  }

  // toggle explicit checkboxes
  editor.querySelectorAll('input[data-explicit]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      refreshDot(cb);
      updateCounts();
      setDirty(true);
    });
  });

  // accordion toggles
  editor.querySelectorAll('[data-perm-toggle]').forEach(function (head) {
    head.addEventListener('click', function (e) {
      if (e.target.closest('button')) return; // don't toggle when clicking batch buttons
      var body = head.parentNode.querySelector('.perm-actions');
      var chev = head.querySelector('.perm-chevron');
      if (!body) return;
      body.hidden = !body.hidden;
      if (chev) {
        chev.classList.toggle('bi-chevron-up', !body.hidden);
        chev.classList.toggle('bi-chevron-down', body.hidden);
      }
    });
  });

  function setModule(open) {
    editor.querySelectorAll('.perm-module').forEach(function (mod) {
      var body = mod.querySelector('.perm-actions');
      var chev = mod.querySelector('.perm-chevron');
      if (body) body.hidden = !open;
      if (chev) {
        chev.classList.toggle('bi-chevron-up', open);
        chev.classList.toggle('bi-chevron-down', !open);
      }
    });
  }
  document.getElementById('iamExpandAll').addEventListener('click', function () { setModule(true); });
  document.getElementById('iamCollapseAll').addEventListener('click', function () { setModule(false); });

  // grant/clear all per module
  editor.querySelectorAll('.perm-grant-all').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mod = btn.closest('.perm-module');
      mod.querySelectorAll('input[data-explicit]').forEach(function (cb) { if (!cb.checked) { cb.checked = true; refreshDot(cb); } });
      updateCounts(); setDirty(true);
    });
  });
  editor.querySelectorAll('.perm-clear-all').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var mod = btn.closest('.perm-module');
      mod.querySelectorAll('input[data-explicit]').forEach(function (cb) { if (cb.checked) { cb.checked = false; refreshDot(cb); } });
      updateCounts(); setDirty(true);
    });
  });

  // save
  document.getElementById('iamSave').addEventListener('click', function () {
    var btn = this;
    var perms = [];
    // collect explicit checked + role-default checked (defaults are inherited but we persist only explicit)
    editor.querySelectorAll('input[data-explicit]:checked').forEach(function (cb) {
      perms.push(cb.getAttribute('data-perm'));
    });
    btn.disabled = true;
    fetch('/admin/permissions/save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_id: userId, permissions: perms, csrf_token: csrf })
    }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (d) {
        btn.disabled = false;
        if (d && d.ok) {
          if (d.csrf) { csrf = d.csrf; if (csrfMeta) csrfMeta.content = d.csrf; }
          setDirty(false);
          toast('Permissions saved');
        } else {
          toast('Could not save permissions' + (d && d.error ? ' (' + d.error + ')' : ''), true);
        }
      }).catch(function () { btn.disabled = false; toast('Network error saving permissions', true); });
  });

  // live user search
  var search = document.getElementById('iamUserSearch');
  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      document.querySelectorAll('#iamUserList .iam-user').forEach(function (a) {
        var name = a.getAttribute('data-name') || '';
        a.style.display = (q === '' || name.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  updateCounts();
})();
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
