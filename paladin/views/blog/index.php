<?php
$pageTitle    = $title;
$activeModule = 'blog';
$breadcrumbs  = $spaceFilter
    ? [['Spaces', '/spaces'], [$spaceFilter['name'], '/spaces/' . (int)$spaceFilter['id']], ['Blog', null]]
    : [['Blog', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title"><i class="bi bi-newspaper"></i> <?= Security::h($spaceFilter ? $spaceFilter['name'] . ' Blog' : 'Blog') ?></h1><p class="page-subtitle">News &amp; announcements</p></div>
  <div class="page-actions">
    <a href="/blog/rss<?= $spaceFilter ? '?space=' . (int)$spaceFilter['id'] : '' ?>" class="btn btn-ghost" target="_blank" rel="noopener"><i class="bi bi-rss-fill"></i> RSS</a>
    <?php if (Auth::can('page.create')): ?><a href="/blog/create<?= $spaceFilter ? '?space=' . (int)$spaceFilter['id'] : '' ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Write Post</a><?php endif; ?>
  </div>
</div>

<?php if ($posts): ?>
<div style="max-width:760px">
  <?php foreach ($posts as $p): ?>
  <div class="card" style="margin-bottom:18px">
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <?= View::avatar($p['author_name'], 'sm') ?>
        <div class="form-hint"><?= Security::h($p['author_name'] ?: '—') ?> · <?= View::fmtDate($p['published_at'] ?: $p['created_at'], 'M j, Y') ?> · in <a href="/spaces/<?= (int)$p['space_id'] ?>"><?= Security::h($p['space_key']) ?></a></div>
        <?php if ($p['status'] !== 'published'): ?><?= View::statusBadge('draft') ?><?php endif; ?>
      </div>
      <h2 style="margin:0 0 8px"><a href="/blog/<?= (int)$p['id'] ?>" class="table-link" style="font-size:1.3rem"><?= Security::h($p['title']) ?></a></h2>
      <div class="prose" style="max-height:140px;overflow:hidden;mask-image:linear-gradient(to bottom,#000 60%,transparent)"><?= $p['body'] ?: '' ?></div>
      <a href="/blog/<?= (int)$p['id'] ?>" class="btn btn-sm btn-ghost" style="margin-top:8px">Read more →</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-newspaper"></i><p>No blog posts yet.</p><?php if (Auth::can('page.create')): ?><a href="/blog/create<?= $spaceFilter ? '?space=' . (int)$spaceFilter['id'] : '' ?>" class="btn btn-sm btn-primary">Write the first post</a><?php endif; ?></div></div></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
