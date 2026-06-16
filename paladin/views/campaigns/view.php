<?php
$pageTitle    = $campaign['title'];
$activeModule = 'campaigns';
$breadcrumbs  = [['Acknowledgement Campaigns', '/campaigns'], [$campaign['title'], null]];
ob_start();
$total = count($targets);
$done  = 0; foreach ($targets as $t) { if (!empty($t['acknowledged_at'])) $done++; }
$pct   = $total > 0 ? (int)round($done / $total * 100) : 0;
$outstanding = $total - $done;
$overdue = $campaign['due_date'] && $campaign['status'] === 'active' && strtotime($campaign['due_date']) < time() && $outstanding > 0;
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($campaign['title']) ?></h1>
    <p class="page-subtitle">
      <span class="chip"><?= Security::h($campaign['document_code']) ?></span>
      <a href="/documents/<?= (int)$campaign['document_id'] ?>"><?= Security::h($campaign['doc_title']) ?></a>
      · rev <?= Security::h($campaign['revision']) ?>
      <?= $campaign['due_date'] ? ' · due ' . Security::h(View::fmtDate($campaign['due_date'])) : '' ?>
      <?= $overdue ? ' <span class="badge badge-red">overdue</span>' : '' ?>
      <?= $campaign['status'] === 'active' ? ' <span class="badge badge-green">Active</span>' : ' <span class="badge badge-gray">Closed</span>' ?>
    </p>
  </div>
  <div class="page-header-actions">
    <a href="/campaigns/<?= (int)$campaign['id'] ?>/export.csv" class="btn btn-light"><i class="bi bi-filetype-csv"></i> Export CSV</a>
    <?php if ($campaign['status'] === 'active' && $outstanding > 0): ?>
      <form method="POST" action="/campaigns/<?= (int)$campaign['id'] ?>/notify" style="display:inline;margin:0"><?= Security::csrfField() ?><button class="btn btn-light" type="submit"><i class="bi bi-bell-fill"></i> Remind outstanding (<?= $outstanding ?>)</button></form>
    <?php endif; ?>
    <?php if ($campaign['status'] === 'active'): ?>
      <form method="POST" action="/campaigns/<?= (int)$campaign['id'] ?>/close" style="display:inline;margin:0" data-confirm="Close this campaign?"><?= Security::csrfField() ?><button class="btn btn-light" type="submit"><i class="bi bi-check2-circle"></i> Close</button></form>
    <?php endif; ?>
    <form method="POST" action="/campaigns/<?= (int)$campaign['id'] ?>/delete" style="display:inline;margin:0" data-confirm="Delete this campaign? Acknowledgement receipts are kept."><?= Security::csrfField() ?><button class="btn btn-danger" type="submit"><i class="bi bi-trash"></i></button></form>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-body">
    <div style="display:flex;align-items:center;gap:16px">
      <div style="flex:1">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px">
          <span class="form-label" style="margin:0">Acknowledged</span>
          <span class="form-hint"><?= $done ?> of <?= $total ?> (<?= $pct ?>%)</span>
        </div>
        <div style="height:12px;border-radius:6px;background:var(--border-light);overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:var(--success)"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-people-fill"></i> Audience (<?= $total ?>)</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0">
      <thead><tr><th>User</th><th>Department</th><th>Status</th><th>Acknowledged</th></tr></thead>
      <tbody>
      <?php foreach ($targets as $t): ?>
        <tr>
          <td><?= Security::h($t['name']) ?><br><span class="form-hint"><?= Security::h($t['email']) ?></span></td>
          <td class="form-hint"><?= Security::h($t['department'] ?? '—') ?></td>
          <td><?= !empty($t['acknowledged_at']) ? '<span class="badge badge-green">Acknowledged</span>' : '<span class="badge badge-amber">Outstanding</span>' ?></td>
          <td class="form-hint"><?= !empty($t['acknowledged_at']) ? Security::h(View::fmtDate($t['acknowledged_at'])) : ($t['notified_at'] ? 'reminded ' . Security::h(View::timeAgo($t['notified_at'])) : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$targets): ?>
        <tr><td colspan="4" class="empty-row"><div class="empty-state-sm"><i class="bi bi-people"></i><p>No targets.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
