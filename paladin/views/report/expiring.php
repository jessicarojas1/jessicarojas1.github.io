<?php
$pageTitle    = 'Expiring Documents';
$activeModule = 'reports';
$breadcrumbs  = [['Reports', '/reports'], ['Expiring Documents', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Expiring &amp; Overdue Documents</h1><p class="page-subtitle">Published documents past or within 90 days of their review or expiration date</p></div>
  <div class="page-actions">
    <?php if (Auth::can('report.export')): ?><a href="/reports/expiring?format=csv" class="btn btn-ghost"><i class="bi bi-download"></i> Export CSV</a><?php endif; ?>
  </div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <?php $canExtend = Auth::can('document.edit'); ?>
    <thead><tr><th>Code</th><th>Title</th><th>Owner</th><th>Space</th><th>Review Date</th><th>Expiration</th><th>Status</th><?php if ($canExtend): ?><th>Extend review</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><span class="chip"><?= Security::h($r['document_code']) ?></span></td>
        <td><a href="/documents/<?= (int)$r['id'] ?>" class="table-link"><?= Security::h($r['title']) ?></a></td>
        <td class="form-hint"><?= Security::h($r['owner_name'] ?: '—') ?></td>
        <td class="form-hint"><?= Security::h($r['space_key'] ?: '—') ?></td>
        <td><?php if ($r['review_date']): ?><span class="<?= strtotime($r['review_date']) < strtotime('today') ? 'badge badge-overdue' : '' ?>"><?= View::fmtDate($r['review_date']) ?></span><?php else: ?><span class="form-hint">—</span><?php endif; ?></td>
        <td><?php if ($r['expiration_date']): ?><span class="<?= strtotime($r['expiration_date']) < strtotime('today') ? 'badge badge-overdue' : '' ?>"><?= View::fmtDate($r['expiration_date']) ?></span><?php else: ?><span class="form-hint">—</span><?php endif; ?></td>
        <td><?= View::statusBadge($r['status']) ?></td>
        <?php if ($canExtend): ?>
        <td>
          <form method="POST" action="/documents/<?= (int)$r['id'] ?>/extend-review" style="display:flex;gap:6px;margin:0;align-items:center">
            <?= Security::csrfField() ?>
            <select name="months" class="form-control form-control-sm" style="width:auto"><option value="3">+3 mo</option><option value="6">+6 mo</option><option value="12" selected>+12 mo</option><option value="24">+24 mo</option></select>
            <button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-calendar-plus"></i> Extend</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="<?= $canExtend ? 8 : 7 ?>"><div class="empty-state"><i class="bi bi-calendar-check"></i><p>No documents are expiring or overdue. Everything is current.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
