<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= Security::h($pageTitle ?? 'AEGIS GRC') ?> — AEGIS GRC</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/public/css/app.css">
<meta name="csrf-token" content="<?= Security::generateCsrfToken() ?>">
</head>
<body>

<?php $u = Auth::user(); ?>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="bi bi-shield-fill-check"></i></div>
    <div class="brand-text">
      <span class="brand-name">AEGIS</span>
      <span class="brand-sub">GRC Platform</span>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Overview</div>
    <a href="/" class="nav-item <?= $activeModule === 'dashboard' ? 'active' : '' ?>">
      <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
    </a>

    <div class="nav-section-label">Compliance</div>
    <a href="/compliance" class="nav-item <?= $activeModule === 'compliance' ? 'active' : '' ?>">
      <i class="bi bi-shield-check"></i><span>Packages</span>
    </a>
    <a href="/compliance/import" class="nav-item <?= $activeModule === 'import' ? 'active' : '' ?>">
      <i class="bi bi-cloud-upload"></i><span>Import Standard</span>
    </a>

    <div class="nav-section-label">Operations</div>
    <a href="/audit" class="nav-item <?= $activeModule === 'audit' ? 'active' : '' ?>">
      <i class="bi bi-clipboard2-check-fill"></i><span>Audits</span>
    </a>
    <a href="/policy" class="nav-item <?= $activeModule === 'policy' ? 'active' : '' ?>">
      <i class="bi bi-file-earmark-text-fill"></i><span>Policies</span>
    </a>
    <a href="/incident" class="nav-item <?= $activeModule === 'incident' ? 'active' : '' ?>">
      <i class="bi bi-fire"></i><span>Incidents</span>
    </a>
    <a href="/issue" class="nav-item <?= $activeModule === 'issue' ? 'active' : '' ?>">
      <i class="bi bi-bug-fill"></i><span>Issues</span>
    </a>

    <div class="nav-section-label">Risk</div>
    <a href="/risk" class="nav-item <?= $activeModule === 'risk' ? 'active' : '' ?>">
      <i class="bi bi-exclamation-triangle-fill"></i><span>Risk Register</span>
    </a>
    <a href="/risk/matrix" class="nav-item <?= $activeModule === 'risk_matrix' ? 'active' : '' ?>">
      <i class="bi bi-grid-3x3-gap-fill"></i><span>Risk Matrix</span>
    </a>
    <a href="/vendor" class="nav-item <?= $activeModule === 'vendor' ? 'active' : '' ?>">
      <i class="bi bi-building"></i><span>Vendor Risk</span>
    </a>

    <div class="nav-section-label">Analytics</div>
    <a href="/metrics" class="nav-item <?= $activeModule === 'metrics' ? 'active' : '' ?>">
      <i class="bi bi-graph-up-arrow"></i><span>Metrics</span>
    </a>
    <a href="/report" class="nav-item <?= $activeModule === 'report' ? 'active' : '' ?>">
      <i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span>
    </a>
    <a href="/export" class="nav-item <?= $activeModule === 'export' ? 'active' : '' ?>">
      <i class="bi bi-download"></i><span>Export</span>
    </a>

    <div class="nav-section-label">Resources</div>
    <a href="/docs" class="nav-item <?= $activeModule === 'docs' ? 'active' : '' ?>">
      <i class="bi bi-book-fill"></i><span>Documentation</span>
    </a>

    <?php if (Auth::can('admin') || Auth::role() === 'admin'): ?>
    <div class="nav-section-label">Administration</div>
    <a href="/admin" class="nav-item <?= $activeModule === 'admin' ? 'active' : '' ?>">
      <i class="bi bi-speedometer2"></i><span>Overview</span>
    </a>
    <a href="/admin/users" class="nav-item <?= $activeModule === 'admin_users' ? 'active' : '' ?>">
      <i class="bi bi-people-fill"></i><span>Users</span>
    </a>
    <a href="/admin/risk-matrix" class="nav-item <?= $activeModule === 'admin_risk_matrix' ? 'active' : '' ?>">
      <i class="bi bi-sliders"></i><span>Risk Matrix</span>
    </a>
    <a href="/admin/workflows" class="nav-item <?= $activeModule === 'admin_workflows' ? 'active' : '' ?>">
      <i class="bi bi-diagram-3-fill"></i><span>Workflows</span>
    </a>
    <a href="/admin/alerts" class="nav-item <?= $activeModule === 'admin_alerts' ? 'active' : '' ?>">
      <i class="bi bi-bell-fill"></i><span>Alerts</span>
    </a>
    <a href="/admin/api-keys" class="nav-item <?= $activeModule === 'admin_api_keys' ? 'active' : '' ?>">
      <i class="bi bi-key-fill"></i><span>API Keys</span>
    </a>
    <a href="/admin/permissions" class="nav-item <?= $activeModule === 'admin_permissions' ? 'active' : '' ?>">
      <i class="bi bi-shield-lock-fill"></i><span>Permissions</span>
    </a>
    <a href="/admin/logs" class="nav-item <?= $activeModule === 'admin_logs' ? 'active' : '' ?>">
      <i class="bi bi-journal-text"></i><span>Activity Logs</span>
    </a>
    <a href="/admin/email" class="nav-item <?= $activeModule === 'admin_email' ? 'active' : '' ?>">
      <i class="bi bi-envelope-fill"></i><span>Email Settings</span>
    </a>
    <a href="/admin/settings" class="nav-item <?= $activeModule === 'admin_settings' ? 'active' : '' ?>">
      <i class="bi bi-gear-fill"></i><span>System Settings</span>
    </a>
    <?php endif; ?>
    <div class="nav-section-label">Account</div>
    <a href="/mfa/setup" class="nav-item <?= $activeModule === 'profile' ? 'active' : '' ?>">
      <i class="bi bi-shield-lock-fill"></i><span>Two-Factor Auth</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= Security::h($u['name']) ?></div>
        <div class="user-role"><?= ucfirst(Security::h($u['role'])) ?></div>
      </div>
      <form method="POST" action="/logout" style="display:inline;margin:0;padding:0">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <button type="submit" class="btn-logout" title="Logout" style="background:none;border:none;cursor:pointer;padding:0"><i class="bi bi-box-arrow-right"></i></button>
      </form>
    </div>
  </div>
</aside>

<!-- Main content -->
<div class="main-content" id="mainContent">
  <header class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="bi bi-list"></i>
      </button>
      <div class="breadcrumb-area">
        <?php if (!empty($breadcrumbs)): ?>
          <?php foreach ($breadcrumbs as $i => [$label, $url]): ?>
            <?php if ($url): ?>
              <a href="<?= Security::h($url) ?>" class="breadcrumb-link"><?= Security::h($label) ?></a>
            <?php else: ?>
              <span class="breadcrumb-current"><?= Security::h($label) ?></span>
            <?php endif; ?>
            <?php if ($i < count($breadcrumbs) - 1): ?><span class="breadcrumb-sep"><i class="bi bi-chevron-right"></i></span><?php endif; ?>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="breadcrumb-current"><?= Security::h($pageTitle ?? '') ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="topbar-right">
      <?php
      $unreadAlerts = Database::fetchOne("SELECT COUNT(*) as c FROM alerts WHERE user_id = ? AND is_read = FALSE", [Auth::id()])['c'] ?? 0;
      ?>
      <div class="alert-bell" onclick="toggleAlertPanel()">
        <i class="bi bi-bell<?= $unreadAlerts > 0 ? '-fill' : '' ?>"></i>
        <?php if ($unreadAlerts > 0): ?>
          <span class="alert-badge"><?= min($unreadAlerts, 99) ?></span>
        <?php endif; ?>
      </div>
      <div class="topbar-user">
        <div class="user-avatar sm"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
        <span><?= Security::h(explode(' ', $u['name'])[0]) ?></span>
      </div>
    </div>
  </header>

  <!-- Alert panel (fly-out) -->
  <div class="alert-panel" id="alertPanel">
    <div class="alert-panel-header">
      <span>Notifications</span>
      <button onclick="toggleAlertPanel()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="alert-panel-body" id="alertPanelBody">
      <?php
      $panelAlerts = Database::fetchAll("SELECT * FROM alerts WHERE user_id = ? ORDER BY created_at DESC LIMIT 20", [Auth::id()]);
      if ($panelAlerts): foreach ($panelAlerts as $al): ?>
        <div class="alert-item <?= $al['is_read'] ? 'read' : 'unread' ?>" data-id="<?= $al['id'] ?>">
          <div class="alert-item-icon sev-<?= Security::h($al['severity']) ?>">
            <i class="bi bi-<?= $al['severity'] === 'critical' ? 'exclamation-octagon-fill' : ($al['severity'] === 'warning' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>"></i>
          </div>
          <div class="alert-item-body">
            <div class="alert-item-title"><?= Security::h($al['title']) ?></div>
            <div class="alert-item-time"><?= date('M j, g:ia', strtotime($al['created_at'])) ?></div>
          </div>
          <?php if (!$al['is_read']): ?>
            <button class="mark-read-btn" onclick="markAlertRead(<?= (int)$al['id'] ?>, this)"><i class="bi bi-check"></i></button>
          <?php endif; ?>
        </div>
      <?php endforeach; else: ?>
        <div class="alert-empty"><i class="bi bi-check-circle-fill"></i><p>All caught up!</p></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="alert-overlay" id="alertOverlay" onclick="toggleAlertPanel()"></div>

  <main class="page-content">
    <?= $content ?? '' ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="/public/js/app.js"></script>
</body>
</html>
