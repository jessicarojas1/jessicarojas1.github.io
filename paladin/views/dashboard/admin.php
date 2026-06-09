<?php
$pageTitle    = 'Admin Dashboard';
$activeModule = 'admin';
$breadcrumbs  = [['Administration', '/admin'], ['Platform Health', null]];
ob_start();
$fmtBytes = function (int $b): string {
    $u = ['B','KB','MB','GB','TB']; $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, 1) . ' ' . $u[$i];
};
?>
<div class="page-header">
  <div><h1 class="page-title">Platform Health</h1><p class="page-subtitle">System status, usage and security overview</p></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-people-fill"></i></div><div><div class="stat-value"><?= $stats['users'] ?></div><div class="stat-label">Total Users</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-person-check-fill"></i></div><div><div class="stat-value"><?= $stats['active_users'] ?></div><div class="stat-label">Active Users</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(2,132,199,.12);color:var(--info)"><i class="bi bi-hdd-network-fill"></i></div><div><div class="stat-value"><?= $stats['sessions'] ?></div><div class="stat-label">Live Sessions</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(139,92,246,.12);color:var(--purple)"><i class="bi bi-diagram-2-fill"></i></div><div><div class="stat-value"><?= $stats['workflows'] ?></div><div class="stat-label">Active Workflows</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(99,102,241,.12);color:var(--indigo)"><i class="bi bi-journal-text"></i></div><div><div class="stat-value"><?= $stats['audit_events'] ?></div><div class="stat-label">Audit Events</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-hdd-fill"></i></div><div><div class="stat-value"><?= $fmtBytes($storageBytes) ?></div><div class="stat-label">Storage Used</div></div></div>
</div>

<div class="card" style="margin-top:22px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-shield-check"></i> Security &amp; Compliance Posture</span></div></div>
  <div class="card-body">
    <div class="meta-grid">
      <div class="meta-item"><div class="meta-label">Audit Log</div><div class="meta-value"><i class="bi bi-link-45deg"></i> Hash-chained &amp; immutable</div></div>
      <div class="meta-item"><div class="meta-label">CSP</div><div class="meta-value">Nonce-based, no inline handlers</div></div>
      <div class="meta-item"><div class="meta-label">Sessions</div><div class="meta-value">HttpOnly · SameSite=Strict</div></div>
      <div class="meta-item"><div class="meta-label">Passwords</div><div class="meta-value">Argon2id hashing</div></div>
      <div class="meta-item"><div class="meta-label">CSRF</div><div class="meta-value">Per-request rotating tokens</div></div>
      <div class="meta-item"><div class="meta-label">Transport</div><div class="meta-value">HSTS when behind TLS</div></div>
    </div>
  </div>
</div>

<div class="form-row" style="margin-top:20px">
  <a href="/admin/users" class="btn btn-ghost"><i class="bi bi-people"></i> Manage Users</a>
  <a href="/admin/permissions" class="btn btn-ghost"><i class="bi bi-shield-lock"></i> Permissions</a>
  <a href="/admin/logs" class="btn btn-ghost"><i class="bi bi-journal-text"></i> Activity Logs</a>
  <a href="/admin/sessions" class="btn btn-ghost"><i class="bi bi-hdd-network"></i> Sessions</a>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
