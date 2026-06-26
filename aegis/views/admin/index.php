<?php
$pageTitle    = 'Admin';
$activeModule = 'admin';
$breadcrumbs  = [['Admin', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Administration</h1>
    <p class="page-subtitle">System configuration and management</p>
  </div>
</div>

<!-- Quick stats -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--primary),var(--secondary))"><i class="bi bi-people-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $userCount ?></div><div class="stat-label">Total Users</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--success),var(--success))"><i class="bi bi-key-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $apiKeyCount ?></div><div class="stat-label">Active API Keys</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--info),var(--info))"><i class="bi bi-gear-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= count($settings) ?></div><div class="stat-label">Settings</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--warning),var(--warning))"><i class="bi bi-activity"></i></div>
    <div class="stat-body"><div class="stat-value"><?= count($activityLog) ?></div><div class="stat-label">Recent Events</div></div>
  </div>
</div>

<div class="admin-grid">
  <!-- Quick links -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-grid-3x3-gap"></i> Admin Modules</h3></div>
    <div class="card-body">
      <div class="admin-module-grid">
        <a href="/admin/users" class="admin-module-card">
          <i class="bi bi-people-fill"></i><span>Users</span>
        </a>
        <a href="/admin/risk-matrix" class="admin-module-card">
          <i class="bi bi-sliders"></i><span>Risk Matrix</span>
        </a>
        <a href="/admin/workflows" class="admin-module-card">
          <i class="bi bi-diagram-3-fill"></i><span>Workflows</span>
        </a>
        <a href="/admin/alerts" class="admin-module-card">
          <i class="bi bi-bell-fill"></i><span>Alerts</span>
        </a>
        <a href="/admin/api-keys" class="admin-module-card">
          <i class="bi bi-key-fill"></i><span>API Keys</span>
        </a>
        <a href="/admin/permissions" class="admin-module-card">
          <i class="bi bi-shield-lock-fill"></i><span>Permissions</span>
        </a>
        <a href="/admin/module-visibility" class="admin-module-card" style="border-color:var(--primary);background:rgba(79,70,229,.06)">
          <i class="bi bi-grid-fill" style="color:var(--primary)"></i><span style="color:var(--primary)">Module Visibility</span>
        </a>
        <a href="/admin/settings" class="admin-module-card">
          <i class="bi bi-gear-fill"></i><span>Settings</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Settings -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-gear"></i> System Settings</h3></div>
    <div class="card-body">
      <?php foreach (['org_name'=>'Organization Name','timezone'=>'Timezone','date_format'=>'Date Format','session_timeout'=>'Session Timeout (min)','version'=>'AEGIS Version'] as $key=>$label): ?>
        <div class="detail-row">
          <span><?= $label ?></span>
          <strong><?= Security::h($settingsMap[$key] ?? '—') ?></strong>
        </div>
      <?php endforeach; ?>
      <?php if (!empty($settingsMap['installed_at'])): ?>
        <div class="detail-row"><span>Installed</span><strong><?= date('M j, Y', strtotime($settingsMap['installed_at'])) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Activity log -->
  <div class="card col-span-2">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-activity"></i> Activity Log</h3></div>
    <div class="card-body p0">
      <table class="table">
        <thead><tr><th scope="col">User</th><th scope="col">Action</th><th scope="col">Entity</th><th scope="col">IP</th><th scope="col">Time</th></tr></thead>
        <tbody>
          <?php foreach ($activityLog as $log): ?>
            <tr>
              <td><?= Security::h($log['user_name'] ?? 'System') ?></td>
              <td><span class="tag"><?= Security::h(str_replace('_',' ',$log['action'])) ?></span></td>
              <td><?= $log['entity_type'] ? Security::h($log['entity_type']).' #'.$log['entity_id'] : '—' ?></td>
              <td class="mono text-muted"><?= Security::h($log['ip_address'] ?? '') ?></td>
              <td class="text-muted text-sm"><?= date('M j, g:ia', strtotime($log['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
