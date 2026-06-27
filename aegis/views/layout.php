<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__brandName = Branding::name(); ?>
<title><?= Security::h($pageTitle ?? $__brandName) ?> — <?= Security::h($__brandName) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
<?php
// Cache-bust app.css/app.js by file mtime so every deploy serves fresh assets.
// (A hardcoded ?v= meant CSS/JS changes were masked by browser/CDN caches.)
$__cssVer = @filemtime(AEGIS_ROOT . '/public/css/app.css') ?: time();
$__jsVer  = @filemtime(AEGIS_ROOT . '/public/js/app.js')  ?: time();
?>
<link rel="stylesheet" href="/public/css/app.css?v=<?= $__cssVer ?>">
<link rel="manifest" href="/public/manifest.json">
<meta name="theme-color" content="var(--primary)">
<meta name="csrf-token" content="<?= Security::generateCsrfToken() ?>">
<script nonce="<?= Security::nonce() ?>">(function(){var t=localStorage.getItem('aegis-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
<?= Branding::accentStyleTag() ?>
</head>
<body>

<?php $u = Auth::user(); ?>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
  <a href="/" class="sidebar-brand" style="text-decoration:none">
    <?php $__logoData = Branding::logo(); ?>
    <?php if ($__logoData): ?>
      <img src="<?= Security::h($__logoData) ?>" alt="<?= Security::h($__brandName) ?> logo"
           class="brand-logo-img" data-logo-fallback
           style="width:36px;height:36px;object-fit:contain;border-radius:8px">
      <div class="brand-icon brand-logo-fallback" style="display:none"><i class="bi bi-shield-fill-check"></i></div>
    <?php else: ?>
      <div class="brand-icon"><i class="bi bi-shield-fill-check"></i></div>
    <?php endif; ?>
    <div class="brand-text">
      <span class="brand-name"><?= Security::h($__brandName) ?></span>
      <span class="brand-sub">Enterprise Governance &amp; Compliance Platform</span>
    </div>
  </a>

  <?php
  // Load module visibility settings once for the sidebar
  try {
    $__mvRows = Database::fetchAll("SELECT key, value FROM settings WHERE key LIKE 'module_hide_%'");
    $__mv = array_column($__mvRows, 'value', 'key');
  } catch (Throwable) { $__mv = []; }
  if (!function_exists('moduleVisible')) {
    function moduleVisible(string $key, array $mv): bool {
      return ($mv['module_hide_' . $key] ?? '0') !== '1';
    }
  }
  ?>
  <nav class="sidebar-nav">

    <!-- Overview -->
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="overview">
        <span>Overview</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-overview">
        <a href="/" class="nav-item <?= $activeModule === 'dashboard' ? 'active' : '' ?>">
          <i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span>
        </a>
      </div>
    </div>

    <!-- Compliance -->
    <?php if (moduleVisible('compliance', $__mv) || moduleVisible('import', $__mv) || moduleVisible('bulk_import', $__mv) || moduleVisible('control_testing', $__mv) || moduleVisible('compliance_gap', $__mv) || moduleVisible('ssp', $__mv) || moduleVisible('poam', $__mv) || moduleVisible('odp', $__mv) || moduleVisible('sprs', $__mv) || moduleVisible('raci', $__mv) || moduleVisible('audit_findings', $__mv)): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="compliance">
        <span>Compliance</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-compliance">
        <?php if (moduleVisible('compliance',     $__mv)): ?><a href="/compliance"           class="nav-item <?= $activeModule==='compliance'?'active':'' ?>"><i class="bi bi-shield-check"></i><span>Packages</span></a><?php endif; ?>
        <?php if (moduleVisible('import',         $__mv)): ?><a href="/compliance/import"    class="nav-item <?= $activeModule==='import'?'active':'' ?>"><i class="bi bi-cloud-upload"></i><span>Import Standard</span></a><?php endif; ?>
        <?php if (moduleVisible('bulk_import',    $__mv)): ?><a href="/import"               class="nav-item <?= $activeModule==='bulk_import'?'active':'' ?>"><i class="bi bi-table"></i><span>Bulk Import</span></a><?php endif; ?>
        <?php if (moduleVisible('control_testing',$__mv)): ?><a href="/compliance/testing"   class="nav-item <?= $activeModule==='control_testing'?'active':'' ?>"><i class="bi bi-clipboard2-pulse-fill"></i><span>Control Testing</span></a><?php endif; ?>
        <?php if (moduleVisible('compliance_gap', $__mv)): ?><a href="/compliance/gap-analysis" class="nav-item <?= $activeModule==='compliance_gap'?'active':'' ?>"><i class="bi bi-bar-chart-steps"></i><span>Gap Analysis</span></a><?php endif; ?>
        <?php if (moduleVisible('ssp',            $__mv)): ?><a href="/ssp"           class="nav-item <?= $activeModule==='ssp'?'active':'' ?>"><i class="bi bi-file-earmark-lock2-fill"></i><span>Sec. Plans (SSP)</span></a><?php endif; ?>
        <?php if (moduleVisible('poam',           $__mv)): ?><a href="/poam"          class="nav-item <?= $activeModule==='poam'?'active':'' ?>"><i class="bi bi-list-check"></i><span>POA&amp;M</span></a><?php endif; ?>
        <?php if (moduleVisible('audit_findings', $__mv)): ?><a href="/audit-findings" class="nav-item <?= $activeModule==='audit_findings'?'active':'' ?>"><i class="bi bi-journal-x"></i><span>Audit Findings</span></a><?php endif; ?>
        <?php if (moduleVisible('odp',            $__mv)): ?><a href="/odp"           class="nav-item <?= $activeModule==='odp'?'active':'' ?>"><i class="bi bi-sliders"></i><span>ODP Center</span></a><?php endif; ?>
        <?php if (moduleVisible('sprs',           $__mv)): ?><a href="/sprs"          class="nav-item <?= $activeModule==='sprs'?'active':'' ?>"><i class="bi bi-speedometer2"></i><span>SPRS Score</span></a><?php endif; ?>
        <?php if (moduleVisible('raci',           $__mv)): ?><a href="/raci"          class="nav-item <?= $activeModule==='raci'?'active':'' ?>"><i class="bi bi-people-fill"></i><span>RACI Matrix</span></a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Operations -->
    <?php $__opsVisible = array_filter(['audit','policy','playbooks','issue','bcp','incident_sla','questionnaire','awareness','privacy','projects'], fn($m) => moduleVisible($m, $__mv));
    if ($__opsVisible): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="operations">
        <span>Operations</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-operations">
        <?php if (moduleVisible('audit',         $__mv)): ?><a href="/audit"        class="nav-item <?= $activeModule==='audit'?'active':'' ?>"><i class="bi bi-clipboard2-check-fill"></i><span>Audits</span></a><?php endif; ?>
        <?php if (moduleVisible('policy',        $__mv)): ?><a href="/policy"       class="nav-item <?= $activeModule==='policy'?'active':'' ?>"><i class="bi bi-file-earmark-text-fill"></i><span>Policies</span></a><?php endif; ?>
        <?php if (moduleVisible('playbooks',     $__mv)): ?><a href="/playbooks"    class="nav-item <?= $activeModule==='playbooks'?'active':'' ?>"><i class="bi bi-journal-code"></i><span>Playbooks</span></a><?php endif; ?>
        <?php if (moduleVisible('issue',         $__mv)): ?><a href="/issue"        class="nav-item <?= $activeModule==='issue'?'active':'' ?>"><i class="bi bi-bug-fill"></i><span>Issues</span></a><?php endif; ?>
        <?php if (moduleVisible('bcp',           $__mv)): ?><a href="/bcp"          class="nav-item <?= $activeModule==='bcp'?'active':'' ?>"><i class="bi bi-shield-fill-exclamation"></i><span>BCP / DR</span></a><?php endif; ?>
        <?php if (moduleVisible('incident_sla',  $__mv)): ?><a href="/incident/sla" class="nav-item <?= $activeModule==='incident_sla'?'active':'' ?>"><i class="bi bi-stopwatch-fill"></i><span>Incident SLA</span></a><?php endif; ?>
        <?php if (moduleVisible('questionnaire',  $__mv)): ?><a href="/questionnaire"   class="nav-item <?= $activeModule==='questionnaire'?'active':'' ?>"><i class="bi bi-ui-checks-grid"></i><span>Questionnaires</span></a><?php endif; ?>
        <?php if (moduleVisible('awareness',      $__mv)): ?><a href="/awareness"       class="nav-item <?= $activeModule==='awareness'?'active':'' ?>"><i class="bi bi-mortarboard-fill"></i><span>Awareness Training</span></a><?php endif; ?>
        <?php if (moduleVisible('privacy',        $__mv)): ?><a href="/privacy"         class="nav-item <?= $activeModule==='privacy'?'active':'' ?>"><i class="bi bi-shield-lock-fill"></i><span>Data Privacy</span></a><?php endif; ?>
        <?php if (moduleVisible('projects',       $__mv)): ?><a href="/projects"        class="nav-item <?= $activeModule==='projects'?'active':'' ?>"><i class="bi bi-briefcase-fill"></i><span>GRC Projects</span></a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Risk -->
    <?php $__riskVisible = array_filter(['risk','risk_matrix','risk_roadmap','risk_exceptions','threats','treatment_plans','kris','vendor','vendor_contracts','assets'], fn($m) => moduleVisible($m, $__mv));
    if ($__riskVisible): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="risk">
        <span>Risk</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-risk">
        <?php if (moduleVisible('risk',             $__mv)): ?><a href="/risk"             class="nav-item <?= $activeModule==='risk'?'active':'' ?>"><i class="bi bi-exclamation-triangle-fill"></i><span>Risk Register</span></a><?php endif; ?>
        <?php if (moduleVisible('risk_matrix',      $__mv)): ?><a href="/risk/matrix"      class="nav-item <?= $activeModule==='risk_matrix'?'active':'' ?>"><i class="bi bi-grid-3x3-gap-fill"></i><span>Risk Matrix</span></a><?php endif; ?>
        <?php if (moduleVisible('risk_roadmap',     $__mv)): ?><a href="/risk/roadmap"     class="nav-item <?= $activeModule==='risk_roadmap'?'active':'' ?>"><i class="bi bi-kanban-fill"></i><span>Treatment Roadmap</span></a><?php endif; ?>
        <?php if (moduleVisible('risk_exceptions',  $__mv)): ?><a href="/risk/exceptions"  class="nav-item <?= $activeModule==='risk_exceptions'?'active':'' ?>"><i class="bi bi-shield-slash"></i><span>Exceptions</span></a><?php endif; ?>
        <?php if (moduleVisible('threats',          $__mv)): ?><a href="/threats"          class="nav-item <?= $activeModule==='threats'?'active':'' ?>"><i class="bi bi-shield-exclamation"></i><span>Threat Register</span></a><?php endif; ?>
        <?php if (moduleVisible('treatment_plans',  $__mv)): ?><a href="/treatment"        class="nav-item <?= $activeModule==='treatment_plans'?'active':'' ?>"><i class="bi bi-tools"></i><span>Treatment Plans</span></a><?php endif; ?>
        <?php if (moduleVisible('kris',             $__mv)): ?><a href="/kris"             class="nav-item <?= $activeModule==='kris'?'active':'' ?>"><i class="bi bi-activity"></i><span>KRI Dashboard</span></a><?php endif; ?>
        <?php if (moduleVisible('vendor',           $__mv)): ?><a href="/vendor"           class="nav-item <?= $activeModule==='vendor'?'active':'' ?>"><i class="bi bi-building"></i><span>Vendor Risk</span></a><?php endif; ?>
        <?php if (moduleVisible('vendor_contracts', $__mv)): ?><a href="/vendor/contracts" class="nav-item <?= $activeModule==='vendor_contracts'?'active':'' ?>"><i class="bi bi-file-earmark-check-fill"></i><span>Contracts</span></a><?php endif; ?>
        <?php if (moduleVisible('assets',           $__mv)): ?><a href="/assets"           class="nav-item <?= $activeModule==='assets'?'active':'' ?>"><i class="bi bi-server"></i><span>Asset Inventory</span></a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Analytics -->
    <?php $__anaVisible = array_filter(['metrics','documents','report','report_board','export','calendar','dashboards'], fn($m) => moduleVisible($m, $__mv));
    if ($__anaVisible): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="analytics">
        <span>Analytics</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-analytics">
        <?php if (moduleVisible('metrics',      $__mv)): ?><a href="/metrics"      class="nav-item <?= $activeModule==='metrics'?'active':'' ?>"><i class="bi bi-graph-up-arrow"></i><span>Metrics &amp; Trends</span></a><?php endif; ?>
        <?php if (moduleVisible('documents',    $__mv)): ?><a href="/documents"    class="nav-item <?= $activeModule==='documents'?'active':'' ?>"><i class="bi bi-folder2-open"></i><span>Documents</span></a><?php endif; ?>
        <?php if (moduleVisible('report',       $__mv)): ?><a href="/report"       class="nav-item <?= $activeModule==='report'?'active':'' ?>"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Reports</span></a><?php endif; ?>
        <?php if (moduleVisible('report_board', $__mv)): ?><a href="/report/board" class="nav-item <?= $activeModule==='report_board'?'active':'' ?>"><i class="bi bi-tv-fill"></i><span>Board Dashboard</span></a><?php endif; ?>
        <?php if (moduleVisible('export',       $__mv)): ?><a href="/export"       class="nav-item <?= $activeModule==='export'?'active':'' ?>"><i class="bi bi-download"></i><span>Export</span></a><?php endif; ?>
        <?php if (moduleVisible('calendar',     $__mv)): ?><a href="/calendar"     class="nav-item <?= $activeModule==='calendar'?'active':'' ?>"><i class="bi bi-calendar3"></i><span>Calendar</span></a><?php endif; ?>
        <?php if (moduleVisible('dashboards',   $__mv)): ?><a href="/dashboards"   class="nav-item <?= $activeModule==='dashboards'?'active':'' ?>"><i class="bi bi-layout-wtf"></i><span>Custom Dashboards</span></a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- GRC Tools -->
    <?php $__toolsVisible = array_filter(['automation','cui'], fn($m) => moduleVisible($m, $__mv));
    if ($__toolsVisible): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="tools">
        <span>GRC Tools</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-tools">
        <?php if (moduleVisible('automation', $__mv)): ?><a href="/automation" class="nav-item <?= $activeModule==='automation'?'active':'' ?>"><i class="bi bi-lightning-fill"></i><span>Automation Rules</span></a><?php endif; ?>
        <?php if (moduleVisible('cui',        $__mv)): ?><a href="/cui"        class="nav-item <?= $activeModule==='cui'?'active':'' ?>"><i class="bi bi-lock-fill"></i><span>CUI Inventory</span></a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Resources -->
    <?php $__resVisible = array_filter(['search','docs'], fn($m) => moduleVisible($m, $__mv));
    if ($__resVisible): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="resources">
        <span>Resources</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-resources">
        <?php if (moduleVisible('search', $__mv)): ?><a href="/search" class="nav-item <?= $activeModule==='search'?'active':'' ?>"><i class="bi bi-search"></i><span>Search</span></a><?php endif; ?>
        <?php if (moduleVisible('docs',   $__mv)): ?><a href="/docs"   class="nav-item <?= $activeModule==='docs'?'active':'' ?>"><i class="bi bi-book-fill"></i><span>Documentation</span></a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Administration -->
    <?php if (Auth::can('admin') || Auth::role() === 'admin'): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="administration">
        <span>Administration</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-administration">
        <a href="/admin"                   class="nav-item <?= $activeModule==='admin'?'active':'' ?>"><i class="bi bi-speedometer2"></i><span>Overview</span></a>
        <a href="/admin/users"             class="nav-item <?= $activeModule==='admin_users'?'active':'' ?>"><i class="bi bi-people-fill"></i><span>Users</span></a>
        <a href="/admin/permissions"       class="nav-item <?= $activeModule==='admin_permissions'?'active':'' ?>"><i class="bi bi-shield-lock-fill"></i><span>Permissions</span></a>
        <a href="/admin/workflows"         class="nav-item <?= $activeModule==='admin_workflows'?'active':'' ?>"><i class="bi bi-diagram-3-fill"></i><span>Workflows</span></a>
        <a href="/admin/approval-templates" class="nav-item <?= $activeModule==='admin_approval_templates'?'active':'' ?>"><i class="bi bi-list-check"></i><span>Approval Templates</span></a>
        <a href="/admin/risk-matrix"       class="nav-item <?= $activeModule==='admin_risk_matrix'?'active':'' ?>"><i class="bi bi-sliders"></i><span>Risk Matrix</span></a>
        <a href="/admin/risk-appetite"     class="nav-item <?= $activeModule==='admin_risk_appetite'?'active':'' ?>"><i class="bi bi-speedometer"></i><span>Risk Appetite</span></a>
        <a href="/admin/alerts"            class="nav-item <?= $activeModule==='admin_alerts'?'active':'' ?>"><i class="bi bi-bell-fill"></i><span>Alerts</span></a>
        <a href="/admin/api-keys"          class="nav-item <?= $activeModule==='admin_api_keys'?'active':'' ?>"><i class="bi bi-key-fill"></i><span>API Keys</span></a>
        <a href="/admin/webhooks"          class="nav-item <?= $activeModule==='admin_webhooks'?'active':'' ?>"><i class="bi bi-broadcast"></i><span>Webhooks</span></a>
        <a href="/admin/email"             class="nav-item <?= $activeModule==='admin_email'?'active':'' ?>"><i class="bi bi-envelope-fill"></i><span>Email Settings</span></a>
        <a href="/admin/settings"          class="nav-item <?= $activeModule==='admin_settings'?'active':'' ?>"><i class="bi bi-gear-fill"></i><span>System Settings</span></a>
        <a href="/admin/module-visibility" class="nav-item <?= $activeModule==='admin_module_visibility'?'active':'' ?>"><i class="bi bi-grid-fill"></i><span>Module Visibility</span></a>
        <a href="/admin/settings/sso"      class="nav-item <?= $activeModule==='admin_sso'?'active':'' ?>"><i class="bi bi-person-badge-fill"></i><span>SSO / OIDC</span></a>
        <a href="/admin/security-policy"   class="nav-item <?= $activeModule==='admin_security_policy'?'active':'' ?>"><i class="bi bi-shield-fill-check"></i><span>Security Policy</span></a>
        <a href="/admin/logs"              class="nav-item <?= $activeModule==='admin_logs'?'active':'' ?>"><i class="bi bi-journal-text"></i><span>Activity Logs</span></a>
        <a href="/admin/sessions"          class="nav-item <?= $activeModule==='admin_sessions'?'active':'' ?>"><i class="bi bi-people-fill"></i><span>Sessions</span></a>
        <a href="/admin/storage"           class="nav-item <?= $activeModule==='admin_storage'?'active':'' ?>"><i class="bi bi-hdd-fill"></i><span>Storage</span></a>
        <a href="/admin/retention"         class="nav-item <?= $activeModule==='admin_retention'?'active':'' ?>"><i class="bi bi-clock-history"></i><span>Data Retention</span></a>
        <a href="/admin/custom-fields"     class="nav-item <?= $activeModule==='admin_custom_fields'?'active':'' ?>"><i class="bi bi-input-cursor-text"></i><span>Custom Fields</span></a>
        <a href="/admin/tags"              class="nav-item <?= $activeModule==='admin_tags'?'active':'' ?>"><i class="bi bi-tags-fill"></i><span>Tags</span></a>
        <a href="/admin/sla-policy"        class="nav-item <?= $activeModule==='admin_sla'?'active':'' ?>"><i class="bi bi-stopwatch-fill"></i><span>SLA Policy</span></a>
        <a href="/policy/attestations"     class="nav-item <?= $activeModule==='policy_attestations'?'active':'' ?>"><i class="bi bi-pen-fill"></i><span>Attestations</span></a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Account -->
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="account">
        <span>Account</span><i class="bi bi-chevron-down nav-acc-chevron"></i>
      </button>
      <div class="nav-acc-body" id="nav-acc-account">
        <a href="/approvals" class="nav-item <?= $activeModule === 'approvals' ? 'active' : '' ?>">
          <i class="bi bi-check2-square"></i><span>Approvals</span>
          <?php
            $pendingApprovals = Database::fetchOne(
              "SELECT COUNT(*) as c FROM approval_requests ar
               JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.step_number = ar.current_step
               WHERE ar.status = 'pending' AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')",
              [$u['id'], $u['role'], $u['role']]
            );
            if (($pendingApprovals['c'] ?? 0) > 0):
          ?>
            <span class="nav-badge"><?= (int)$pendingApprovals['c'] ?></span>
          <?php endif; ?>
        </a>
        <a href="/profile/notifications" class="nav-item <?= $activeModule==='profile_notifications'?'active':'' ?>"><i class="bi bi-bell-fill"></i><span>Notifications</span></a>
        <a href="/profile/edit"          class="nav-item <?= $activeModule==='profile_edit'?'active':'' ?>"><i class="bi bi-person-fill-gear"></i><span>Edit Profile</span></a>
        <a href="/my-attestations"       class="nav-item <?= $activeModule==='my_attestations'?'active':'' ?>"><i class="bi bi-pen-fill"></i><span>My Attestations</span></a>
        <a href="/mfa/setup"             class="nav-item <?= $activeModule==='profile'?'active':'' ?>"><i class="bi bi-shield-lock-fill"></i><span>Two-Factor Auth</span></a>
        <a href="/mfa/backup-codes"      class="nav-item <?= $activeModule==='mfa_backup'?'active':'' ?>"><i class="bi bi-key-fill"></i><span>Backup Codes</span></a>
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
        <button type="submit" class="btn-logout" title="Logout" aria-label="Log out" style="background:none;border:none;cursor:pointer;padding:0"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button>
      </form>
    </div>
  </div>
</aside>
<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<!-- Main content -->
<div class="main-content" id="mainContent">
  <header class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" aria-label="Open menu" type="button">
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
      <button id="themeToggle" class="theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode" type="button">
        <i class="bi bi-moon-fill" id="themeIcon" aria-hidden="true"></i>
      </button>
      <div class="alert-bell" id="alertBell" role="button" tabindex="0" aria-label="Notifications" aria-haspopup="true">
        <i class="bi bi-bell<?= $unreadAlerts > 0 ? '-fill' : '' ?>" aria-hidden="true"></i>
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
      <button id="alertPanelClose" aria-label="Close notifications"><i class="bi bi-x-lg"></i></button>
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
            <button class="mark-read-btn" data-alert-id="<?= (int)$al['id'] ?>" aria-label="Mark as read"><i class="bi bi-check"></i></button>
          <?php endif; ?>
        </div>
      <?php endforeach; else: ?>
        <div class="alert-empty"><i class="bi bi-check-circle-fill"></i><p>All caught up!</p></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="alert-overlay" id="alertOverlay"></div>

  <main class="page-content">
    <?= $content ?? '' ?>
  </main>
</div>

<script src="/public/vendor/chart.js/chart.umd.js" integrity="sha384-tgbB5AKnszdcfwcZtTfuhR3Ko1XZdlDfsLtkxiiAZiVkkXCkFmp+FQFh+V/UTo54" crossorigin="anonymous" nonce="<?= Security::nonce() ?>"></script>
<script nonce="<?= Security::nonce() ?>" src="/public/js/app.js?v=<?= $__jsVer ?>"></script>
</body>
</html>
