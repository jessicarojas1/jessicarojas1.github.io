<?php
$pageTitle    = 'Label: ' . $label['name'];
$activeModule = 'labels';
$breadcrumbs  = [['Labels', '/labels'], [$label['name'], null]];
ob_start();
$total = array_sum(array_map('count', $groups));
?>
<div class="page-header">
  <div><h1 class="page-title"><span class="chip" style="border-left:3px solid <?= Security::h($label['color']) ?>;font-size:1rem"><?= Security::h($label['name']) ?></span></h1>
    <p class="page-subtitle"><?= $total ?> item<?= $total===1?'':'s' ?> filed under this label</p></div>
  <div class="page-actions"><a href="/labels" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> All labels</a></div>
</div>

<?php if ($groups): foreach ($groups as $groupName => $items): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><?= Security::h($groupName) ?> (<?= count($items) ?>)</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0"><tbody>
      <?php foreach ($items as $it): ?>
        <tr><?php if ($it['meta']): ?><td style="width:110px"><span class="chip"><?= Security::h($it['meta']) ?></span></td><?php else: ?><td style="width:110px"></td><?php endif; ?>
          <td><a href="<?= Security::h($it['url']) ?>" class="table-link"><?= Security::h($it['title']) ?></a></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>
</div>
<?php endforeach; else: ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-tag"></i><p>Nothing is filed under this label yet.</p></div></div></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
