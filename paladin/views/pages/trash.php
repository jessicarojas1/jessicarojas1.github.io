<?php
$pageTitle    = 'Trash — ' . $space['name'];
$activeModule = 'spaces';
$breadcrumbs  = [['Spaces', '/spaces'], [$space['space_key'], '/spaces/' . (int)$space['id']], ['Trash', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title"><i class="bi bi-trash"></i> Trash</h1><p class="page-subtitle">Deleted pages in <a href="/spaces/<?= (int)$space['id'] ?>"><?= Security::h($space['name']) ?></a>. Restore them or delete permanently.</p></div>
  <div class="page-actions"><a href="/spaces/<?= (int)$space['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to space</a></div>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0">
      <thead><tr><th>Page</th><th>Status</th><th>Deleted</th><th>By</th><th style="width:200px"></th></tr></thead>
      <tbody>
      <?php foreach ($pages as $p): ?>
        <tr>
          <td><i class="bi bi-file-earmark-text"></i> <?= Security::h($p['title']) ?></td>
          <td><?= View::statusBadge($p['status']) ?></td>
          <td class="form-hint"><?= Security::h(View::timeAgo($p['deleted_at'])) ?></td>
          <td class="form-hint"><?= Security::h($p['deleted_by_name'] ?? '—') ?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if (Auth::can('page.delete')): ?>
            <form method="POST" action="/pages/<?= (int)$p['id'] ?>/restore-trash" style="display:inline;margin:0"><?= Security::csrfField() ?><button class="btn btn-sm" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
            <form method="POST" action="/pages/<?= (int)$p['id'] ?>/purge" style="display:inline;margin:0" data-confirm="Permanently delete &quot;<?= Security::h($p['title']) ?>&quot;? This cannot be undone."><?= Security::csrfField() ?><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i> Delete forever</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$pages): ?>
        <tr><td colspan="5" class="empty-row"><div class="empty-state-sm"><i class="bi bi-trash"></i><p>Trash is empty.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
