<?php
/**
 * Announcements module view. Provided by AnnouncementsController::index().
 * In scope: $user, $NONCE, $programs, $programId, $items, $can, $csrf.
 */
use Redoubt\Support\Security;

$title = 'Announcements';
$navActive = 'announcements';
$breadcrumbs = ['Home' => '/app', 'Announcements' => null];
require __DIR__ . '/partials/app_header.php';

$prioBadge = static function (string $p): string {
    $map = ['critical' => 'b-warn', 'high' => 'b-warn', 'normal' => 'b-muted'];
    return '<span class="badge ' . ($map[$p] ?? 'b-muted') . '">' . Security::h(ucfirst($p)) . '</span>';
};
$fields = function (array $a = []) use ($csrf) {
    $aud = $a['audience'] ?? [];
    ob_start(); ?>
    <label>Title</label>
    <input type="text" name="title" required maxlength="200" value="<?= Security::h($a['title'] ?? '') ?>">
    <label>Body</label>
    <textarea name="body" rows="3" required><?= Security::h($a['body'] ?? '') ?></textarea>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div><label>Priority</label>
        <select name="priority">
          <?php foreach (['normal', 'high', 'critical'] as $p): ?>
            <option value="<?= $p ?>"<?= ($a['priority'] ?? 'normal') === $p ? ' selected' : '' ?>><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Expires (optional)</label>
        <input type="datetime-local" name="expire_at" value="<?= Security::h($a['expire_at'] ? date('Y-m-d\TH:i', strtotime((string) $a['expire_at'])) : '') ?>">
      </div>
      <div><label>Audience</label>
        <span style="display:inline-flex;gap:12px">
          <?php foreach (['all' => 'All members', 'internal' => 'Internal', 'customer' => 'Customer/COR'] as $tok => $lbl): ?>
            <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500">
              <input type="checkbox" name="aud_<?= $tok ?>" style="width:auto"<?= in_array($tok, $aud, true) ? ' checked' : '' ?>> <?= $lbl ?>
            </label>
          <?php endforeach; ?>
        </span>
      </div>
    </div>
    <?php return ob_get_clean();
};
?>
<div class="page-header">
  <h1 class="page-title">Announcements</h1>
  <form method="get" action="/app/announcements" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto">
      <?php foreach ($programs as $pid => $label): ?>
        <option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<?php if ($can['create']): ?>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:700">➕ New announcement</summary>
    <form method="post" action="/app/announcements" style="margin-top:12px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <?= $fields() ?>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Create draft</button></div>
    </form>
  </details>
</div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?>
    <div class="empty-state-sm">No announcements yet<?= $can['create'] ? ' — create the first one above.' : '.' ?></div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Title</th><th>Priority</th><th>Status</th><th style="width:1%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $a): ?>
        <tr>
          <td>
            <strong><?= Security::h($a['title']) ?></strong>
            <div class="perm-key" style="margin-top:2px"><?= Security::h(mb_strimwidth($a['body'], 0, 120, '…')) ?></div>
            <div class="perm-key">audience: <?= Security::h($a['audience'] ? implode(', ', $a['audience']) : 'all') ?></div>
          </td>
          <td><?= $prioBadge($a['priority']) ?></td>
          <td>
            <?php if ($a['is_live']): ?><span class="badge b-ok">Live</span>
            <?php elseif ($a['publish_at']): ?><span class="badge b-muted">Expired</span>
            <?php else: ?><span class="badge b-warn">Draft</span><?php endif; ?>
          </td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if ($can['publish'] && !$a['publish_at']): ?>
                <form method="post" action="/app/announcements">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="publish">
                  <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-sm btn-primary" type="submit">Publish</button>
                </form>
              <?php endif; ?>
              <?php if ($can['edit']): ?>
                <form method="post" action="/app/announcements">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button class="btn btn-sm" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php if ($can['edit']): ?>
        <tr><td colspan="4" style="padding-top:0">
          <details>
            <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Edit</summary>
            <form method="post" action="/app/announcements" style="margin-top:10px">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <?= $fields($a) ?>
              <div style="margin-top:12px"><button class="btn btn-primary btn-sm" type="submit">Save changes</button></div>
            </form>
          </details>
        </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
