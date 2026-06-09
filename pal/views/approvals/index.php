<?php
$pageTitle    = 'Approvals';
$activeModule = 'approvals';
$breadcrumbs  = [['Approvals', null]];
ob_start();
$renderRow = function (array $a, bool $showRequester = true) {
    echo '<tr><td><a href="/approvals/' . (int)$a['id'] . '" class="table-link">' . Security::h($a['title']) . '</a>';
    if (!empty($a['entity_type'])) echo '<div class="form-hint">' . Security::h(ucfirst($a['entity_type'])) . ($a['entity_id'] ? ' #' . (int)$a['entity_id'] : '') . '</div>';
    echo '</td>';
    if ($showRequester) echo '<td class="form-hint">' . Security::h($a['requester'] ?? '—') . '</td>';
    echo '<td>' . View::statusBadge($a['status']) . '</td>';
    echo '<td class="form-hint">' . (!empty($a['due_at']) ? (strtotime($a['due_at']) < time() && $a['status']==='pending' ? '<span class="badge badge-overdue">' . View::fmtDate($a['due_at']) . '</span>' : View::fmtDate($a['due_at'])) : '—') . '</td></tr>';
};
?>
<div class="page-header">
  <div><h1 class="page-title">Approvals</h1><p class="page-subtitle">Review and decide on items routed to you</p></div>
  <div class="page-actions"><a href="/approvals/start" class="btn btn-primary"><i class="bi bi-send"></i> Start Approval</a></div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-inbox-fill"></i> Awaiting My Decision (<?= count($pending) ?>)</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0"><thead><tr><th>Item</th><th>Requested By</th><th>Status</th><th>Due</th></tr></thead><tbody>
      <?php foreach ($pending as $a) $renderRow($a); ?>
      <?php if (!$pending): ?><tr><td colspan="4"><div class="empty-state"><i class="bi bi-check-circle"></i><p>Nothing awaiting your decision.</p></div></td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-person-up"></i> My Requests (<?= count($mine) ?>)</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0"><thead><tr><th>Item</th><th>Status</th><th>Due</th></tr></thead><tbody>
      <?php foreach ($mine as $a): ?>
        <tr><td><a href="/approvals/<?= (int)$a['id'] ?>" class="table-link"><?= Security::h($a['title']) ?></a></td><td><?= View::statusBadge($a['status']) ?></td><td class="form-hint"><?= View::fmtDate($a['due_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$mine): ?><tr><td colspan="3"><div class="empty-state-sm">You haven't started any approval requests.</div></td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>

<?php if (!empty($all)): ?>
<div class="card">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-collection"></i> All Requests</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0"><thead><tr><th>Item</th><th>Requested By</th><th>Status</th><th>Due</th></tr></thead><tbody>
      <?php foreach ($all as $a) $renderRow($a); ?>
    </tbody></table>
  </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
