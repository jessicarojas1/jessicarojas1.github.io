<?php
$pageTitle    = 'Mail Outbox';
$activeModule = 'admin_outbox';
$breadcrumbs  = [['Admin', '/admin'], ['Mail Outbox', null]];
ob_start();
$badge = ['queued' => 'badge-gray', 'sent' => 'badge-green', 'failed' => 'badge-red'];
?>
<div class="page-header">
  <div><h1 class="page-title">Mail Outbox</h1>
    <p class="page-subtitle">Every digest/notification the app generated · transport: <strong><?= Security::h($transport) ?></strong><?php if ($transport === 'queued'): ?> <span class="form-hint">(no SMTP configured — messages are recorded but not delivered)</span><?php endif; ?></p></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-envelope"></i></div><div><div class="stat-value"><?= (int)$counts['total'] ?></div><div class="stat-label">Total</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(100,116,139,.12);color:var(--secondary)"><i class="bi bi-hourglass"></i></div><div><div class="stat-value"><?= (int)$counts['queued'] ?></div><div class="stat-label">Queued</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= (int)$counts['sent'] ?></div><div class="stat-label">Sent</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-exclamation-octagon"></i></div><div><div class="stat-value"><?= (int)$counts['failed'] ?></div><div class="stat-label">Failed</div></div></div>
</div>

<div class="card" style="margin-top:18px"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>To</th><th>Subject</th><th>Transport</th><th>Status</th><th>Created</th><th>Sent</th></tr></thead>
    <tbody>
    <?php foreach ($messages as $m): ?>
      <tr>
        <td class="form-hint"><?= Security::h($m['to_email']) ?></td>
        <td><?= Security::h($m['subject']) ?><?php if ($m['error']): ?><div class="form-hint" style="color:var(--danger)"><?= Security::h($m['error']) ?></div><?php endif; ?></td>
        <td><span class="chip"><?= Security::h($m['transport']) ?></span></td>
        <td><span class="badge <?= $badge[$m['status']] ?? 'badge-gray' ?>"><?= Security::h(ucfirst($m['status'])) ?></span></td>
        <td class="form-hint"><?= View::fmtDate($m['created_at'], 'M j, g:ia') ?></td>
        <td class="form-hint"><?= $m['sent_at'] ? View::fmtDate($m['sent_at'], 'M j, g:ia') : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$messages): ?>
      <tr><td colspan="6"><div class="empty-state"><i class="bi bi-envelope-open"></i><p>No mail has been generated yet. Digests are produced by <code>cli/send_digests.php</code> (run via cron).</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
