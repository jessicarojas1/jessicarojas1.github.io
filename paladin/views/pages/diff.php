<?php
$pageTitle    = 'Compare Revisions';
$activeModule = 'spaces';
$breadcrumbs  = [['Spaces', '/spaces'], [$page['title'], '/pages/' . (int)$page['id']], ['History', '/pages/' . (int)$page['id'] . '/history'], ['Compare', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Compare Revisions</h1>
    <p class="page-subtitle"><?= Security::h($page['title']) ?> — v<?= (int)$from['version'] ?> → v<?= (int)$to['version'] ?>
      · <span style="color:var(--success)">+<?= (int)$stats['added'] ?></span> <span style="color:var(--danger)">−<?= (int)$stats['removed'] ?></span></p></div>
  <div class="page-actions"><a href="/pages/<?= (int)$page['id'] ?>/history" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> History</a></div>
</div>

<div class="card" style="margin-bottom:18px"><div class="card-body">
  <form method="GET" action="/pages/<?= (int)$page['id'] ?>/diff" class="form-row" style="align-items:flex-end;gap:12px;margin:0">
    <div class="form-group" style="margin:0"><label class="form-label">From (older)</label><select name="from" class="form-select"><?php foreach ($versions as $v): ?><option value="<?= (int)$v['version'] ?>" <?= (int)$v['version']===(int)$from['version']?'selected':'' ?>>v<?= (int)$v['version'] ?> · <?= View::fmtDate($v['created_at'],'M j, Y g:ia') ?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0"><label class="form-label">To (newer)</label><select name="to" class="form-select"><?php foreach ($versions as $v): ?><option value="<?= (int)$v['version'] ?>" <?= (int)$v['version']===(int)$to['version']?'selected':'' ?>>v<?= (int)$v['version'] ?> · <?= View::fmtDate($v['created_at'],'M j, Y g:ia') ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-left-right"></i> Compare</button>
  </form>
</div></div>

<?php if ($titleDiff): ?>
<div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title">Title</span></div></div><div class="card-body">
  <div class="diff-line diff-del"><span class="diff-gutter">−</span><?= Security::h($from['title']) ?></div>
  <div class="diff-line diff-add"><span class="diff-gutter">+</span><?= Security::h($to['title']) ?></div>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-file-text"></i> Body changes</span></div></div>
  <div class="card-body diff-body">
    <?php if (count($bodyDiff) && $stats['added'] + $stats['removed'] === 0): ?>
      <div class="empty-state-sm">No textual changes between these revisions.</div>
    <?php endif; ?>
    <?php foreach ($bodyDiff as $d):
      $cls = $d['type']==='add' ? 'diff-add' : ($d['type']==='del' ? 'diff-del' : 'diff-eq');
      $g = $d['type']==='add' ? '+' : ($d['type']==='del' ? '−' : '');
    ?>
      <div class="diff-line <?= $cls ?>"><span class="diff-gutter"><?= $g ?></span><?= Security::h($d['text']) ?></div>
    <?php endforeach; ?>
    <?php if (!$bodyDiff): ?><div class="empty-state-sm">Both revisions are empty.</div><?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
