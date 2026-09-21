<?php
/** Quick Links view. In scope: $user,$NONCE,$programs,$programId,$items,$can,$csrf. */
use Redoubt\Support\Security;

$title = 'Quick Links';
$navActive = 'resources';
$breadcrumbs = ['Home' => '/app', 'Program' => '/app/overview', 'Quick Links' => null];
require __DIR__ . '/partials/app_header.php';

$fields = function (array $l = []) {
    $aud = $l['audience'] ?? [];
    ob_start(); ?>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:180px"><label>Label</label><input type="text" name="label" required maxlength="120" value="<?= Security::h($l['label'] ?? '') ?>"></div>
      <div style="flex:2;min-width:220px"><label>URL (https://)</label><input type="url" name="url" required value="<?= Security::h($l['url'] ?? '') ?>" placeholder="https://enterprise-system.example.us"></div>
      <div><label>Audience</label><span style="display:inline-flex;gap:10px">
        <?php foreach (['all' => 'All', 'internal' => 'Internal', 'customer' => 'Customer'] as $t => $lbl): ?>
          <label style="display:inline-flex;gap:4px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="aud_<?= $t ?>" style="width:auto"<?= in_array($t, $aud, true) ? ' checked' : '' ?>> <?= $lbl ?></label>
        <?php endforeach; ?>
      </span></div>
    </div>
    <?php return ob_get_clean();
};
?>
<div class="page-header">
  <h1 class="page-title">Quick Links</h1>
  <form method="get" action="/app/quick-links" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto"><?php foreach ($programs as $pid => $label): ?><option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<?php if ($can['manage']): ?>
<div class="card"><details><summary style="cursor:pointer;font-weight:700">➕ Add link</summary>
  <form method="post" action="/app/quick-links" style="margin-top:12px"><?= Security::csrfField() ?><input type="hidden" name="action" value="create"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><?= $fields() ?><div style="margin-top:12px"><button class="btn btn-primary" type="submit">Add</button></div></form>
</details></div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?><div class="empty-state-sm">No quick links yet.</div>
  <?php else: ?>
    <div class="quick-grid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr))">
      <?php foreach ($items as $l): ?>
        <div class="quick-card" style="display:flex;justify-content:space-between;align-items:center;gap:8px">
          <a href="<?= Security::h($l['url']) ?>" target="_blank" rel="noopener" style="font-weight:600">🔗 <?= Security::h($l['label']) ?> ↗</a>
          <?php if ($can['manage']): ?>
          <form method="post" action="/app/quick-links" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><button class="btn btn-sm" type="submit" style="padding:2px 8px">✕</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
