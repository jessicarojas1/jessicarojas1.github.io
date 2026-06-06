<?php
$breadcrumbs  = [['Admin','/admin'],['Permissions',null]];
$pageTitle    = 'Permission Management';
$activeModule = 'admin_permissions';
ob_start();

// Build per-user permission data for JS injection
$permData = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    $role = $u['role'];
    $rolePerms = [];
    if (isset($roleDefaults[$role])) {
        foreach ($roleDefaults[$role] as $mod => $actions) {
            foreach ($actions as $act) {
                $rolePerms[] = $mod . '.' . $act;
            }
        }
    }
    $explicitPerms = [];
    if (isset($grants[$uid])) {
        foreach ($grants[$uid] as $mod => $acts) {
            foreach ($acts as $act => $bool) {
                $explicitPerms[] = $mod . '.' . $act;
            }
        }
    }
    $permData[$uid] = [
        'rolePerms'     => $rolePerms,
        'explicitPerms' => $explicitPerms,
    ];
}

$moduleIcons = [
    'risk'       => 'exclamation-diamond',
    'compliance' => 'shield-check',
    'audit'      => 'clipboard2-check',
    'policy'     => 'file-text',
    'incident'   => 'fire',
    'vendor'     => 'building',
    'issue'      => 'bug',
    'change'     => 'arrow-left-right',
    'threat'     => 'crosshair',
    'awareness'  => 'mortarboard',
    'asset'      => 'hdd-stack',
    'kri'        => 'bar-chart-line',
    'bcp'        => 'life-preserver',
    'ssp'        => 'lock',
    'report'     => 'file-earmark-bar-graph',
    'automation' => 'robot',
    'approval'   => 'check2-square',
];
?>

<style nonce="<?= Security::nonce() ?>">
.perm-layout {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 16px;
    min-height: 600px;
}
.perm-left-pane {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.perm-search-wrap {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    position: relative;
}
.perm-search-wrap .bi-search {
    position: absolute;
    left: 22px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
}
.perm-search-input {
    width: 100%;
    padding: 8px 10px 8px 32px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--card-bg);
    color: inherit;
    font-size: 13px;
    box-sizing: border-box;
}
.perm-search-input:focus {
    outline: none;
    border-color: var(--primary);
}
.perm-user-count {
    padding: 6px 12px;
    font-size: 12px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
}
.perm-user-list {
    flex: 1;
    overflow-y: auto;
}
.perm-user-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    cursor: pointer;
    border-bottom: 1px solid var(--border);
    transition: background 0.15s;
}
.perm-user-card:hover,
.perm-user-card.active {
    background: rgba(99,102,241,0.08);
}
.perm-user-card.active {
    border-left: 3px solid var(--primary);
}
.perm-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 15px;
    flex-shrink: 0;
}
.perm-user-info { flex: 1; min-width: 0; }
.perm-user-name { font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.perm-user-sub  { font-size: 11px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.perm-right-pane {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.perm-empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    gap: 12px;
    padding: 40px;
}
.perm-empty-state .bi { font-size: 48px; }

.perm-right-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.perm-right-header-info { flex: 1; min-width: 0; }
.perm-right-header-name { font-weight: 700; font-size: 16px; }
.perm-right-header-sub  { font-size: 12px; color: var(--text-muted); }
.perm-header-actions { display: flex; align-items: center; gap: 8px; }

.perm-info-banner {
    padding: 8px 20px;
    font-size: 12px;
    color: var(--text-muted);
    background: var(--card-bg);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.perm-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 4px;
}
.dot-role     { background: var(--success); }
.dot-explicit { background: var(--warning); }
.dot-none     { background: var(--border); }

.perm-toolbar {
    padding: 8px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}
.perm-total-count {
    margin-left: auto;
    font-size: 12px;
    color: var(--text-muted);
}

.perm-accordion-body {
    flex: 1;
    overflow-y: auto;
    padding: 12px 20px;
}
.perm-module-accordion {
    border: 1px solid var(--border);
    border-radius: 6px;
    margin-bottom: 8px;
    overflow: hidden;
}
.perm-module-header-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: var(--card-bg);
    cursor: pointer;
    user-select: none;
}
.perm-module-header-row:hover { background: rgba(99,102,241,0.06); }
.perm-module-label { font-weight: 600; font-size: 13px; flex: 1; }
.perm-module-count-badge {
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 10px;
    background: var(--primary);
    color: #fff;
    font-weight: 600;
}
.perm-module-actions { display: flex; gap: 4px; align-items: center; }
.perm-module-chevron { color: var(--text-muted); transition: transform 0.2s; }
.perm-module-accordion.open .perm-module-chevron { transform: rotate(180deg); }
.perm-module-body {
    display: none;
    padding: 10px 14px 14px;
    border-top: 1px solid var(--border);
    background: var(--card-bg);
}
.perm-module-accordion.open .perm-module-body { display: block; }
.perm-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px 10px;
}
.perm-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}
.perm-cb { cursor: pointer; }
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.status-role     { background: var(--success); }
.status-explicit { background: var(--warning); }
.status-none     { background: var(--border); }

.perm-save-bottom {
    padding: 14px 20px;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}
.dirty-indicator {
    font-size: 12px;
    color: var(--warning);
    font-weight: 600;
}

.perm-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    z-index: 9999;
    opacity: 0;
    transform: translateY(12px);
    transition: opacity 0.3s, transform 0.3s;
    pointer-events: none;
}
.perm-toast.show { opacity: 1; transform: translateY(0); }
.perm-toast.success { background: var(--success); color: #fff; }
.perm-toast.error   { background: var(--danger);  color: #fff; }

@media (max-width: 900px) {
    .perm-layout { grid-template-columns: 1fr; }
    .perm-left-pane { max-height: 280px; }
    .perm-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-header">
  <h1 class="page-title">Permission Management</h1>
  <span class="badge badge-blue" style="font-size:13px">Admin Only</span>
</div>

<div class="perm-layout">

  <!-- Left Pane: User List -->
  <div class="perm-left-pane">
    <div class="perm-search-wrap">
      <i class="bi bi-search"></i>
      <input type="text" id="permUserSearch" class="perm-search-input" placeholder="Search users...">
    </div>
    <div class="perm-user-count" id="permUserCount"><?= (int)count($users) ?> users</div>
    <div class="perm-user-list" id="permUserList">
      <?php if (!$users): ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px">
          <i class="bi bi-people" style="font-size:28px;display:block;margin-bottom:8px"></i>
          No non-admin users found.
        </div>
      <?php else: ?>
        <?php foreach ($users as $u): ?>
          <div class="perm-user-card"
               data-user-id="<?= (int)$u['id'] ?>"
               data-name="<?= Security::h(strtolower($u['name'])) ?>">
            <div class="perm-avatar"><?= Security::h(strtoupper(substr($u['name'], 0, 1))) ?></div>
            <div class="perm-user-info">
              <div class="perm-user-name"><?= Security::h($u['name']) ?></div>
              <div class="perm-user-sub"><?= Security::h($u['email']) ?><?= !empty($u['department']) ? ' · ' . Security::h($u['department']) : '' ?></div>
            </div>
            <span class="role-badge role-<?= Security::h($u['role']) ?>"><?= Security::h(ucfirst($u['role'])) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Right Pane -->
  <div class="perm-right-pane" id="permRightPane">
    <div class="perm-empty-state" id="permEmptyState">
      <i class="bi bi-lock"></i>
      <div style="font-size:15px;font-weight:600">Select a user to manage permissions</div>
      <div style="font-size:13px">Choose a user from the left panel to view and edit their module-level access.</div>
    </div>
    <div id="permUserDetail" style="display:none;flex-direction:column;flex:1;overflow:hidden">
      <!-- Header -->
      <div class="perm-right-header">
        <div class="perm-avatar" id="eDetailAvatar" style="width:44px;height:44px;font-size:18px"></div>
        <div class="perm-right-header-info">
          <div class="perm-right-header-name" id="eDetailName"></div>
          <div class="perm-right-header-sub" id="eDetailSub"></div>
        </div>
        <div class="perm-header-actions">
          <span id="eDirty" class="dirty-indicator" style="display:none"><i class="bi bi-pencil-fill"></i> Unsaved changes</span>
          <button class="btn btn-primary btn-sm" id="eSaveTop"><i class="bi bi-floppy"></i> Save Changes</button>
        </div>
      </div>
      <!-- Info Banner -->
      <div class="perm-info-banner">
        <span><span class="perm-dot dot-role"></span>Green dot = role default</span>
        <span><span class="perm-dot dot-explicit"></span>Orange dot = explicit grant</span>
        <span><span class="perm-dot dot-none"></span>Unchecked = denied</span>
      </div>
      <!-- Toolbar -->
      <div class="perm-toolbar">
        <button class="btn btn-sm btn-outline" id="eBtnExpandAll"><i class="bi bi-arrows-expand"></i> Expand All</button>
        <button class="btn btn-sm btn-outline" id="eBtnCollapseAll"><i class="bi bi-arrows-collapse"></i> Collapse All</button>
        <div class="perm-total-count">Total permissions: <strong id="eTotalCount">0</strong></div>
      </div>
      <!-- Accordion Body -->
      <div class="perm-accordion-body" id="eAccordionBody"></div>
      <!-- Bottom Save -->
      <div class="perm-save-bottom">
        <button class="btn btn-primary" id="eSaveBottom"><i class="bi bi-floppy"></i> Save Changes</button>
        <span id="eDirty2" class="dirty-indicator" style="display:none"><i class="bi bi-pencil-fill"></i> Unsaved changes</span>
      </div>
    </div>
  </div>

</div>

<!-- Toast -->
<div class="perm-toast" id="permToast"></div>

<script nonce="<?= Security::nonce() ?>">
var PERM_DATA   = <?= json_encode($permData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var MODULES     = <?= json_encode($modules, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var USERS_DATA  = <?= json_encode(array_column($users, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var csrfToken   = <?= json_encode(Security::generateCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var MODULE_ICONS = <?= json_encode($moduleIcons, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

var currentUid = null;

// ── User card click ──────────────────────────────────────────────────────────
document.getElementById('permUserList').addEventListener('click', function(e) {
    var card = e.target.closest('.perm-user-card');
    if (!card) return;
    var uid = parseInt(card.dataset.userId, 10);
    document.querySelectorAll('.perm-user-card').forEach(function(c) { c.classList.remove('active'); });
    card.classList.add('active');
    selectUser(uid);
});

function selectUser(uid) {
    currentUid = uid;
    var user = USERS_DATA[uid];
    if (!user) return;

    var pd = PERM_DATA[uid] || { rolePerms: [], explicitPerms: [] };

    // Show detail pane
    document.getElementById('permEmptyState').style.display = 'none';
    var detail = document.getElementById('permUserDetail');
    detail.style.display = 'flex';

    // Populate header
    document.getElementById('eDetailAvatar').textContent = (user.name || '?')[0].toUpperCase();
    document.getElementById('eDetailName').textContent = user.name || '';
    var sub = user.email || '';
    if (user.department) sub += ' · ' + user.department;
    document.getElementById('eDetailSub').textContent = sub;

    // Role badge next to name
    var existingBadge = document.getElementById('eDetailRoleBadge');
    if (existingBadge) existingBadge.remove();
    var badge = document.createElement('span');
    badge.id = 'eDetailRoleBadge';
    badge.className = 'role-badge role-' + (user.role || 'viewer');
    badge.textContent = (user.role || 'viewer').charAt(0).toUpperCase() + (user.role || 'viewer').slice(1);
    var nameEl = document.getElementById('eDetailName');
    nameEl.parentNode.insertBefore(badge, nameEl.nextSibling);

    // Build accordion
    buildAccordion(pd);
    hideDirty();
}

function buildAccordion(pd) {
    var container = document.getElementById('eAccordionBody');
    container.innerHTML = '';

    Object.keys(MODULES).forEach(function(mod) {
        var actions = MODULES[mod];
        var icon = MODULE_ICONS[mod] || 'grid';

        var accordion = document.createElement('div');
        accordion.className = 'perm-module-accordion open';
        accordion.dataset.mod = mod;

        // Count checked
        var checkedCount = 0;
        actions.forEach(function(act) {
            var key = mod + '.' + act;
            if (pd.explicitPerms.indexOf(key) !== -1 || pd.rolePerms.indexOf(key) !== -1) checkedCount++;
        });

        var headerRow = document.createElement('div');
        headerRow.className = 'perm-module-header-row';
        headerRow.innerHTML =
            '<i class="bi bi-' + escHtml(icon) + '"></i>' +
            '<span class="perm-module-label">' + escHtml(mod.charAt(0).toUpperCase() + mod.slice(1)) + '</span>' +
            '<span class="perm-module-count-badge" data-mod-count="' + escHtml(mod) + '">' + checkedCount + '/' + actions.length + '</span>' +
            '<div class="perm-module-actions">' +
                '<button class="btn btn-xs btn-outline" data-grant-all="' + escHtml(mod) + '">Grant All</button>' +
                '<button class="btn btn-xs btn-outline" data-clear-all="' + escHtml(mod) + '">Clear All</button>' +
            '</div>' +
            '<i class="bi bi-chevron-down perm-module-chevron"></i>';

        var bodyDiv = document.createElement('div');
        bodyDiv.className = 'perm-module-body';

        var grid = document.createElement('div');
        grid.className = 'perm-grid';
        grid.id = 'perm-grid-' + mod;

        bodyDiv.appendChild(grid);
        accordion.appendChild(headerRow);
        accordion.appendChild(bodyDiv);
        container.appendChild(accordion);

        actions.forEach(function(act) {
            var key = mod + '.' + act;
            var isRole     = pd.rolePerms.indexOf(key) !== -1;
            var isExplicit = pd.explicitPerms.indexOf(key) !== -1;
            var isChecked  = isRole || isExplicit;
            var dotClass   = isRole ? 'status-role' : (isExplicit ? 'status-explicit' : 'status-none');

            var item = document.createElement('label');
            item.className = 'perm-item';

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'perm-cb';
            cb.dataset.mod  = mod;
            cb.dataset.perm = act;
            cb.checked = isChecked;

            var dot = document.createElement('span');
            dot.className = 'status-dot ' + dotClass;
            dot.dataset.dot = key;

            var label = document.createElement('span');
            label.textContent = act;

            item.appendChild(cb);
            item.appendChild(dot);
            item.appendChild(label);
            grid.appendChild(item);
        });
    });

    updateTotalCount();
}

// ── Accordion events ─────────────────────────────────────────────────────────
document.getElementById('eAccordionBody').addEventListener('click', function(e) {
    // Grant All
    var grantBtn = e.target.closest('[data-grant-all]');
    if (grantBtn) {
        e.stopPropagation();
        var mod = grantBtn.dataset.grantAll;
        document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]').forEach(function(cb) { cb.checked = true; });
        updateModuleBadge(mod);
        markDirty();
        return;
    }

    // Clear All
    var clearBtn = e.target.closest('[data-clear-all]');
    if (clearBtn) {
        e.stopPropagation();
        var mod = clearBtn.dataset.clearAll;
        document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]').forEach(function(cb) { cb.checked = false; });
        updateModuleBadge(mod);
        markDirty();
        return;
    }

    // Module header toggle (click on header row itself, not buttons inside)
    var headerRow = e.target.closest('.perm-module-header-row');
    if (headerRow) {
        var accordion = headerRow.closest('.perm-module-accordion');
        if (accordion) accordion.classList.toggle('open');
    }
});

// ── Checkbox change ───────────────────────────────────────────────────────────
document.getElementById('eAccordionBody').addEventListener('change', function(e) {
    if (!e.target.classList.contains('perm-cb')) return;
    var mod  = e.target.dataset.mod;
    var perm = e.target.dataset.perm;
    var key  = mod + '.' + perm;

    var dot = document.querySelector('[data-dot="' + key + '"]');
    if (dot) {
        dot.className = 'status-dot ' + (e.target.checked ? 'status-explicit' : 'status-none');
    }

    updateModuleBadge(mod);
    markDirty();
});

// ── Expand / Collapse All ────────────────────────────────────────────────────
document.getElementById('eBtnExpandAll').addEventListener('click', function() {
    document.querySelectorAll('.perm-module-accordion').forEach(function(a) { a.classList.add('open'); });
});
document.getElementById('eBtnCollapseAll').addEventListener('click', function() {
    document.querySelectorAll('.perm-module-accordion').forEach(function(a) { a.classList.remove('open'); });
});

// ── Save buttons ─────────────────────────────────────────────────────────────
document.getElementById('eSaveTop').addEventListener('click', doSave);
document.getElementById('eSaveBottom').addEventListener('click', doSave);

function doSave() {
    if (!currentUid) return;

    var perms = [];
    document.querySelectorAll('.perm-cb:checked').forEach(function(cb) {
        perms.push(cb.dataset.mod + '.' + cb.dataset.perm);
    });

    var body = new URLSearchParams();
    body.append('csrf_token', csrfToken);
    perms.forEach(function(p) { body.append('permissions[]', p); });

    fetch('/admin/permissions/' + currentUid + '/update', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            if (data.csrf) csrfToken = data.csrf;
            hideDirty();
            showToast('Permissions saved successfully.', 'success');
            if (PERM_DATA[currentUid]) {
                PERM_DATA[currentUid].explicitPerms = perms.slice();
            }
        } else {
            showToast(data.message || 'Failed to save permissions.', 'error');
        }
    })
    .catch(function() {
        showToast('Network error. Please try again.', 'error');
    });
}

// ── Dirty state ───────────────────────────────────────────────────────────────
function markDirty() {
    document.getElementById('eDirty').style.display  = 'inline';
    document.getElementById('eDirty2').style.display = 'inline';
    updateTotalCount();
}
function hideDirty() {
    document.getElementById('eDirty').style.display  = 'none';
    document.getElementById('eDirty2').style.display = 'none';
    updateTotalCount();
}

// ── Counts ────────────────────────────────────────────────────────────────────
function updateTotalCount() {
    var n = document.querySelectorAll('.perm-cb:checked').length;
    document.getElementById('eTotalCount').textContent = n;
}
function updateModuleBadge(mod) {
    var total   = document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]').length;
    var checked = document.querySelectorAll('.perm-cb[data-mod="' + mod + '"]:checked').length;
    var badge   = document.querySelector('[data-mod-count="' + mod + '"]');
    if (badge) badge.textContent = checked + '/' + total;
    updateTotalCount();
}

// ── User search ───────────────────────────────────────────────────────────────
document.getElementById('permUserSearch').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    var cards = document.querySelectorAll('.perm-user-card');
    var visible = 0;
    cards.forEach(function(card) {
        var name = (card.dataset.name || '').toLowerCase();
        var show = !q || name.indexOf(q) !== -1;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('permUserCount').textContent = visible + ' user' + (visible !== 1 ? 's' : '');
});

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    var t = document.getElementById('permToast');
    t.textContent = msg;
    t.className = 'perm-toast ' + type;
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 3500);
}

// ── Escape helper ─────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
