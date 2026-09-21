<?php
/** FAQ view. In scope: $user,$NONCE,$programs,$programId,$items,$can,$csrf. */
use Redoubt\Support\Security;

$title = 'FAQ';
$navActive = 'resources';
$breadcrumbs = ['Home' => '/app', 'Program' => '/app/overview', 'FAQ' => null];
require __DIR__ . '/partials/app_header.php';

$fields = function (array $f = []) {
    $aud = $f['audience'] ?? [];
    ob_start(); ?>
    <label>Question</label><input type="text" name="question" required maxlength="300" value="<?= Security::h($f['question'] ?? '') ?>">
    <label>Answer</label><textarea name="answer" rows="3" required><?= Security::h($f['answer'] ?? '') ?></textarea>
    <label>Audience</label><span style="display:inline-flex;gap:10px">
      <?php foreach (['all' => 'All', 'internal' => 'Internal', 'customer' => 'Customer'] as $t => $lbl): ?>
        <label style="display:inline-flex;gap:4px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="aud_<?= $t ?>" style="width:auto"<?= in_array($t, $aud, true) ? ' checked' : '' ?>> <?= $lbl ?></label>
      <?php endforeach; ?>
    </span>
    <?php return ob_get_clean();
};
?>
<div class="page-header">
  <h1 class="page-title">FAQ &amp; Knowledge Base</h1>
  <form method="get" action="/app/faq" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto"><?php foreach ($programs as $pid => $label): ?><option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<?php if ($can['manage']): ?>
<div class="card"><details><summary style="cursor:pointer;font-weight:700">➕ Add Q&amp;A</summary>
  <form method="post" action="/app/faq" style="margin-top:12px"><?= Security::csrfField() ?><input type="hidden" name="action" value="create"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><?= $fields() ?><div style="margin-top:12px"><button class="btn btn-primary" type="submit">Add</button></div></form>
</details></div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?><div class="empty-state-sm">No FAQ entries yet.</div>
  <?php else: foreach ($items as $f): ?>
    <div style="border-bottom:1px solid var(--line);padding:12px 0">
      <details>
        <summary style="cursor:pointer;font-weight:700"><?= Security::h($f['question']) ?></summary>
        <div style="margin-top:8px;color:var(--muted);white-space:pre-wrap"><?= Security::h($f['answer']) ?></div>
        <?php if ($can['manage']): ?>
        <div style="margin-top:8px">
          <details><summary style="cursor:pointer;font-size:12.5px;color:var(--muted)">Edit</summary>
            <form method="post" action="/app/faq" style="margin-top:10px"><?= Security::csrfField() ?><input type="hidden" name="action" value="update"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><?= $fields($f) ?><div style="margin-top:10px;display:flex;gap:6px"><button class="btn btn-primary btn-sm" type="submit">Save</button></div></form>
          </details>
          <form method="post" action="/app/faq" style="margin-top:6px"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><button class="btn btn-sm" type="submit">Delete</button></form>
        </div>
        <?php endif; ?>
      </details>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
