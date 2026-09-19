<?php
/**
 * Task Orders module view. Provided by TaskOrdersController::index().
 * In scope: $user, $NONCE, $programs, $programId, $items, $can, $csrf.
 */
use Redoubt\Support\Security;
use Redoubt\Support\TaskOrders;

$title = 'Task Orders';
$navActive = 'taskorders';
$breadcrumbs = ['Home' => '/app', 'Task Orders' => null];
require __DIR__ . '/partials/app_header.php';

$statusBadge = static function (string $s): string {
    $map = ['awarded' => 'b-ok', 'active' => 'b-ok', 'closed' => 'b-muted', 'draft' => 'b-warn'];
    return '<span class="badge ' . ($map[$s] ?? 'b-muted') . '">' . Security::h(ucfirst($s)) . '</span>';
};
$toFields = function (array $t = []) {
    ob_start(); ?>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div><label>TO number</label><input type="text" name="number" required value="<?= Security::h($t['number'] ?? '') ?>"></div>
      <div style="flex:1;min-width:200px"><label>Title</label><input type="text" name="title" value="<?= Security::h($t['title'] ?? '') ?>"></div>
      <div><label>Status</label>
        <select name="status">
          <?php foreach (TaskOrders::STATUSES as $s): ?>
            <option value="<?= $s ?>"<?= ($t['status'] ?? 'draft') === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div><label>Company scope (ids, comma-sep; blank = all)</label><input type="text" name="company_scope" value="<?= Security::h(implode(',', $t['company_scope'] ?? [])) ?>" placeholder="e.g. 7"></div>
      <div style="flex:1;min-width:200px"><label>Document link (SharePoint)</label><input type="url" name="sp_link" value="<?= Security::h($t['sp_link'] ?? '') ?>"></div>
    </div>
    <?php return ob_get_clean();
};
?>
<div class="page-header">
  <h1 class="page-title">Task Orders</h1>
  <form method="get" action="/app/task-orders" style="display:flex;gap:8px;align-items:center">
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
    <summary style="cursor:pointer;font-weight:700">➕ New task order</summary>
    <form method="post" action="/app/task-orders" style="margin-top:12px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
      <?= $toFields() ?>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Create</button></div>
    </form>
  </details>
</div>
<?php endif; ?>

<div class="card">
  <?php if ($items === []): ?>
    <div class="empty-state-sm">No task orders visible.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Number</th><th>Title</th><th>Status</th><th>Scope</th><th style="width:1%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $t): ?>
        <tr>
          <td><strong><?= Security::h($t['number']) ?></strong></td>
          <td><?= Security::h($t['title'] ?: '—') ?><?php if ($t['sp_link']): ?> · <a href="<?= Security::h($t['sp_link']) ?>">doc</a><?php endif; ?></td>
          <td><?= $statusBadge($t['status'] ?? 'draft') ?></td>
          <td class="perm-key"><?= $t['company_scope'] ? Security::h(implode(', ', $t['company_scope'])) : 'all' ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if ($can['approve'] && ($t['status'] ?? '') !== 'awarded'): ?>
                <form method="post" action="/app/task-orders">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button class="btn btn-sm btn-primary" type="submit">Award</button>
                </form>
              <?php endif; ?>
              <?php if ($can['edit']): ?>
                <form method="post" action="/app/task-orders">
                  <?= Security::csrfField() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button class="btn btn-sm" type="submit">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php if ($can['edit']): ?>
        <tr><td colspan="5" style="padding-top:0">
          <details>
            <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Edit</summary>
            <form method="post" action="/app/task-orders" style="margin-top:10px">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <?= $toFields($t) ?>
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
