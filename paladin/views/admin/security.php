<?php
$pageTitle    = 'Security Overview';
$activeModule = 'admin_security';
$breadcrumbs  = [['Admin', '/admin'], ['Security', null]];
ob_start();
$policyLabel = ['off' => 'Optional', 'admins' => 'Required for admins', 'all' => 'Required for all'][$mfaPolicy] ?? 'Optional';
?>
<div class="page-header">
  <div><h1 class="page-title">Security Overview</h1><p class="page-subtitle">Authentication posture and recent privileged activity.</p></div>
  <div class="page-actions"><a href="/admin/logs?action=login_failed" class="btn btn-ghost"><i class="bi bi-journal-text"></i> Failed logins</a></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-shield-exclamation"></i></div><div><div class="stat-value"><?= (int)$failed24 ?></div><div class="stat-label">Failed logins (24h)</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-shield-exclamation"></i></div><div><div class="stat-value"><?= (int)$failed7d ?></div><div class="stat-label">Failed logins (7d)</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-people-fill"></i></div><div><div class="stat-value"><?= (int)$activeSessions ?></div><div class="stat-label">Active sessions (30m)</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-shield-lock-fill"></i></div><div><div class="stat-value"><?= (int)$mfaStats['with_mfa'] ?>/<?= (int)$mfaStats['total'] ?></div><div class="stat-label">Users with 2FA · policy: <?= Security::h($policyLabel) ?></div></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px">
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-shield-exclamation"></i> Top failed-login sources (7d)</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table" style="margin:0"><thead><tr><th>IP address</th><th>Attempts</th><th>Last seen</th></tr></thead><tbody>
        <?php foreach ($topFailedIps as $r): ?>
          <tr><td class="form-hint"><?= Security::h($r['ip_address']) ?></td><td><span class="badge <?= (int)$r['c'] >= 10 ? 'badge-red' : 'badge-gray' ?>"><?= (int)$r['c'] ?></span></td><td class="form-hint"><?= Security::h(View::timeAgo($r['last_at'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$topFailedIps): ?><tr><td colspan="3" class="empty-row"><div class="empty-state-sm"><i class="bi bi-shield-check"></i><p>No failed logins in the last 7 days.</p></div></td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>

  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-person-fill-exclamation"></i> Admins without 2FA (<?= (int)$mfaStats['admins_without_mfa'] ?>)</span></div></div>
    <div class="card-body">
      <?php if ($adminsNoMfa): ?>
        <div class="banner warn" style="margin:0 0 10px"><i class="bi bi-exclamation-triangle-fill"></i><div class="banner-body">These administrators have not enabled two-factor authentication.</div></div>
        <?php foreach ($adminsNoMfa as $a): ?>
          <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid var(--border-light)"><?= View::avatar($a['name'], 'sm') ?><div style="flex:1"><div style="font-size:.86rem;font-weight:600"><?= Security::h($a['name']) ?></div><div class="form-hint"><?= Security::h($a['email']) ?></div></div></div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state-sm"><i class="bi bi-shield-check"></i><p>All active admins have 2FA enabled.</p></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card" style="margin-top:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-clock-history"></i> Recent privileged actions</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0"><thead><tr><th>When</th><th>User</th><th>Action</th><th>Target</th></tr></thead><tbody>
      <?php foreach ($privileged as $p): ?>
        <tr><td class="form-hint"><?= Security::h(View::fmtDate($p['created_at'], 'M j, g:ia')) ?></td><td><?= Security::h($p['user_name'] ?: 'System') ?></td><td><span class="chip"><?= Security::h(str_replace('_', ' ', $p['action'])) ?></span></td><td class="form-hint"><?= Security::h(trim(($p['entity_type'] ?? '') . ' ' . ($p['entity_id'] ?? ''))) ?: '—' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$privileged): ?><tr><td colspan="4" class="empty-row"><div class="empty-state-sm"><i class="bi bi-clock-history"></i><p>No recent privileged actions.</p></div></td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
