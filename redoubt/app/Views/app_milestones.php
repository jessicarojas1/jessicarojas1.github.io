<?php
/**
 * Milestones module view. Provided by MilestonesController::index().
 * In scope: $user, $NONCE, $programs, $programId, $items, $can, $csrf.
 */
use Redoubt\Support\Security;

$title = 'Milestones';
$navActive = 'milestones';
$breadcrumbs = ['Home' => '/app', 'Milestones' => null];
require __DIR__ . '/partials/app_header.php';

$typeBadge = static function (string $t): string {
    $map = ['CDRL' => 'b-warn', 'deliverable' => 'b-ok', 'event' => 'b-muted'];
    return '<span class="badge ' . ($map[$t] ?? 'b-muted') . '">' . Security::h($t) . '</span>';
};
$fields = function (array $m = []) {
    ob_start(); ?>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:220px"><label>Title</label><input type="text" name="title" required maxlength="200" value="<?= Security::h($m['title'] ?? '') ?>"></div>
      <div><label>Due date</label><input type="date" name="due_date" value="<?= Security::h($m['due_date'] ? date('Y-m-d', strtotime((string) $m['due_date'])) : '') ?>"></div>
      <div><label>Type</label>
        <select name="type">
          <?php foreach (['CDRL', 'deliverable', 'event'] as $t): ?><option value="<?= $t ?>"<?= ($m['type'] ?? 'event') === $t ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php return ob_get_clean();
};
?>
<div class="page-header">
  <h1 class="page-title">Milestones &amp; Key Dates</h1>
  <form method="get" action="/app/milestones" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto">
      <?php foreach ($programs as $pid => $label): ?>
        <option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<?php if ($can['manage']): ?>
<div class="card">
  <details>
    <summary style="cursor:pointer;font-weight:700">➕ Add milestone</summary>
    <form method="post" action="/app/milestones" style="margin-top:12px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <?= $fields() ?>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Add</button></div>
    </form>
  </details>
</div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?>
    <div class="empty-state-sm">No milestones yet.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Milestone</th><th>Type</th><th>Due</th><th>Countdown</th><?php if ($can['manage']): ?><th style="width:1%">Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($items as $m): ?>
        <tr<?= $m['is_past'] ? ' style="opacity:.55"' : '' ?>>
          <td><strong><?= Security::h($m['title']) ?></strong></td>
          <td><?= $typeBadge((string) $m['type']) ?></td>
          <td><?= Security::h($m['due_date'] ? date('M j, Y', strtotime((string) $m['due_date'])) : '—') ?></td>
          <td>
            <?php if ($m['days_out'] === null): ?><span class="perm-key">—</span>
            <?php elseif ($m['is_past']): ?><span class="perm-key">past</span>
            <?php elseif ($m['days_out'] === 0): ?><span class="badge b-warn">Today</span>
            <?php else: ?><span class="badge <?= $m['days_out'] <= 7 ? 'b-warn' : 'b-muted' ?>"><?= (int) $m['days_out'] ?> day<?= $m['days_out'] === 1 ? '' : 's' ?></span><?php endif; ?>
          </td>
          <?php if ($can['manage']): ?>
          <td>
            <div style="display:flex;gap:6px">
              <form method="post" action="/app/milestones"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-sm" type="submit">Delete</button></form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php if ($can['manage']): ?>
        <tr><td colspan="5" style="padding-top:0">
          <details><summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Edit</summary>
            <form method="post" action="/app/milestones" style="margin-top:10px">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <?= $fields($m) ?>
              <div style="margin-top:12px"><button class="btn btn-primary btn-sm" type="submit">Save</button></div>
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
