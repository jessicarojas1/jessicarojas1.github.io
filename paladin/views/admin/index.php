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

<?php
$console = [
  'Configuration' => [
    ['/admin/settings', 'bi-gear-fill', 'General Settings', 'Dates, uploads, password policy, email, storage'],
    ['/admin/tags', 'bi-tags-fill', 'Tags &amp; Labels', 'Manage the shared label taxonomy'],
    ['/templates', 'bi-files', 'Templates &amp; Blueprints', 'Reusable document &amp; page templates'],
    ['/admin/system', 'bi-cpu', 'System Information', 'Environment, extensions, counts, migrations'],
  ],
  'Users &amp; Security' => [
    ['/admin/users', 'bi-people-fill', 'Users', 'Create, edit, activate/deactivate accounts'],
    ['/admin/roles', 'bi-person-badge-fill', 'Roles', 'Built-in &amp; custom role definitions'],
    ['/admin/permissions', 'bi-shield-lock-fill', 'Permissions', 'Granular per-user module × action grants'],
    ['/admin/api-keys', 'bi-key-fill', 'API Keys', 'Programmatic access tokens'],
    ['/admin/sessions', 'bi-hdd-network-fill', 'Sessions', 'Live sessions &amp; revocation'],
    ['/admin/logs', 'bi-journal-text', 'Audit Log', 'Immutable, hash-chained activity trail'],
  ],
  'Look &amp; Feel' => [
    ['/admin/branding', 'bi-palette-fill', 'Branding', 'Logo, display name &amp; accent colour'],
  ],
  'Workflows' => [
    ['/workflows', 'bi-diagram-2-fill', 'Global Workflow Templates', 'Stages, states, transitions &amp; space assignment'],
  ],
];
?>
<?php foreach ($console as $cat => $items): ?>
<div style="margin-top:22px">
  <div style="font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);font-weight:700;margin-bottom:10px"><?= $cat ?></div>
  <div class="lib-grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
    <?php foreach ($items as $it): ?>
      <a href="<?= $it[0] ?>" class="lib-card" style="padding:16px">
        <div style="display:flex;align-items:center;gap:12px">
          <div class="lib-card-icon" style="width:38px;height:38px;font-size:1.1rem;background:var(--primary)"><i class="bi <?= $it[1] ?>"></i></div>
          <div style="min-width:0"><div class="lib-card-title" style="font-size:.95rem"><?= $it[2] ?></div><div class="lib-card-desc" style="font-size:.78rem"><?= $it[3] ?></div></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

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
require PALADIN_ROOT . '/views/layout.php';
