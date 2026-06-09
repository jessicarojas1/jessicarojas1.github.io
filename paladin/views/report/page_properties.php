<?php
$pageTitle    = 'Page Properties Report';
$activeModule = 'reports';
$breadcrumbs  = [['Reports', '/reports'], ['Page Properties', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Page Properties Report</h1><p class="page-subtitle">Aggregate the properties of every page sharing a label.</p></div>
  <div class="page-actions">
    <form method="GET" action="/reports/page-properties" style="margin:0">
      <select name="label" class="form-select" data-auto-submit style="min-width:200px">
        <?php if (!$labels): ?><option value="">No labelled pages with properties</option><?php endif; ?>
        <?php foreach ($labels as $l): ?><option value="<?= (int)$l['id'] ?>" <?= ($label && (int)$label['id']===(int)$l['id'])?'selected':'' ?>><?= Security::h($l['name']) ?> (<?= (int)$l['pages'] ?>)</option><?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0;overflow:auto">
    <?php if (!$label || !$rows): ?>
      <div class="empty-state-sm" style="padding:24px"><i class="bi bi-table"></i><p>No pages with properties for this label. Add a <strong>Page Properties</strong> table to pages and label them.</p></div>
    <?php else: ?>
    <table class="table table-hover" style="margin:0">
      <thead><tr><th>Page</th><th>Space</th><?php foreach ($columns as $c): ?><th><?= Security::h($c) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a href="/pages/<?= (int)$r['page']['id'] ?>"><?= Security::h($r['page']['title']) ?></a></td>
            <td class="form-hint"><?= Security::h($r['page']['space_name'] ?? '') ?></td>
            <?php foreach ($columns as $c): ?><td><?= Security::h($r['props'][$c] ?? '') ?></td><?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
