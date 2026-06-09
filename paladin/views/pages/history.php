<?php
$pageTitle    = 'Version History';
$activeModule = 'spaces';
$breadcrumbs  = [['Spaces', '/spaces'], [$page['title'], '/pages/' . (int)$page['id']], ['History', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Version History</h1><p class="page-subtitle"><?= Security::h($page['title']) ?> — <?= count($versions) ?> revisions</p></div>
  <div class="page-actions"><?php if (count($versions) > 1): ?><a href="/pages/<?= (int)$page['id'] ?>/diff" class="btn btn-primary"><i class="bi bi-arrow-left-right"></i> Compare</a><?php endif; ?><a href="/pages/<?= (int)$page['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to page</a></div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th style="width:80px">Version</th><th>Title</th><th>Change Note</th><th>Editor</th><th>When</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($versions as $v): ?>
      <tr>
        <td><span class="chip">v<?= (int)$v['version'] ?></span><?= (int)$v['version'] === (int)$page['current_version'] ? ' <span class="badge badge-green">current</span>' : '' ?></td>
        <td><?= Security::h($v['title']) ?></td>
        <td class="form-hint"><?= Security::h($v['change_note'] ?: '—') ?></td>
        <td><?= Security::h($v['editor'] ?: '—') ?></td>
        <td class="form-hint"><?= View::fmtDate($v['created_at'], 'M j, Y g:ia') ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ((int)$v['version'] !== (int)$page['current_version']): ?>
          <a href="/pages/<?= (int)$page['id'] ?>/diff?from=<?= (int)$v['version'] ?>&to=<?= (int)$page['current_version'] ?>" class="btn btn-sm btn-ghost" title="Compare to current"><i class="bi bi-arrow-left-right"></i></a>
          <?php endif; ?>
          <?php if (Auth::can('page.edit') && (int)$v['version'] !== (int)$page['current_version']): ?>
          <form method="POST" action="/pages/<?= (int)$page['id'] ?>/restore/<?= (int)$v['version'] ?>" style="display:inline;margin:0" data-confirm="Restore this version as a new revision?"><?= Security::csrfField() ?><button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
