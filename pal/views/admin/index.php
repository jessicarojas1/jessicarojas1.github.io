<?php
$pageTitle    = 'Administration';
$activeModule = 'admin';
$breadcrumbs  = [['Administration', '/admin'], ['Overview', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Administration</h1><p class="page-subtitle">Users, permissions, branding, settings and platform security</p></div>
  <div class="page-actions"><a href="/admin/users/create" class="btn btn-primary"><i class="bi bi-person-plus-fill"></i> New User</a></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-people-fill"></i></div><div><div class="stat-value"><?= (int)$stats['users'] ?></div><div class="stat-label">Total Users</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-person-check-fill"></i></div><div><div class="stat-value"><?= (int)$stats['active_users'] ?></div><div class="stat-label">Active Users</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(2,132,199,.12);color:var(--info)"><i class="bi bi-file-earmark-text-fill"></i></div><div><div class="stat-value"><?= (int)$stats['documents'] ?></div><div class="stat-label">Documents</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(99,102,241,.12);color:var(--indigo)"><i class="bi bi-collection-fill"></i></div><div><div class="stat-value"><?= (int)$stats['spaces'] ?></div><div class="stat-label">Spaces</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(139,92,246,.12);color:var(--purple)"><i class="bi bi-diagram-2-fill"></i></div><div><div class="stat-value"><?= (int)$stats['workflows'] ?></div><div class="stat-label">Active Workflows</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(13,148,136,.12);color:var(--info)"><i class="bi bi-journal-text"></i></div><div><div class="stat-value"><?= (int)$stats['audit_events'] ?></div><div class="stat-label">Audit Events</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-hdd-network-fill"></i></div><div><div class="stat-value"><?= (int)$stats['sessions'] ?></div><div class="stat-label">Live Sessions</div></div></div>
</div>

<div class="card" style="margin-top:22px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-grid-1x2-fill"></i> Quick Links</span></div></div>
  <div class="card-body">
    <div class="form-row" style="flex-wrap:wrap;gap:10px">
      <a href="/admin/users" class="btn btn-ghost"><i class="bi bi-people-fill"></i> Users</a>
      <a href="/admin/permissions" class="btn btn-ghost"><i class="bi bi-shield-lock-fill"></i> Permissions</a>
      <a href="/admin/branding" class="btn btn-ghost"><i class="bi bi-palette-fill"></i> Branding</a>
      <a href="/admin/settings" class="btn btn-ghost"><i class="bi bi-gear-fill"></i> Settings</a>
      <a href="/admin/tags" class="btn btn-ghost"><i class="bi bi-tags-fill"></i> Tags</a>
      <a href="/admin/api-keys" class="btn btn-ghost"><i class="bi bi-key-fill"></i> API Keys</a>
      <a href="/admin/logs" class="btn btn-ghost"><i class="bi bi-journal-text"></i> Activity Logs</a>
      <a href="/admin/sessions" class="btn btn-ghost"><i class="bi bi-hdd-network-fill"></i> Sessions</a>
    </div>
  </div>
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
      <div class="meta-item"><div class="meta-label">Secrets</div><div class="meta-value">AES-256-GCM at rest</div></div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
