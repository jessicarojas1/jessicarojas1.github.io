<?php
/**
 * Like button. Expects: $likeType, $likeId, $likeData (['count'=>int,'liked'=>bool]),
 * and optional $likeSmall (bool) for the compact comment variant.
 */
$ld = $likeData ?? ['count' => 0, 'liked' => false];
$small = !empty($likeSmall);
?>
<form method="POST" action="/reactions/toggle" class="like-form" style="display:inline-block;margin:0">
  <?= Security::csrfField() ?>
  <input type="hidden" name="entity_type" value="<?= Security::h($likeType) ?>">
  <input type="hidden" name="entity_id" value="<?= (int)$likeId ?>">
  <button type="submit" class="like-btn<?= $ld['liked'] ? ' liked' : '' ?><?= $small ? ' like-sm' : '' ?>" title="<?= $ld['liked'] ? 'Unlike' : 'Like' ?>">
    <i class="bi bi-hand-thumbs-up<?= $ld['liked'] ? '-fill' : '' ?>"></i><?php if (!$small): ?> Like<?php endif; ?><?php if ($ld['count'] > 0): ?> <span class="like-count"><?= (int)$ld['count'] ?></span><?php endif; ?>
  </button>
</form>
