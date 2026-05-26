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
<link rel="manifest" href="/public/manifest.json">
<meta name="theme-color" content="#6366f1">
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

  <?php
  // Determine which nav group is active so it auto-expands
  $navGroupMap = [
    'overview'    => ['dashboard'],
    'compliance'  => ['compliance','import','bulk_import','control_testing','compliance_gap'],
    'operations'  => ['audit','policy','incident','playbooks','issue','change','bcp','incident_sla','questionnaire'],
    'risk'        => ['risk','risk_matrix','risk_roadmap','risk_exceptions','threats','treatment_plans','kris','vendor','vendor_contracts','assets'],
    'analytics'   => ['metrics','documents','report','report_board','export','calendar'],
    'resources'   => ['search','docs'],
    'admin'       => ['admin','admin_users','admin_risk_matrix','admin_workflows','admin_alerts','admin_api_keys','admin_webhooks','admin_permissions','admin_logs','admin_email','admin_settings','admin_sso','admin_storage','admin_retention','admin_security_policy','admin_custom_fields','admin_tags','admin_approval_templates','admin_sessions','admin_risk_appetite','policy_attestations','admin_sla'],
    'account'     => ['approvals','profile_notifications','profile_edit','my_attestations','profile','mfa_backup'],
  ];
  $activeGroup = '';
  foreach ($navGroupMap as $grp => $mods) {
    if (in_array($activeModule ?? '', $mods, true)) { $activeGroup = $grp; break; }
  }
  ?>
  <nav class="sidebar-nav">

    <!-- ── Overview ──────────────────────────────────────── -->
    <div class="nav-group <?= $activeGroup === 'overview' ? 'open' : '' ?>">
      <button class="nav-group-header" type="button">
        <span><i class="bi bi-grid-1x2-fill"></i> Overview</span>
        <i class="bi bi-chevron-down nav-group-arrow"></i>
      </button>
      <div class="nav-group-items">
        <a href="/" class="nav-item <?= $activeModule === 'dashboard' ? 'active' : '' ?>">
          <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
        </a>
      </div>
    </div>

    <!-- ── Compliance ─────────────────────────────────────── -->
    <div class="nav-group <?= $activeGroup === 'compliance' ? 'open' : '' ?>">
      <button class="nav-group-header" type="button">
        <span><i class="bi bi-shield-check"></i> Compliance</span>
        <i class="bi bi-chevron-down nav-group-arrow"></i>
      </button>
      <div class="nav-group-items">
        <a href="/compliance" class="nav-item <?= $activeModule === 'compliance' ? 'active' : '' ?>">
          <i class="bi bi-shield-check"></i><span>Packages</span>
        </a>
        <a href="/compliance/import" class="nav-item <?= $activeModule === 'import' ? 'active' : '' ?>">
          <i class="bi bi-cloud-upload"></i><span>Import Standard</span>
        </a>
        <a href="/import" class="nav-item <?= $activeModule === 'bulk_import' ? 'active' : '' ?>">
          <i class="bi bi-table"></i><span>Bulk Import</span>
        </a>
        <a href="/compliance/testing" class="nav-item <?= $activeModule === 'control_testing' ? 'active' : '' ?>">
          <i class="bi bi-clipboard2-pulse-fill"></i><span>Control Testing</span>
        </a>
        <a href="/compliance/gap-analysis" class="nav-item <?= $activeModule === 'compliance_gap' ? 'active' : '' ?>">
          <i class="bi bi-bar-chart-steps"></i><span>Gap Analysis</span>
        </a>
      </div>
    </div>

    <!-- ── Operations ─────────────────────────────────────── -->
    <div class="nav-group <?= $activeGroup === 'operations' ? 'open' : '' ?>">
      <button class="nav-group-header" type="button">
        <span><i class="bi bi-clipboard2-check-fill"></i> Operations</span>
        <i class="bi bi-chevron-down nav-group-arrow"></i>
      </button>
      <div class="nav-group-items">
        <a href="/audit" class="nav-item <?= $activeModule === 'audit' ? 'active' : '' ?>">
          <i class="bi bi-clipboard2-check-fill"></i><span>Audits</span>
        </a>
        <a href="/policy" class="nav-item <?= $activeModule === 'policy' ? 'active' : '' ?>">
          <i class="bi bi-file-earmark-text-fill"></i><span>Policies</span>
        </a>
        <a href="/incident" class="nav-item <?= $activeModule === 'incident' ? 'active' : '' ?>">
          <i class="bi bi-fire"></i><span>Incidents</span>
        </a>
        <a href="/playbooks" class="nav-item <?= $activeModule === 'playbooks' ? 'active' : '' ?>">
          <i class="bi bi-journal-code"></i><span>Playbooks</span>
        </a>
        <a href="/issue" class="nav-item <?= $activeModule === 'issue' ? 'active' : '' ?>">
          <i class="bi bi-bug-fill"></i><span>Issues</span>
        </a>
        <a href="/change" class="nav-item <?= $activeModule === 'change' ? 'active' : '' ?>">
          <i class="bi bi-arrow-repeat"></i><span>Change Requests</span>
        </a>
        <a href="/bcp" class="nav-item <?= $activeModule === 'bcp' ? 'active' : '' ?>">
          <i class="bi bi-shield-fill-exclamation"></i><span>BCP / DR</span>
        </a>
        <a href="/incident/sla" class="nav-item <?= $activeModule === 'incident_sla' ? 'active' : '' ?>">
          <i class="bi bi-stopwatch-fill"></i><span>Incident SLA</span>
        </a>
        <a href="/questionnaire" class="nav-item <?= $activeModule === 'questionnaire' ? 'active' : '' ?>">
          <i class="bi bi-ui-checks-grid"></i><span>Questionnaires</span>
        </a>
      </div>
    </div>

    <!-- ── Risk ───────────────────────────────────────────── -->
    <div class="nav-group <?= $activeGroup === 'risk' ? 'open' : '' ?>">
      <button class="nav-group-header" type="button">
        <span><i class="bi bi-exclamation-triangle-fill"></i> Risk</span>
        <i class="bi bi-chevron-down nav-group-arrow"></i>
      </button>
      <div class="nav-group-items">
        <a href="/risk" class="nav-item <?= $activeModule === 'risk' ? 'active' : '' ?>">
          <i class="bi bi-exclamation-triangle-fill"></i><span>Risk Register</span>
        </a>
        <a href="/risk/matrix" class="nav-item <?= $activeModule === 'risk_matrix' ? 'active' : '' ?>">
          <i class="bi bi-grid-3x3-gap-fill"></i><span>Risk Matrix</span>
        </a>
        <a href="/risk/roadmap" class="nav-item <?= $activeModule === 'risk_roadmap' ? 'active' : '' ?>">
          <i class="bi bi-kanban-fill"></i><span>Treatment Roadmap</span>
        </a>
        <a href="/risk/exceptions" class="nav-item <?= $activeModule === 'risk_exceptions' ? 'active' : '' ?>">
          <i class="bi bi-shield-slash"></i><span>Exceptions</span>
        </a>
        <a href="/threats" class="nav-item <?= $activeModule === 'threats' ? 'active' : '' ?>">
          <i class="bi bi-biohazard"></i><span>Threat Register</span>
        </a>
        <a href="/treatment" class="nav-item <?= $activeModule === 'treatment_plans' ? 'active' : '' ?>">
          <i class="bi bi-tools"></i><span>Treatment Plans</span>
        </a>
        <a href="/kris" class="nav-item <?= $activeModule === 'kris' ? 'active' : '' ?>">
          <i class="bi bi-activity"></i><span>KRI Dashboard</span>
        </a>
        <a href="/vendor" class="nav-item <?= $activeModule === 'vendor' ? 'active' : '' ?>">
          <i class="bi bi-building"></i><span>Vendor Risk</span>
        </a>
        <a href="/vendor/contracts" class="nav-item <?= $activeModule === 'vendor_contracts' ? 'active' : '' ?>">
          <i class="bi bi-file-earmark-check-fill"></i><span>Contracts</span>
        </a>
        <a href="/assets" class="nav-item <?= $activeModule === 'assets' ? 'active' : '' ?>">
          <i class="bi bi-server"></i><span>Asset Inventory</span>
        </a>
      </div>
    </div>

    <!-- ── Analytics ──────────────────────────────────────── -->
    <div class="nav-group <?= $activeGroup === 'analytics' ? 'open' : '' ?>">
      <button class="nav-group-header" type="button">
        <span><i class="bi bi-graph-up-arrow"></i> Analytics</span>
        <i class="bi bi-chevron-down nav-group-arrow"></i>
      </button>
      <div class="nav-group-items">
        <a href="/metrics" class="nav-item <?= $activeModule === 'metrics' ? 'active' : '' ?>">
          <i class="bi bi-graph-up-arrow"></i><span>Metrics &amp; Trends</span>
        </a>
        <a href="/report" class="nav-item <?= $activeModule === 'report' ? 'active' : '' ?>">
          <i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span>
        </a>
        <a href="/report/board" class="nav-item <?= $activeModule === 'report_board' ? 'active' : '' ?>">
          <i class="bi bi-tv-fill"></i><span>Board Dashboard</span>
        </a>
        <a href="/documents" class="nav-item <?= $activeModule === 'documents' ? 'active' : '' ?>">
          <i class="bi bi-folder2-open"></i><span>Documents</span>
        </a>
        <a href="/export" class="nav-item <?= $activeModule === 'export' ? 'active' : '' ?>">
          <i class="bi bi-download"></i><span>Export</span>
        </a>
        <a href="/calendar" class="nav-item <?= $activeModule === 'calendar' ? 'active' : '' ?>">
          <i class="bi bi-calendar3"></i><span>Calendar</span>
        </a>
      </div>
    </div>

    <!-- ── Resources ──────────────────────────────────────── -->
    <div class="nav-group <?= $activeGroup === 'resources' ? 'open' : '' ?>">
      <button class="nav-group-header" type="button">
        <span><i class="bi bi-book-fill"></i> Resources</span>
        <i class="bi bi-chevron-down nav-group-arrow"></i>
      </button>
      <div class="nav-group-items">
        <a href="/search" class="nav-item <?= $activeModule === 'search' ? 'active' : '' ?>">
          <i class="bi bi-search"></i><span>Search</span>
        </a>
        <a href="/docs" class="nav-item <?= $activeModule === 'docs' ? 'active' : '' ?>">
          <i class="bi bi-book-fill"></i><span>Documentation</span>
        </a>
      </div>
    </div>

    <!-- ── Administration ─────────────────────────────────── -->
    <?php if (Auth::can('admin') || Auth::role() === 'admin'): ?>
    <div class="nav-group <?= $activeGroup === 'admin' ? 'open' : '' ?>">
      <button class="nav-group-header" type="button">
        <span><i class="bi bi-gear-fill"></i> Administration</span>
        <i class="bi bi-chevron-down nav-group-arrow"></i>
      </button>
      <div class="nav-group-items">
        <a href="/admin" class="nav-item <?= $activeModule === 'admin' ? 'active' : '' ?>">
          <i class="bi bi-speedometer2"></i><span>Overview</span>
        </a>
        <a href="/admin/users" class="nav-item <?= $activeModule === 'admin_users' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i><span>Users</span>
        </a>
        <a href="/admin/permissions" class="nav-item <?= $activeModule === 'admin_permissions' ? 'active' : '' ?>">
          <i class="bi bi-shield-lock-fill"></i><span>Permissions</span>
        </a>
        <a href="/admin/workflows" class="nav-item <?= $activeModule === 'admin_workflows' ? 'active' : '' ?>">
          <i class="bi bi-diagram-3-fill"></i><span>Workflows</span>
        </a>
        <a href="/admin/approval-templates" class="nav-item <?= $activeModule === 'admin_approval_templates' ? 'active' : '' ?>">
          <i class="bi bi-list-check"></i><span>Approval Templates</span>
        </a>
        <a href="/admin/risk-matrix" class="nav-item <?= $activeModule === 'admin_risk_matrix' ? 'active' : '' ?>">
          <i class="bi bi-sliders"></i><span>Risk Matrix</span>
        </a>
        <a href="/admin/risk-appetite" class="nav-item <?= $activeModule === 'admin_risk_appetite' ? 'active' : '' ?>">
          <i class="bi bi-speedometer"></i><span>Risk Appetite</span>
        </a>
        <a href="/admin/alerts" class="nav-item <?= $activeModule === 'admin_alerts' ? 'active' : '' ?>">
          <i class="bi bi-bell-fill"></i><span>Alerts</span>
        </a>
        <a href="/admin/api-keys" class="nav-item <?= $activeModule === 'admin_api_keys' ? 'active' : '' ?>">
          <i class="bi bi-key-fill"></i><span>API Keys</span>
        </a>
        <a href="/admin/webhooks" class="nav-item <?= $activeModule === 'admin_webhooks' ? 'active' : '' ?>">
          <i class="bi bi-broadcast"></i><span>Webhooks</span>
        </a>
        <a href="/admin/email" class="nav-item <?= $activeModule === 'admin_email' ? 'active' : '' ?>">
          <i class="bi bi-envelope-fill"></i><span>Email Settings</span>
        </a>
        <a href="/admin/settings" class="nav-item <?= $activeModule === 'admin_settings' ? 'active' : '' ?>">
          <i class="bi bi-gear-fill"></i><span>System Settings</span>
        </a>
        <a href="/admin/settings/sso" class="nav-item <?= $activeModule === 'admin_sso' ? 'active' : '' ?>">
          <i class="bi bi-person-badge-fill"></i><span>SSO / OIDC</span>
        </a>
        <a href="/admin/security-policy" class="nav-item <?= $activeModule === 'admin_security_policy' ? 'active' : '' ?>">
          <i class="bi bi-shield-fill-check"></i><span>Security Policy</span>
        </a>
        <a href="/admin/logs" class="nav-item <?= $activeModule === 'admin_logs' ? 'active' : '' ?>">
          <i class="bi bi-journal-text"></i><span>Activity Logs</span>
        </a>
        <a href="/admin/sessions" class="nav-item <?= $activeModule === 'admin_sessions' ? 'active' : '' ?>">
          <i class="bi bi-people-fill"></i><span>Sessions</span>
        </a>
        <a href="/admin/storage" class="nav-item <?= $activeModule === 'admin_storage' ? 'active' : '' ?>">
          <i class="bi bi-hdd-fill"></i><span>Storage</span>
        </a>
        <a href="/admin/retention" class="nav-item <?= $activeModule === 'admin_retention' ? 'active' : '' ?>">
          <i class="bi bi-clock-history"></i><span>Data Retention</span>
        </a>
        <a href="/admin/custom-fields" class="nav-item <?= $activeModule === 'admin_custom_fields' ? 'active' : '' ?>">
          <i class="bi bi-input-cursor-text"></i><span>Custom Fields</span>
        </a>
        <a href="/admin/tags" class="nav-item <?= $activeModule === 'admin_tags' ? 'active' : '' ?>">
          <i class="bi bi-tags-fill"></i><span>Tags</span>
        </a>
        <a href="/admin/sla-policy" class="nav-item <?= $activeModule === 'admin_sla' ? 'active' : '' ?>">
          <i class="bi bi-stopwatch-fill"></i><span>SLA Policy</span>
        </a>
        <a href="/policy/attestations" class="nav-item <?= $activeModule === 'policy_attestations' ? 'active' : '' ?>">
          <i class="bi bi-pen-fill"></i><span>Attestations</span>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Account ────────────────────────────────────────── -->
    <div class="nav-group <?= $activeGroup === 'account' ? 'open' : '' ?>">
      <button class="nav-group-header" type="button">
        <span><i class="bi bi-person-circle"></i> Account</span>
        <i class="bi bi-chevron-down nav-group-arrow"></i>
      </button>
      <div class="nav-group-items">
        <a href="/approvals" class="nav-item <?= $activeModule === 'approvals' ? 'active' : '' ?>">
          <i class="bi bi-check2-square"></i><span>Approvals</span>
          <?php
            try {
              $pendingApprovals = Database::fetchOne(
                "SELECT COUNT(*) as c FROM approval_requests ar
                 JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.step_number = ar.current_step
                 WHERE ar.status = 'pending' AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')",
                [$u['id'], $u['role'], $u['role']]
              );
              if (($pendingApprovals['c'] ?? 0) > 0):
            ?>
              <span class="nav-badge"><?= (int)$pendingApprovals['c'] ?></span>
            <?php endif; } catch (Throwable) {} ?>
        </a>
        <a href="/profile/notifications" class="nav-item <?= $activeModule === 'profile_notifications' ? 'active' : '' ?>">
          <i class="bi bi-bell-fill"></i><span>Notifications</span>
        </a>
        <a href="/profile/edit" class="nav-item <?= $activeModule === 'profile_edit' ? 'active' : '' ?>">
          <i class="bi bi-person-fill-gear"></i><span>Edit Profile</span>
        </a>
        <a href="/my-attestations" class="nav-item <?= $activeModule === 'my_attestations' ? 'active' : '' ?>">
          <i class="bi bi-pen-fill"></i><span>My Attestations</span>
        </a>
        <a href="/mfa/setup" class="nav-item <?= $activeModule === 'profile' ? 'active' : '' ?>">
          <i class="bi bi-shield-lock-fill"></i><span>Two-Factor Auth</span>
        </a>
        <a href="/mfa/backup-codes" class="nav-item <?= $activeModule === 'mfa_backup' ? 'active' : '' ?>">
          <i class="bi bi-key-fill"></i><span>Backup Codes</span>
        </a>
      </div>
    </div>

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
<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<!-- Main content -->
<div class="main-content" id="mainContent">
  <header class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" aria-label="Open menu" type="button" onclick="toggleSidebar()">
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" nonce="<?= Security::nonce() ?>"></script>
<script src="/public/js/app.js" nonce="<?= Security::nonce() ?>"></script>
</body>
</html>
