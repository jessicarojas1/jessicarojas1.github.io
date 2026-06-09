<?php
$pageTitle    = $page['title'];
$activeModule = 'spaces';
$breadcrumbs  = array_merge([['Spaces', '/spaces'], [$page['space_name'], '/spaces/' . (int)$page['space_id']]], $crumbs, [[$page['title'], null]]);
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($page['title']) ?> <?= View::statusBadge($page['status']) ?></h1>
    <p class="page-subtitle">In <a href="/spaces/<?= (int)$page['space_id'] ?>"><?= Security::h($page['space_name']) ?></a> · Owner: <?= Security::h($page['owner_name'] ?: '—') ?> · v<?= (int)$page['current_version'] ?> · Updated <?= View::timeAgo($page['updated_at']) ?></p>
  </div>
  <div class="page-actions">
    <form method="POST" action="/pages/<?= (int)$page['id'] ?>/watch" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-ghost" type="submit"><i class="bi bi-eye<?= $isWatching?'-fill':'' ?>"></i></button></form>
    <a href="/pages/<?= (int)$page['id'] ?>/history" class="btn btn-ghost"><i class="bi bi-clock-history"></i> History (<?= $versionCount ?>)</a>
    <?php if (Auth::can('page.publish') && $page['status'] !== 'published'): ?>
      <form method="POST" action="/pages/<?= (int)$page['id'] ?>/publish" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-success" type="submit"><i class="bi bi-send-check"></i> Publish</button></form>
    <?php endif; ?>
    <?php if (Auth::can('page.edit')): ?><a href="/pages/<?= (int)$page['id'] ?>/edit" class="btn btn-primary"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start">
  <div>
    <div class="card"><div class="card-body"><div class="prose"><?= $page['body'] ?: '<p style="color:var(--text-muted)">This page has no content yet.</p>' ?></div></div></div>

    <div class="card" style="margin-top:18px" id="comments">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-chat-left-text"></i> Comments (<?= count($comments) ?>)</span></div></div>
      <div class="card-body">
        <?php foreach ($comments as $c): ?>
        <div class="comment"><?= View::avatar($c['user_name']) ?><div class="comment-body"><div class="comment-head"><span class="comment-author"><?= Security::h($c['user_name'] ?: 'User') ?></span><span class="comment-time"><?= View::timeAgo($c['created_at']) ?></span></div><div class="comment-text"><?= Security::h($c['body']) ?></div></div></div>
        <?php endforeach; ?>
        <?php if (!$comments): ?><div class="empty-state-sm">No comments yet.</div><?php endif; ?>
        <?php if (Auth::can('page.comment')): ?>
        <form method="POST" action="/pages/<?= (int)$page['id'] ?>/comment" style="margin-top:14px">
          <?= Security::csrfField() ?>
          <div class="form-group" style="margin:0"><textarea name="body" class="form-control" rows="2" placeholder="Add a comment…" required></textarea></div>
          <div style="margin-top:8px"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send"></i> Comment</button></div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-diagram-3"></i> Child Pages</span></div></div>
    <div class="card-body">
      <?php if ($children): ?>
        <ul class="page-tree">
        <?php foreach ($children as $ch): ?><li><a href="/pages/<?= (int)$ch['id'] ?>"><i class="bi bi-file-richtext"></i> <?= Security::h($ch['title']) ?> <?= View::statusBadge($ch['status']) ?></a></li><?php endforeach; ?>
        </ul>
      <?php else: ?><div class="empty-state-sm">No child pages.</div><?php endif; ?>
      <?php if (Auth::can('page.create')): ?><a href="/pages/create?space=<?= (int)$page['space_id'] ?>&parent=<?= (int)$page['id'] ?>" class="btn btn-sm btn-ghost" style="margin-top:10px"><i class="bi bi-plus"></i> Add child page</a><?php endif; ?>
    </div>
    <?php if (Auth::can('page.delete')): ?>
    <div class="card-body" style="border-top:1px solid var(--border-light)">
      <form method="POST" action="/pages/<?= (int)$page['id'] ?>/delete" style="margin:0" data-confirm="Delete this page and all its versions? This cannot be undone."><?= Security::csrfField() ?><button class="btn btn-sm btn-danger btn-full" type="submit"><i class="bi bi-trash"></i> Delete Page</button></form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
