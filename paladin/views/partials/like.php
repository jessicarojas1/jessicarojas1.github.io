<?php
/**
 * Emoji reaction strip. Expects:
 *   $likeType, $likeId, $likeData (['total'=>int,'emojis'=>[{emoji,count,reacted}]]),
 *   optional $likeSmall (compact), optional $likeReturn (relative return path).
 * Renders existing emoji chips (toggle on click) + a "+" picker of the palette.
 */
$ld    = $likeData ?? ['total' => 0, 'emojis' => []];
$small = !empty($likeSmall);
$ret   = $likeReturn ?? '';
$used  = [];
foreach (($ld['emojis'] ?? []) as $e) $used[$e['emoji']] = true;
$pickerId = 'react-' . Security::h($likeType) . '-' . (int)$likeId;
?>
<span class="reaction-strip" style="display:inline-flex;align-items:center;gap:4px;flex-wrap:wrap">
  <?php foreach (($ld['emojis'] ?? []) as $e): ?>
    <form method="POST" action="/reactions/toggle" style="display:inline;margin:0">
      <?= Security::csrfField() ?>
      <input type="hidden" name="entity_type" value="<?= Security::h($likeType) ?>">
      <input type="hidden" name="entity_id" value="<?= (int)$likeId ?>">
      <input type="hidden" name="emoji" value="<?= Security::h($e['emoji']) ?>">
      <?php if ($ret !== ''): ?><input type="hidden" name="return" value="<?= Security::h($ret) ?>"><?php endif; ?>
      <button type="submit" class="reaction-chip<?= $e['reacted'] ? ' reacted' : '' ?>" title="<?= $e['reacted'] ? 'Remove your reaction' : 'React' ?>"
        style="border:1px solid <?= $e['reacted'] ? 'var(--primary)' : 'var(--border-light)' ?>;background:<?= $e['reacted'] ? 'color-mix(in srgb, var(--primary) 14%, transparent)' : 'var(--card-bg)' ?>;border-radius:14px;padding:1px 8px;cursor:pointer;font-size:<?= $small ? '.8rem' : '.85rem' ?>;line-height:1.6"><?= Security::h($e['emoji']) ?> <span class="form-hint" style="font-size:.8em"><?= (int)$e['count'] ?></span></button>
    </form>
  <?php endforeach; ?>
  <span style="position:relative">
    <button type="button" class="reaction-add" data-menu-toggle="<?= $pickerId ?>" title="Add reaction" style="border:1px solid var(--border-light);background:var(--card-bg);border-radius:14px;padding:1px 7px;cursor:pointer;color:var(--text-light);font-size:<?= $small ? '.8rem' : '.85rem' ?>;line-height:1.6"><i class="bi bi-emoji-smile"></i></button>
    <span id="<?= $pickerId ?>" class="topbar-menu" hidden style="position:absolute;left:0;top:calc(100% + 4px);z-index:60;background:var(--card-bg);border:1px solid var(--border);border-radius:10px;box-shadow:0 6px 22px rgba(0,0,0,.2);padding:5px;display:flex;gap:2px">
      <?php foreach (Reactions::PALETTE as $em): ?>
        <form method="POST" action="/reactions/toggle" style="margin:0">
          <?= Security::csrfField() ?>
          <input type="hidden" name="entity_type" value="<?= Security::h($likeType) ?>">
          <input type="hidden" name="entity_id" value="<?= (int)$likeId ?>">
          <input type="hidden" name="emoji" value="<?= Security::h($em) ?>">
          <?php if ($ret !== ''): ?><input type="hidden" name="return" value="<?= Security::h($ret) ?>"><?php endif; ?>
          <button type="submit" title="<?= isset($used[$em]) ? 'Toggle' : 'React' ?>" style="border:none;background:<?= isset($used[$em]) ? 'var(--bg-secondary)' : 'none' ?>;border-radius:6px;padding:4px 6px;cursor:pointer;font-size:1.05rem"><?= Security::h($em) ?></button>
        </form>
      <?php endforeach; ?>
    </span>
  </span>
</span>
