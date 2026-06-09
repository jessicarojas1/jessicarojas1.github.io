<?php
$pageTitle    = 'Acknowledgement Coverage';
$activeModule = 'reports';
$breadcrumbs  = [['Reports', '/reports'], ['Acknowledgements', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Acknowledgement Coverage</h1><p class="page-subtitle">Read-receipt completeness for published documents requiring acknowledgement</p></div>
  <div class="page-actions">
    <?php if (Auth::can('report.export')): ?><a href="/reports/acknowledgements?format=csv" class="btn btn-ghost"><i class="bi bi-download"></i> Export CSV</a><?php endif; ?>
  </div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>Code</th><th>Title</th><th>Rev</th><th>Acknowledged</th><th>Coverage</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        $ack = (int)($r['ack_count'] ?? 0);
        $pct = $totalUsers > 0 ? (int)round($ack / $totalUsers * 100) : 0;
        $badge = $pct >= 100 ? 'badge-green' : ($pct >= 50 ? 'badge-warning' : 'badge-red');
      ?>
      <tr>
        <td><span class="chip"><?= Security::h($r['document_code']) ?></span></td>
        <td><a href="/documents/<?= (int)$r['id'] ?>" class="table-link"><?= Security::h($r['title']) ?></a></td>
        <td><?= Security::h($r['revision']) ?></td>
        <td class="form-hint"><?= $ack ?> / <?= (int)$totalUsers ?></td>
        <td><span class="badge <?= $badge ?>"><?= $pct ?>%</span></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="5"><div class="empty-state"><i class="bi bi-patch-check"></i><p>No published documents currently require acknowledgement.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
