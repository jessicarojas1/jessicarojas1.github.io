<?php
$pageTitle    = 'Approval Backlog';
$activeModule = 'reports';
$breadcrumbs  = [['Reports', '/reports'], ['Approval Backlog', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Approval Backlog</h1><p class="page-subtitle">Pending approval requests ordered by age, oldest first</p></div>
  <div class="page-actions">
    <?php if (Auth::can('report.export')): ?><a href="/reports/approval-backlog?format=csv" class="btn btn-ghost"><i class="bi bi-download"></i> Export CSV</a><?php endif; ?>
  </div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>Title</th><th>Entity</th><th>Requester</th><th>Step</th><th>Age</th><th>Due</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php $age = (int)($r['age_days'] ?? 0); ?>
      <tr>
        <td><a href="/approvals/<?= (int)$r['id'] ?>" class="table-link"><?= Security::h($r['title']) ?></a></td>
        <td class="form-hint"><?php if (!empty($r['entity_type'])): ?><span class="chip"><?= Security::h($r['entity_type']) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></span><?php else: ?>—<?php endif; ?></td>
        <td class="form-hint"><?= Security::h($r['requester_name'] ?: '—') ?></td>
        <td><span class="chip">Step <?= (int)$r['current_step'] ?></span></td>
        <td><span class="<?= $age > 7 ? 'badge badge-overdue' : '' ?>"><?= $age ?> day<?= $age === 1 ? '' : 's' ?></span></td>
        <td class="form-hint"><?php if (!empty($r['due_at'])): ?><span class="<?= strtotime($r['due_at']) < time() ? 'badge badge-overdue' : '' ?>"><?= View::fmtDate($r['due_at']) ?></span><?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="6"><div class="empty-state"><i class="bi bi-check-circle"></i><p>No pending approvals. The backlog is clear.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
