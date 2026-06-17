<?php
$pageTitle    = 'Webhook Deliveries';
$activeModule = 'admin';
$breadcrumbs  = [['Admin', '/admin'], ['Webhooks', '/admin/webhooks'], ['Deliveries', null]];
ob_start();
$truthy = fn($v) => $v === true || $v === 't' || $v === '1' || $v === 1;
?>
<div class="page-header">
  <div><h1 class="page-title">Delivery log</h1>
    <p class="page-subtitle"><?= Security::h($hook['name']) ?> · <span class="form-hint" style="word-break:break-all"><?= Security::h($hook['url']) ?></span></p></div>
  <div class="page-actions"><a href="/admin/webhooks" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to webhooks</a></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-send"></i></div><div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Total</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= (int)$stats['ok'] ?></div><div class="stat-label">Delivered</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-exclamation-octagon"></i></div><div><div class="stat-value"><?= (int)$stats['failed'] ?></div><div class="stat-label">Failed</div></div></div>
</div>

<div class="card" style="margin-top:18px"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>Event</th><th>Result</th><th>HTTP</th><th>Attempts</th><th>Error</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($deliveries as $d):
      $retrying = !$truthy($d['success']) && !empty($d['next_retry_at']); ?>
      <tr>
        <td><span class="chip"><?= Security::h($d['event']) ?></span></td>
        <td><?= $truthy($d['success'])
              ? '<span class="badge badge-green">Delivered</span>'
              : ($retrying ? '<span class="badge badge-warning">Retry pending</span>' : '<span class="badge badge-red">Failed</span>') ?></td>
        <td class="form-hint"><?= $d['status_code'] !== null ? (int)$d['status_code'] : '—' ?></td>
        <td class="form-hint"><?= (int)($d['attempts'] ?? 1) ?><?php if ($retrying): ?> · <span title="Next retry">retry <?= Security::h(View::timeAgo($d['next_retry_at'])) ?></span><?php endif; ?></td>
        <td class="form-hint" style="max-width:280px;word-break:break-word"><?= $d['error'] ? Security::h($d['error']) : '—' ?></td>
        <td class="form-hint"><?= Security::h(View::fmtDate($d['created_at'], 'M j, g:ia')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$deliveries): ?>
      <tr><td colspan="6" class="empty-row"><div class="empty-state-sm"><i class="bi bi-clock-history"></i><p>No deliveries yet. Use the <i class="bi bi-send"></i> test button on the webhooks page to send one.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
