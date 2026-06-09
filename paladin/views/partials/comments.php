<?php
/**
 * Threaded comments partial.
 * Expects:
 *   $comments    — flat rows: id, parent_id, user_id, user_name, body,
 *                  created_at, resolved_at, resolver_name
 *   $cEntityType — 'page' | 'document'
 *   $cEntityId   — int
 *   $cAction     — POST URL for new comments/replies (e.g. /pages/5/comment)
 *   $cCanComment — bool (show reply/new forms)
 */
$cEntityType = $cEntityType ?? 'page';
$cEntityId   = (int)($cEntityId ?? 0);
$cCanComment = $cCanComment ?? false;
$cReactions  = $cReactions ?? [];

$threads = []; $replies = [];
foreach ($comments as $c) {
    if (empty($c['parent_id'])) $threads[$c['id']] = $c;
}
foreach ($comments as $c) {
    if (!empty($c['parent_id'])) $replies[(int)$c['parent_id']][] = $c;
}

$renderComment = function (array $c) use ($cReactions) {
    echo '<div class="comment">' . View::avatar($c['user_name']) . '<div class="comment-body">';
    echo '<div class="comment-head"><span class="comment-author">' . Security::h($c['user_name'] ?: 'User') . '</span>';
    echo '<span class="comment-time">' . View::timeAgo($c['created_at']) . '</span></div>';
    echo '<div class="comment-text">' . View::mentionize($c['body']) . '</div>';
    $likeType = 'comment'; $likeId = (int)$c['id']; $likeSmall = true;
    $likeData = $cReactions[(int)$c['id']] ?? ['count' => 0, 'liked' => false];
    echo '<div class="comment-like">'; require PALADIN_ROOT . '/views/partials/like.php'; echo '</div>';
    echo '</div></div>';
};
?>
<div class="comment-threads">
  <?php foreach ($threads as $t): $isResolved = !empty($t['resolved_at']); $tid = (int)$t['id']; ?>
    <div class="thread <?= $isResolved ? 'resolved' : '' ?>">
      <div class="thread-main">
        <?php $renderComment($t); ?>
        <div class="thread-actions">
          <?php if ($isResolved): ?>
            <span class="badge badge-green"><i class="bi bi-check2-circle"></i> Resolved<?= !empty($t['resolver_name']) ? ' by ' . Security::h($t['resolver_name']) : '' ?></span>
            <form method="POST" action="/comments/<?= $tid ?>/reopen" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button></form>
          <?php else: ?>
            <?php if ($cCanComment): ?><button type="button" class="btn btn-sm btn-ghost" data-toggle-class="open" data-target="#reply-<?= $tid ?>"><i class="bi bi-reply"></i> Reply</button><?php endif; ?>
            <form method="POST" action="/comments/<?= $tid ?>/resolve" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-check2-circle"></i> Resolve</button></form>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($replies[$tid])): ?>
        <div class="thread-replies">
          <?php foreach ($replies[$tid] as $r) $renderComment($r); ?>
        </div>
      <?php endif; ?>

      <?php if ($cCanComment && !$isResolved): ?>
        <form method="POST" action="<?= Security::h($cAction) ?>" class="reply-form" id="reply-<?= $tid ?>">
          <?= Security::csrfField() ?>
          <input type="hidden" name="parent_id" value="<?= $tid ?>">
          <div class="form-group" style="margin:0"><textarea name="body" class="form-control" rows="2" placeholder="Reply… use @name to notify" required></textarea></div>
          <div style="margin-top:6px"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send"></i> Reply</button></div>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$threads): ?><div class="empty-state-sm">No comments yet.</div><?php endif; ?>

  <?php if ($cCanComment): ?>
  <form method="POST" action="<?= Security::h($cAction) ?>" style="margin-top:14px">
    <?= Security::csrfField() ?>
    <div class="form-group" style="margin:0"><textarea name="body" class="form-control" rows="2" placeholder="Add a comment… use @name to notify a teammate" required></textarea></div>
    <div style="margin-top:8px"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send"></i> Comment</button></div>
  </form>
  <?php endif; ?>
</div>
