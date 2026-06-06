<?php
$pageTitle    = 'Permissions';
$activeModule = 'admin_permissions';
$breadcrumbs  = [['Admin','/admin'],['Permissions',null]];
ob_start();

// ── Module & permission metadata ────────────────────────────────────────────
$permDescriptions = [
    'view'          => 'Read-only access to list and detail pages',
    'create'        => 'Create new records',
    'edit'          => 'Modify existing records',
    'delete'        => 'Permanently remove records',
    'accept'        => 'Manage risk acceptances and exceptions',
    'review'        => 'Conduct periodic risk reviews',
    'treatment'     => 'Create and manage treatment plans',
    'scenarios'     => 'Access risk scenario analysis',
    'bowtie'        => 'Access bowtie analysis visualizations',
    'export'        => 'Export data to CSV/PDF',
    'assess'        => 'Assess controls and record evidence',
    'import'        => 'Import data from files/frameworks',
    'test'          => 'Run control testing workflows',
    'gap'           => 'Perform gap analysis',
    'findings'      => 'Add and manage audit findings',
    'close'         => 'Close/complete items',
    'publish'       => 'Publish or archive policies',
    'attest'        => 'Manage attestation campaigns',
    'playbook'      => 'Manage incident response playbooks',
    'questionnaire' => 'Manage vendor questionnaires',
    'contracts'     => 'View and manage vendor contracts',
    'approve'       => 'Approve or reject pending items',
    'exercise'      => 'Manage DR/BCP exercises',
    'manage'        => 'Full management of this module',
    'record'        => 'Record metric values',
];

$moduleMeta = [
    'risk'        => ['icon' => 'exclamation-diamond-fill',    'color' => 'var(--danger)',  'label' => 'Risk Management'],
    'compliance'  => ['icon' => 'shield-check-fill',           'color' => 'var(--success)', 'label' => 'Compliance'],
    'audit'       => ['icon' => 'clipboard2-check-fill',       'color' => 'var(--purple)',  'label' => 'Audit'],
    'policy'      => ['icon' => 'file-earmark-text-fill',      'color' => 'var(--info)',    'label' => 'Policies'],
    'incident'    => ['icon' => 'fire',                        'color' => 'var(--danger)',  'label' => 'Incidents'],
    'vendor'      => ['icon' => 'building-fill',               'color' => 'var(--orange)',  'label' => 'Vendors'],
    'issue'       => ['icon' => 'bug-fill',                    'color' => 'var(--warning)', 'label' => 'Issues'],
    'asset'       => ['icon' => 'hdd-stack-fill',              'color' => 'var(--indigo)',  'label' => 'Assets'],
    'change'      => ['icon' => 'arrow-repeat',                'color' => 'var(--info)',    'label' => 'Change Management'],
    'bcp'         => ['icon' => 'life-preserver',              'color' => 'var(--danger)',  'label' => 'BCP / DRP'],
    'threat'      => ['icon' => 'radioactive',                 'color' => 'var(--danger)',  'label' => 'Threat Intelligence'],
    'awareness'   => ['icon' => 'mortarboard-fill',            'color' => 'var(--purple)',  'label' => 'Awareness &amp; Training'],
    'report'      => ['icon' => 'bar-chart-fill',              'color' => 'var(--indigo)',  'label' => 'Reports'],
    'kri'         => ['icon' => 'speedometer2',                'color' => 'var(--orange)',  'label' => 'Key Risk Indicators'],
    'ssp'         => ['icon' => 'file-earmark-lock-fill',      'color' => 'var(--success)', 'label' => 'System Security Plans'],
    'automation'  => ['icon' => 'diagram-3-fill',              'color' => 'var(--purple)',  'label' => 'Workflow Automation'],
    'approval'    => ['icon' => 'check-circle-fill',           'color' => 'var(--success)', 'label' => 'Approvals'],
];

// ── Build JS data payload ────────────────────────────────────────────────────
$jsPermData = [];
foreach ($users as $u) {
    $uid        = (int)$u['id'];
    $userGrants = $grants[$uid] ?? [];
    $roleDef    = $roleDefaults[$u['role']] ?? [];

    $rolePerms = [];
    foreach ($roleDef as $mod => $perms) {
        foreach ($perms as $p) {
            $rolePerms[] = $mod . '.' . $p;
        }
    }
    $explicitPerms = [];
    foreach ($userGrants as $mod => $perms) {
        foreach ($perms as $p => $v) {
            if ($v) $explicitPerms[] = $mod . '.' . $p;
        }
    }

    $jsPermData[$uid] = [
        'id'           => $uid,
        'name'         => $u['name'],
        'role'         => $u['role'],
        'department'   => $u['department'] ?? '',
        'rolePerms'    => $rolePerms,
        'explicitPerms'=> $explicitPerms,
    ];
}

$jsModules = [];
foreach ($modules as $mod => $perms) {
    $meta = $moduleMeta[$mod] ?? ['icon' => 'grid-fill', 'color' => 'var(--neutral)', 'label' => ucfirst($mod)];
    $jsModules[$mod] = [
        'label' => $meta['label'],
        'icon'  => $meta['icon'],
        'color' => $meta['color'],
        'perms' => $perms,
    ];
}

$jsPermDescriptions = $permDescriptions;
?>

<style nonce="<?= Security::nonce() ?>">
/* ── Two-pane layout ─────────────────────────────────────────────────── */
.perm-layout {
  display: grid;
  grid-template-columns: 300px 1fr;
  gap: 0;
  height: calc(100vh - 64px - 28px - 60px);
  min-height: 520px;
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  box-shadow: var(--shadow);
}

/* ── Left pane ───────────────────────────────────────────────────────── */
.perm-user-pane {
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--bg-secondary);
}
html[data-theme="dark"] .perm-user-pane { background: #161b22; }

.perm-user-search {
  padding: 12px 14px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.perm-search-wrap { position: relative; }
.perm-search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-muted);
  font-size: 13px;
  pointer-events: none;
}
.perm-search-input {
  width: 100%;
  padding: 8px 12px 8px 33px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: var(--card-bg);
  color: var(--text);
  font-size: 13px;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
  font-family: inherit;
}
.perm-search-input:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-ring);
}
html[data-theme="dark"] .perm-search-input {
  background: #0d1117;
  border-color: #30363d;
  color: #e6edf3;
}

.perm-user-count-bar {
  padding: 6px 14px 5px;
  font-size: 11px;
  color: var(--text-muted);
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
  letter-spacing: .3px;
  font-weight: 500;
}

.perm-user-list {
  flex: 1;
  overflow-y: auto;
  padding: 8px;
}
.perm-user-list::-webkit-scrollbar { width: 5px; }
.perm-user-list::-webkit-scrollbar-track { background: transparent; }
.perm-user-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

.perm-user-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.13s, border-color 0.13s, box-shadow 0.13s;
  border: 1.5px solid transparent;
  margin-bottom: 3px;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
}
.perm-user-card:hover {
  background: var(--card-bg);
  border-color: var(--border-light);
  box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
html[data-theme="dark"] .perm-user-card:hover { background: #1c2128; border-color: #30363d; }
.perm-user-card.active {
  background: var(--card-bg);
  border-color: var(--primary);
  box-shadow: 0 0 0 2px var(--primary-ring);
}
html[data-theme="dark"] .perm-user-card.active { background: #1c2128; }

.pu-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  font-weight: 700;
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  text-transform: uppercase;
}
.pu-info { flex: 1; min-width: 0; }
.pu-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}
.pu-dept {
  font-size: 11px;
  color: var(--text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 1px;
}
.pu-role { flex-shrink: 0; }

/* ── Right pane ──────────────────────────────────────────────────────── */
.perm-editor-pane {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 0;
}

.perm-editor-header {
  padding: 12px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
  background: var(--card-bg);
  min-height: 62px;
}
html[data-theme="dark"] .perm-editor-header { background: #161b22; border-color: #30363d; }

.perm-editor-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  color: white;
  font-weight: 700;
  font-size: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  text-transform: uppercase;
}
.perm-editor-info { flex: 1; min-width: 0; }
.perm-editor-name {
  font-size: 15px;
  font-weight: 700;
  color: var(--text);
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  line-height: 1.3;
}
.perm-editor-dept { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

.perm-editor-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.perm-dirty {
  font-size: 11px;
  color: var(--warning);
  font-weight: 600;
  display: none;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
}
.perm-dirty.visible { display: flex; }

.perm-toolbar {
  padding: 7px 20px;
  border-bottom: 1px solid var(--border-light);
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
  background: var(--bg-secondary);
  font-size: 12px;
  color: var(--text-muted);
}
html[data-theme="dark"] .perm-toolbar { background: #0d1117; border-color: #21262d; }
.perm-toolbar-link {
  color: var(--primary);
  text-decoration: none;
  font-weight: 500;
  cursor: pointer;
  background: none;
  border: none;
  padding: 0;
  font-size: 12px;
  font-family: inherit;
}
.perm-toolbar-link:hover { text-decoration: underline; }

.perm-editor-scroll {
  flex: 1;
  overflow-y: auto;
  padding: 16px 20px 0;
}
.perm-editor-scroll::-webkit-scrollbar { width: 5px; }
.perm-editor-scroll::-webkit-scrollbar-track { background: transparent; }
.perm-editor-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

/* ── Empty state ─────────────────────────────────────────────────────── */
.perm-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  text-align: center;
  padding: 48px 32px;
  gap: 10px;
}
.perm-empty i {
  font-size: 52px;
  color: var(--text-light);
  opacity: .4;
  display: block;
  margin-bottom: 6px;
}
.perm-empty h3 { font-size: 16px; font-weight: 700; margin: 0; color: var(--text); }
.perm-empty p  { font-size: 13px; color: var(--text-muted); margin: 0; max-width: 260px; line-height: 1.5; }

/* ── Role info banner ────────────────────────────────────────────────── */
.perm-banner {
  background: var(--info-subtle);
  border: 1px solid var(--info-border);
  border-radius: var(--radius-sm);
  padding: 9px 13px;
  font-size: 12px;
  color: var(--info-text);
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 14px;
  line-height: 1.5;
}
.perm-banner i { flex-shrink: 0; margin-top: 1px; }
html[data-theme="dark"] .perm-banner { background: #1e3a5f; border-color: #1d4ed8; color: #93c5fd; }

/* ── Module accordion ────────────────────────────────────────────────── */
.perm-module {
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  margin-bottom: 7px;
  overflow: hidden;
  transition: box-shadow 0.15s;
}
.perm-module:hover { box-shadow: 0 1px 6px rgba(0,0,0,.06); }
html[data-theme="dark"] .perm-module { border-color: #30363d; }

.perm-mod-header {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 9px 12px;
  background: var(--card-bg);
  cursor: pointer;
  user-select: none;
  transition: background 0.12s;
  border: none;
  width: 100%;
  text-align: left;
  -webkit-tap-highlight-color: transparent;
}
.perm-mod-header:hover { background: var(--bg-subtle); }
html[data-theme="dark"] .perm-mod-header { background: #161b22; }
html[data-theme="dark"] .perm-mod-header:hover { background: #1c2128; }

.perm-mod-icon {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  flex-shrink: 0;
  color: white;
}

.perm-mod-label {
  flex: 1;
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.perm-mod-count {
  font-size: 10px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 10px;
  background: var(--bg-subtle);
  color: var(--text-muted);
  white-space: nowrap;
  flex-shrink: 0;
  transition: background 0.15s, color 0.15s;
  letter-spacing: .2px;
}
.perm-mod-count.has-grants { background: #d1fae5; color: #065f46; }
html[data-theme="dark"] .perm-mod-count.has-grants { background: #064e3b; color: #6ee7b7; }

.perm-mod-batch {
  display: flex;
  gap: 4px;
  flex-shrink: 0;
}
.perm-batch-btn {
  font-size: 10px;
  padding: 2px 7px;
  border-radius: 4px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  font-weight: 600;
  transition: background 0.12s, color 0.12s, border-color 0.12s;
  white-space: nowrap;
  font-family: inherit;
  line-height: 1.6;
}
.perm-batch-btn:hover { background: var(--bg-secondary); color: var(--text); }
.perm-batch-btn.grant:hover { background: #d1fae5; color: #065f46; border-color: #86efac; }
.perm-batch-btn.clear:hover { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
html[data-theme="dark"] .perm-batch-btn.grant:hover { background: #064e3b; color: #6ee7b7; border-color: #166534; }
html[data-theme="dark"] .perm-batch-btn.clear:hover { background: #7f1d1d; color: #fca5a5; border-color: #991b1b; }

.perm-mod-chevron {
  font-size: 11px;
  color: var(--text-muted);
  flex-shrink: 0;
  transition: transform 0.2s ease;
}
.perm-module.open .perm-mod-chevron { transform: rotate(180deg); }

.perm-mod-body {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.28s ease;
  background: var(--card-bg);
  border-top: 1px solid transparent;
}
.perm-module.open .perm-mod-body {
  max-height: 700px;
  border-top-color: var(--border-light);
}
html[data-theme="dark"] .perm-mod-body { background: #0d1117; }
html[data-theme="dark"] .perm-module.open .perm-mod-body { border-top-color: #21262d; }

.perm-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  padding: 10px 12px;
}

/* ── Checkbox items ──────────────────────────────────────────────────── */
.perm-item {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 6px 9px;
  border-radius: 6px;
  border: 1px solid var(--border-light);
  background: var(--bg-secondary);
  cursor: pointer;
  transition: border-color 0.13s, background 0.13s;
  user-select: none;
}
.perm-item:hover { border-color: var(--border); background: var(--card-bg); }
html[data-theme="dark"] .perm-item { background: #161b22; border-color: #21262d; }
html[data-theme="dark"] .perm-item:hover { background: #1c2128; border-color: #30363d; }

.perm-item.s-role     { background: #f0fdf4; border-color: #86efac; }
.perm-item.s-explicit { background: #fffbeb; border-color: #fcd34d; }
html[data-theme="dark"] .perm-item.s-role     { background: #052e16; border-color: #166534; }
html[data-theme="dark"] .perm-item.s-explicit { background: #451a03; border-color: #92400e; }

.perm-item-cb {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
  cursor: pointer;
  accent-color: var(--primary);
}

.perm-item-text { flex: 1; min-width: 0; }
.perm-item-name {
  font-size: 12px;
  font-weight: 600;
  color: var(--text);
  text-transform: capitalize;
  display: block;
  line-height: 1.3;
}
.perm-item-desc {
  font-size: 10px;
  color: var(--text-muted);
  display: block;
  line-height: 1.3;
  margin-top: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.perm-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
  background: var(--border-light);
}
.perm-item.s-role     .perm-dot { background: var(--success); }
.perm-item.s-explicit .perm-dot { background: var(--warning); }

/* ── Save bar ────────────────────────────────────────────────────────── */
.perm-save-bar {
  padding: 10px 20px;
  border-top: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
  background: var(--card-bg);
  margin-top: 16px;
}
html[data-theme="dark"] .perm-save-bar { background: #161b22; border-color: #30363d; }

/* ── Toasts ──────────────────────────────────────────────────────────── */
.perm-toasts {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: 10px;
  pointer-events: none;
}
.perm-toast {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  box-shadow: var(--shadow-lg);
  pointer-events: auto;
  animation: pgToastIn 0.25s ease forwards;
  min-width: 220px;
  max-width: 340px;
}
.perm-toast.ok  { background:#d1fae5; color:#065f46; border:1px solid #86efac; }
.perm-toast.err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.perm-toast.out { animation: pgToastOut 0.25s ease forwards; }
html[data-theme="dark"] .perm-toast.ok  { background:#064e3b; color:#6ee7b7; border-color:#166534; }
html[data-theme="dark"] .perm-toast.err { background:#7f1d1d; color:#fca5a5; border-color:#991b1b; }
@keyframes pgToastIn  { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
@keyframes pgToastOut { from{opacity:1;transform:translateY(0)}    to{opacity:0;transform:translateY(8px)} }

/* ── Responsive ──────────────────────────────────────────────────────── */
@media (max-width: 768px) {
  .perm-layout { grid-template-columns: 1fr; height: auto; }
  .perm-user-pane { height: 230px; border-right: none; border-bottom: 1px solid var(--border); }
  .perm-editor-pane { height: 600px; }
  .perm-grid { grid-template-columns: repeat(2,1fr); }
}
@media (max-width: 480px) {
  .perm-grid { grid-template-columns: 1fr; }
}
</style>

<!-- ── Page header ────────────────────────────────────────────────────────── -->
<div class="page-header">
  <div>
    <h1 class="page-title">Permission Management</h1>
    <p class="page-subtitle">Manage fine-grained module access for each user</p>
  </div>
  <div class="page-actions">
    <span class="badge badge-red" style="font-size:12px;padding:5px 12px">
      <i class="bi bi-shield-fill-exclamation"></i> Admin Only
    </span>
  </div>
</div>

<?php if (!$users): ?>
  <div class="card">
    <div class="card-body">
      <div class="empty-state-sm">
        <i class="bi bi-people"></i>
        <p>No non-admin users found. Create users first before managing permissions.</p>
      </div>
    </div>
  </div>
<?php else: ?>

<!-- ── Legend ─────────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-bottom:12px;font-size:12px;color:var(--text-muted)">
  <span style="display:flex;align-items:center;gap:6px">
    <span style="width:9px;height:9px;border-radius:50%;background:var(--success);display:inline-block;flex-shrink:0"></span>
    Role default
  </span>
  <span style="display:flex;align-items:center;gap:6px">
    <span style="width:9px;height:9px;border-radius:50%;background:var(--warning);display:inline-block;flex-shrink:0"></span>
    Explicit override / addition
  </span>
  <span style="display:flex;align-items:center;gap:6px">
    <span style="width:9px;height:9px;border-radius:50%;background:var(--border-light);border:1px solid var(--border);display:inline-block;flex-shrink:0"></span>
    Not granted
  </span>
  <span style="margin-left:auto;font-size:11px">
    <i class="bi bi-info-circle"></i> Admin users always have full access and are excluded from this list.
  </span>
</div>

<!-- ── Two-pane layout ────────────────────────────────────────────────────── -->
<div class="perm-layout">

  <!-- LEFT: User list -->
  <div class="perm-user-pane">
    <div class="perm-user-search">
      <div class="perm-search-wrap">
        <i class="bi bi-search perm-search-icon"></i>
        <input type="text" id="permUserSearch" class="perm-search-input" placeholder="Search users…" autocomplete="off">
      </div>
    </div>
    <div class="perm-user-count-bar" id="permUserCount"><?= count($users) ?> users</div>
    <div class="perm-user-list" id="permUserList">
      <?php foreach ($users as $u): ?>
        <div class="perm-user-card"
             data-user-id="<?= (int)$u['id'] ?>"
             data-name="<?= strtolower(Security::h($u['name'])) ?>">
          <div class="pu-avatar"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
          <div class="pu-info">
            <div class="pu-name"><?= Security::h($u['name']) ?></div>
            <div class="pu-dept"><?= Security::h($u['department'] ?? ($u['email'] ?? '')) ?></div>
          </div>
          <div class="pu-role">
            <span class="role-badge role-<?= Security::h($u['role']) ?>"><?= Security::h(ucfirst($u['role'])) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RIGHT: Editor -->
  <div class="perm-editor-pane" id="permEditorPane">

    <!-- Empty state -->
    <div class="perm-empty" id="permEmpty">
      <i class="bi bi-person-lock"></i>
      <h3>No User Selected</h3>
      <p>Choose a user from the list on the left to manage their module permissions.</p>
    </div>

    <!-- Loaded editor (hidden until user selected) -->
    <div id="permEditor" style="display:none;flex-direction:column;flex:1;overflow:hidden;height:100%">

      <!-- Header row -->
      <div class="perm-editor-header">
        <div class="perm-editor-avatar" id="eAvatar"></div>
        <div class="perm-editor-info">
          <div class="perm-editor-name">
            <span id="eName"></span>
            <span id="eRoleBadge" class="role-badge"></span>
          </div>
          <div class="perm-editor-dept" id="eDept"></div>
        </div>
        <div class="perm-editor-actions">
          <span class="perm-dirty" id="eDirtyTop">
            <i class="bi bi-dot" style="font-size:18px;line-height:1"></i> Unsaved changes
          </span>
          <button type="button" class="btn btn-primary btn-sm" id="eSaveTop">
            <i class="bi bi-floppy-fill"></i> Save Changes
          </button>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="perm-toolbar">
        <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Modules</span>
        <button type="button" class="perm-toolbar-link" id="eBtnExpandAll">Expand All</button>
        <button type="button" class="perm-toolbar-link" id="eBtnCollapseAll">Collapse All</button>
        <span style="margin-left:auto;font-size:11px" id="eTotalCount"></span>
      </div>

      <!-- Scrollable body -->
      <div class="perm-editor-scroll" id="eScroll">

        <!-- Role info banner -->
        <div class="perm-banner">
          <i class="bi bi-info-circle-fill"></i>
          <span id="eBannerText"></span>
        </div>

        <!-- Accordion (JS-rendered) -->
        <div id="eAccordion"></div>

        <!-- Bottom save bar -->
        <div class="perm-save-bar">
          <button type="button" class="btn btn-primary btn-sm" id="eSaveBottom">
            <i class="bi bi-floppy-fill"></i> Save Changes
          </button>
          <span class="perm-dirty" id="eDirtyBottom">
            <i class="bi bi-dot" style="font-size:18px;line-height:1"></i> Unsaved changes
          </span>
        </div>

      </div><!-- /eScroll -->
    </div><!-- /permEditor -->
  </div><!-- /perm-editor-pane -->
</div><!-- /perm-layout -->

<!-- Hidden CSRF form -->
<form id="permSaveForm" method="POST" action="" style="display:none">
  <input type="hidden" name="csrf_token" id="permCsrf" value="">
</form>

<?php endif; ?>

<!-- Toast dock -->
<div class="perm-toasts" id="permToasts"></div>

<!-- ── PHP-rendered data ───────────────────────────────────────────────────── -->
<script nonce="<?= Security::nonce() ?>">
var PERM_DATA  = <?= json_encode($jsPermData,         JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var MODULES    = <?= json_encode($jsModules,           JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var PERM_DESCS = <?= json_encode($jsPermDescriptions,  JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var csrfToken  = <?= json_encode(Security::generateCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<!-- ── IAM Permission Manager logic ──────────────────────────────────────── -->
<script nonce="<?= Security::nonce() ?>">
(function () {
'use strict';

// ── state ──────────────────────────────────────────────────────────────────
var activeUid = null;
var dirty     = false;
var cbState   = {};  // 'mod.perm' -> bool

// ── refs ───────────────────────────────────────────────────────────────────
var elEmpty        = document.getElementById('permEmpty');
var elEditor       = document.getElementById('permEditor');
var elAccordion    = document.getElementById('eAccordion');
var elSearch       = document.getElementById('permUserSearch');
var elUserCount    = document.getElementById('permUserCount');
var elCsrf         = document.getElementById('permCsrf');
var elSaveForm     = document.getElementById('permSaveForm');
var elToasts       = document.getElementById('permToasts');
var elBannerText   = document.getElementById('eBannerText');
var elTotalCount   = document.getElementById('eTotalCount');
var elAvatar       = document.getElementById('eAvatar');
var elName         = document.getElementById('eName');
var elRoleBadge    = document.getElementById('eRoleBadge');
var elDept         = document.getElementById('eDept');

function $id(id) { return document.getElementById(id); }

// ── escape for HTML output ─────────────────────────────────────────────────
function esc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── toast ──────────────────────────────────────────────────────────────────
function toast(msg, type) {
  var el = document.createElement('div');
  el.className = 'perm-toast ' + (type === 'err' ? 'err' : 'ok');
  el.innerHTML = '<i class="bi bi-' + (type === 'err' ? 'x-circle-fill' : 'check-circle-fill') + '"></i> ' + esc(msg);
  elToasts.appendChild(el);
  setTimeout(function() {
    el.classList.add('out');
    setTimeout(function(){ el.remove(); }, 260);
  }, 3600);
}

// ── dirty tracking ─────────────────────────────────────────────────────────
function setDirty(val) {
  dirty = val;
  ['eDirtyTop','eDirtyBottom'].forEach(function(id) {
    var el = $id(id);
    if (el) { if (val) el.classList.add('visible'); else el.classList.remove('visible'); }
  });
}

// ── count updates ──────────────────────────────────────────────────────────
function refreshModCount(mod) {
  var perms   = MODULES[mod] ? MODULES[mod].perms : [];
  var granted = 0;
  perms.forEach(function(p){ if (cbState[mod+'.'+p]) granted++; });
  var el = elAccordion.querySelector('[data-mod-count="'+mod+'"]');
  if (el) {
    el.textContent = granted + ' / ' + perms.length;
    if (granted > 0) el.classList.add('has-grants'); else el.classList.remove('has-grants');
  }
}

function refreshTotal() {
  var n = 0;
  Object.keys(cbState).forEach(function(k){ if (cbState[k]) n++; });
  if (elTotalCount) elTotalCount.textContent = n + ' permission' + (n!==1?'s':'') + ' granted';
}

// ── on checkbox change ─────────────────────────────────────────────────────
function onCbChange(cb) {
  var key = cb.dataset.key;
  var mod = cb.dataset.mod;
  cbState[key] = cb.checked;
  setDirty(true);

  var item = cb.closest('.perm-item');
  if (item) {
    item.classList.remove('s-role','s-explicit');
    if (cb.checked) item.classList.add('s-explicit');
  }

  refreshModCount(mod);
  refreshTotal();
}

// ── build accordion for a user ─────────────────────────────────────────────
function buildAccordion(uid) {
  var data    = PERM_DATA[uid];
  var roleMap = {}, explMap = {};
  data.rolePerms.forEach(function(k){    roleMap[k] = true; });
  data.explicitPerms.forEach(function(k){ explMap[k] = true; });

  cbState = {};
  Object.keys(MODULES).forEach(function(mod) {
    MODULES[mod].perms.forEach(function(p) {
      var key = mod+'.'+p;
      cbState[key] = !!(roleMap[key] || explMap[key]);
    });
  });

  var html = '';
  var idx  = 0;
  Object.keys(MODULES).forEach(function(mod) {
    var m      = MODULES[mod];
    var perms  = m.perms;
    var open   = idx < 3;
    var granted = perms.filter(function(p){ return cbState[mod+'.'+p]; }).length;

    html += '<div class="perm-module'+(open?' open':'')+'" data-module="'+esc(mod)+'">';

    // header
    html += '<button type="button" class="perm-mod-header" data-mod="'+esc(mod)+'">';
    html +=   '<span class="perm-mod-icon" style="background:'+esc(m.color)+'"><i class="bi bi-'+esc(m.icon)+'"></i></span>';
    html +=   '<span class="perm-mod-label">'+esc(m.label)+'</span>';
    html +=   '<span class="perm-mod-count'+(granted>0?' has-grants':'')+'" data-mod-count="'+esc(mod)+'">'+granted+' / '+perms.length+'</span>';
    html +=   '<span class="perm-mod-batch">';
    html +=     '<button type="button" class="perm-batch-btn grant" data-grant="'+esc(mod)+'" title="Grant all '+esc(m.label)+' permissions">Grant All</button>';
    html +=     '<button type="button" class="perm-batch-btn clear" data-clear="'+esc(mod)+'" title="Clear all '+esc(m.label)+' permissions">Clear All</button>';
    html +=   '</span>';
    html +=   '<i class="bi bi-chevron-down perm-mod-chevron"></i>';
    html += '</button>';

    // body
    html += '<div class="perm-mod-body">';
    html += '<div class="perm-grid">';
    perms.forEach(function(p) {
      var key     = mod+'.'+p;
      var isRole  = !!roleMap[key];
      var isExpl  = !!explMap[key];
      var checked = isRole || isExpl;
      var sc      = isRole ? 's-role' : (isExpl ? 's-explicit' : '');
      var desc    = PERM_DESCS[p] || '';
      html += '<label class="perm-item '+sc+'">';
      html +=   '<input type="checkbox" class="perm-item-cb" data-key="'+esc(key)+'" data-mod="'+esc(mod)+'" data-perm="'+esc(p)+'"'+(checked?' checked':'')+' tabindex="0">';
      html +=   '<span class="perm-item-text">';
      html +=     '<span class="perm-item-name">'+esc(p)+'</span>';
      html +=     '<span class="perm-item-desc" title="'+esc(desc)+'">'+esc(desc)+'</span>';
      html +=   '</span>';
      html +=   '<span class="perm-dot" title="'+(isRole?'Granted by role':(isExpl?'Explicit grant':'Not granted'))+'"></span>';
      html += '</label>';
    });
    html += '</div>';
    html += '</div>';
    html += '</div>';

    idx++;
  });

  elAccordion.innerHTML = html;

  // attach listeners
  elAccordion.querySelectorAll('.perm-item-cb').forEach(function(cb) {
    cb.addEventListener('change', function(){ onCbChange(cb); });
  });
}

// ── select user ────────────────────────────────────────────────────────────
function selectUser(uid) {
  uid = parseInt(uid, 10);
  var data = PERM_DATA[uid];
  if (!data) return;

  activeUid = uid;

  // highlight card
  document.querySelectorAll('.perm-user-card').forEach(function(c){
    c.classList.toggle('active', parseInt(c.dataset.userId,10) === uid);
  });

  // show editor
  elEmpty.style.display  = 'none';
  elEditor.style.display = 'flex';

  // populate header
  elAvatar.textContent     = data.name.charAt(0).toUpperCase();
  elName.textContent       = data.name;
  elRoleBadge.className    = 'role-badge role-'+data.role;
  elRoleBadge.textContent  = data.role.charAt(0).toUpperCase() + data.role.slice(1);
  elDept.textContent       = data.department || '';

  // form target
  if (elSaveForm) elSaveForm.action = '/admin/permissions/' + uid + '/update';
  if (elCsrf)     elCsrf.value      = csrfToken;

  // banner
  var rc = data.rolePerms.length;
  elBannerText.textContent =
    'The "' + data.role + '" role grants ' + rc + ' permission' + (rc!==1?'s':'') + ' by default. '
    + 'Items highlighted green are role defaults; orange items are explicit overrides. '
    + 'Uncheck any item to remove that permission for this user.';

  buildAccordion(uid);
  refreshTotal();
  setDirty(false);

  // scroll editor to top
  var scroll = $id('eScroll');
  if (scroll) scroll.scrollTop = 0;
}

// ── event delegation ──────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
  // user card
  var card = e.target.closest('.perm-user-card');
  if (card) { selectUser(card.dataset.userId); return; }

  // batch grant/clear — check before mod-header to prevent propagation issues
  var batchGrant = e.target.closest('[data-grant]');
  var batchClear = e.target.closest('[data-clear]');

  if (batchGrant) {
    e.stopPropagation();
    var mod = batchGrant.dataset.grant;
    elAccordion.querySelectorAll('.perm-item-cb[data-mod="'+mod+'"]').forEach(function(cb){
      if (!cb.checked) { cb.checked = true; onCbChange(cb); }
    });
    return;
  }

  if (batchClear) {
    e.stopPropagation();
    var mod2 = batchClear.dataset.clear;
    elAccordion.querySelectorAll('.perm-item-cb[data-mod="'+mod2+'"]').forEach(function(cb){
      if (cb.checked) { cb.checked = false; onCbChange(cb); }
    });
    return;
  }

  // module header toggle
  var modHdr = e.target.closest('.perm-mod-header');
  if (modHdr) {
    var modEl = modHdr.closest('.perm-module');
    if (modEl) modEl.classList.toggle('open');
    return;
  }

  // save buttons
  if (e.target.closest('#eSaveTop') || e.target.closest('#eSaveBottom')) {
    doSave();
    return;
  }

  // expand / collapse all
  if (e.target.closest('#eBtnExpandAll')) {
    elAccordion.querySelectorAll('.perm-module').forEach(function(m){ m.classList.add('open'); });
    return;
  }
  if (e.target.closest('#eBtnCollapseAll')) {
    elAccordion.querySelectorAll('.perm-module').forEach(function(m){ m.classList.remove('open'); });
    return;
  }

}, true);

// ── save via AJAX ──────────────────────────────────────────────────────────
function doSave() {
  if (!activeUid) return;

  var perms = Object.keys(cbState).filter(function(k){ return cbState[k]; });

  var body = new URLSearchParams();
  body.append('csrf_token', csrfToken);
  perms.forEach(function(p){ body.append('permissions[]', p); });

  var saveBtns = [$id('eSaveTop'), $id('eSaveBottom')].filter(Boolean);
  saveBtns.forEach(function(b){ b.disabled = true; });

  fetch('/admin/permissions/' + activeUid + '/update', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: body.toString()
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      if (data.csrf) { csrfToken = data.csrf; if (elCsrf) elCsrf.value = csrfToken; }

      // Rebuild explicit perms in memory
      if (PERM_DATA[activeUid]) {
        var roleSet = {};
        PERM_DATA[activeUid].rolePerms.forEach(function(k){ roleSet[k] = true; });
        PERM_DATA[activeUid].explicitPerms = perms.filter(function(p){ return !roleSet[p]; });
      }

      setDirty(false);
      toast('Permissions saved successfully.', 'ok');

      // Refresh visual dot states
      elAccordion.querySelectorAll('.perm-item').forEach(function(item) {
        var cb = item.querySelector('.perm-item-cb');
        if (!cb) return;
        var k      = cb.dataset.key;
        var inRole = PERM_DATA[activeUid] && PERM_DATA[activeUid].rolePerms.indexOf(k) !== -1;
        var inExpl = PERM_DATA[activeUid] && PERM_DATA[activeUid].explicitPerms.indexOf(k) !== -1;
        item.classList.remove('s-role','s-explicit');
        if (cb.checked) item.classList.add(inRole ? 's-role' : 's-explicit');
      });

    } else {
      toast(data.message || 'An error occurred. Please try again.', 'err');
    }
  })
  .catch(function() {
    toast('Network error. Check your connection.', 'err');
  })
  .finally(function() {
    saveBtns.forEach(function(b){ b.disabled = false; });
  });
}

// ── user search ────────────────────────────────────────────────────────────
if (elSearch) {
  elSearch.addEventListener('input', function() {
    var q     = elSearch.value.toLowerCase().trim();
    var cards = document.querySelectorAll('.perm-user-card');
    var vis   = 0;
    cards.forEach(function(c) {
      var show = !q || c.dataset.name.indexOf(q) !== -1;
      c.style.display = show ? '' : 'none';
      if (show) vis++;
    });
    if (elUserCount) elUserCount.textContent = vis + ' user' + (vis!==1?'s':'');
  });
}

// ── auto-select if single user ─────────────────────────────────────────────
var firstCard = document.querySelector('.perm-user-card');
if (firstCard && document.querySelectorAll('.perm-user-card').length === 1) {
  selectUser(firstCard.dataset.userId);
}

}());
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
