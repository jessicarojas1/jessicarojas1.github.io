<?php
$pageTitle    = 'Labels';
$activeModule = 'labels';
$breadcrumbs  = [['Labels', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title"><i class="bi bi-tags-fill"></i> Labels</h1><p class="page-subtitle">Browse content by label across the library</p></div>
</div>

<?php if ($labels): ?>
<div class="card"><div class="card-body">
  <div style="display:flex;flex-wrap:wrap;gap:10px">
    <?php foreach ($labels as $l): ?>
      <a href="/labels/<?= (int)$l['id'] ?>" class="chip" style="border-left:3px solid <?= Security::h($l['color']) ?>;text-decoration:none;font-size:.85rem;padding:6px 12px">
        <?= Security::h($l['name']) ?> <span class="badge badge-gray" style="margin-left:4px"><?= (int)$l['cnt'] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div></div>
<?php else: ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-tags"></i><p>No labels yet. Add labels to pages, or manage the vocabulary in <a href="/admin/tags">Admin → Tags</a>.</p></div></div></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
