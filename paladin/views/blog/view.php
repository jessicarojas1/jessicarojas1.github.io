<?php
$pageTitle    = $post['title'];
$activeModule = 'blog';
$breadcrumbs  = [['Blog', '/blog'], [$post['title'], null]];
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($post['title']) ?> <?php if ($post['status'] !== 'published'): ?><?= View::statusBadge('draft') ?><?php endif; ?></h1>
    <p class="page-subtitle">By <?= Security::h($post['author_name'] ?: '—') ?> · <?= View::fmtDate($post['published_at'] ?: $post['created_at'], 'M j, Y') ?> · in <a href="/spaces/<?= (int)$post['space_id'] ?>"><?= Security::h($post['space_name']) ?></a></p>
  </div>
  <div class="page-actions">
    <?php $likeType='blog'; $likeId=(int)$post['id']; $likeData=$postLike; require PALADIN_ROOT . '/views/partials/like.php'; ?>
    <?php $shareType='blog'; $shareId=(int)$post['id']; $sharePath='/blog/'.(int)$post['id']; require PALADIN_ROOT . '/views/partials/share.php'; ?>
    <?php if (Auth::can('page.edit')): ?><a href="/blog/<?= (int)$post['id'] ?>/edit" class="btn btn-primary"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    <?php if (Auth::can('page.delete')): ?><form method="POST" action="/blog/<?= (int)$post['id'] ?>/delete" style="margin:0" data-confirm="Delete this blog post?"><?= Security::csrfField() ?><button class="btn btn-ghost btn-danger" type="submit"><i class="bi bi-trash"></i></button></form><?php endif; ?>
  </div>
</div>

<div style="max-width:760px">
  <div class="card"><div class="card-body"><div class="prose"><?= $post['body'] ?: '<p style="color:var(--text-muted)">No content.</p>' ?></div></div></div>

  <div class="card" style="margin-top:18px" id="comments">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-chat-left-text"></i> Comments (<?= count($comments) ?>)</span></div></div>
    <div class="card-body">
      <?php
        $cEntityType = 'blog'; $cEntityId = (int)$post['id'];
        $cAction = '/blog/' . (int)$post['id'] . '/comment';
        $cCanComment = Auth::can('page.comment');
        require PALADIN_ROOT . '/views/partials/comments.php';
      ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
