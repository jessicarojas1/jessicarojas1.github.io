<?php
$pageTitle    = 'Webhook Dead-Letters';
$activeModule = 'admin_webhooks';
$breadcrumbs  = [['Administration', '/admin'], ['Webhooks', '/admin/webhooks'], ['Dead-letters', null]];
ob_start();
$truthy = fn($v) => in_array(strtolower((string)$v), ['1', 't', 'true', 'yes', 'on'], true);
?>
<div class="page-header">
  <div><h1 class="page-title">Dead-letter queue</h1>
    <p class="page-subtitle">Deliveries that exhausted their automatic retry budget. Replay one once its endpoint is healthy again.</p></div>
  <div class="page-actions"><a href="/admin/webhooks" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to webhooks</a></div>
</div>

<div class="card" style="margin-top:18px"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>Webhook</th><th>Event</th><th>HTTP</th><th>Attempts</th><th>Error</th><th>Failed</th><th style="width:120px"></th></tr></thead>
    <tbody>
    <?php foreach ($deliveries as $d):
      $replayable = $truthy($d['replayable']) && $truthy($d['is_active']); ?>
      <tr>
        <td><a href="/admin/webhooks/<?= (int)$d['webhook_id'] ?>/deliveries"><?= Security::h($d['hook_name']) ?></a>
          <?php if (!$truthy($d['is_active'])): ?><br><span class="badge badge-gray">Paused</span><?php endif; ?>
          <br><span class="form-hint" style="word-break:break-all"><?= Security::h($d['hook_url']) ?></span></td>
        <td><span class="chip"><?= Security::h($d['event']) ?></span></td>
        <td class="form-hint"><?= $d['status_code'] !== null ? (int)$d['status_code'] : '—' ?></td>
        <td class="form-hint"><?= (int)($d['attempts'] ?? 1) ?></td>
        <td class="form-hint" style="max-width:280px;word-break:break-word"><?= $d['error'] ? Security::h($d['error']) : '—' ?></td>
        <td class="form-hint"><?= Security::h(View::fmtDate($d['created_at'], 'M j, g:ia')) ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($replayable): ?>
            <form method="POST" action="/admin/webhook-deliveries/<?= (int)$d['id'] ?>/replay" style="display:inline;margin:0">
              <?= Security::csrfField() ?>
              <button class="btn btn-sm btn-primary" type="submit" title="Replay this delivery"><i class="bi bi-arrow-repeat"></i> Replay</button>
            </form>
          <?php else: ?>
            <span class="form-hint" title="<?= $truthy($d['is_active']) ? 'No stored payload to replay' : 'Endpoint is paused' ?>">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$deliveries): ?>
      <tr><td colspan="7" class="empty-row"><div class="empty-state-sm"><i class="bi bi-check2-circle"></i><p>No dead-lettered deliveries. Everything that could be delivered, was.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
