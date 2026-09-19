<?php
/**
 * Directory module view. Provided by DirectoryController::index().
 * In scope: $user, $NONCE, $programs, $programId, $items, $can, $csrf.
 */
use Redoubt\Support\Security;

$title = 'Directory';
$navActive = 'directory';
$breadcrumbs = ['Home' => '/app', 'Directory' => null];
require __DIR__ . '/partials/app_header.php';

$fields = function (array $c = []) {
    $vis = $c['visibility'] ?? [];
    ob_start(); ?>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:200px"><label>Name</label><input type="text" name="freeform" required value="<?= Security::h($c['name'] ?? $c['freeform'] ?? '') ?>"></div>
      <div style="flex:1;min-width:160px"><label>Role / title</label><input type="text" name="role_label" value="<?= Security::h($c['role_label'] ?? '') ?>"></div>
      <div><label>Company id (optional)</label><input type="text" name="company_id" value="<?= Security::h((string) ($c['company_id'] ?? '')) ?>" placeholder="e.g. 2"></div>
    </div>
    <label>Visibility</label>
    <span style="display:inline-flex;gap:12px">
      <?php foreach (['all' => 'All members', 'internal' => 'Internal', 'customer' => 'Customer'] as $tok => $lbl): ?>
        <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="vis_<?= $tok ?>" style="width:auto"<?= in_array($tok, $vis, true) ? ' checked' : '' ?>> <?= $lbl ?></label>
      <?php endforeach; ?>
    </span>
    <?php return ob_get_clean();
};
?>
<div class="page-header">
  <h1 class="page-title">Program Directory</h1>
  <form method="get" action="/app/directory" style="display:flex;gap:8px;align-items:center">
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
    <summary style="cursor:pointer;font-weight:700">➕ Add contact</summary>
    <form method="post" action="/app/directory" style="margin-top:12px">
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
    <div class="empty-state-sm">No contacts visible.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Name</th><th>Role</th><th>Company</th><th>Email</th><?php if ($can['manage']): ?><th style="width:1%">Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($items as $c): ?>
        <tr>
          <td><strong><?= Security::h($c['name']) ?></strong></td>
          <td><?= Security::h($c['role_label'] ?: '—') ?></td>
          <td><?= Security::h($c['company'] ?: '—') ?></td>
          <td><?php if ($c['email']): ?><a href="mailto:<?= Security::h($c['email']) ?>"><?= Security::h($c['email']) ?></a><?php else: ?><span class="perm-key">—</span><?php endif; ?></td>
          <?php if ($can['manage']): ?>
          <td>
            <form method="post" action="/app/directory"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm" type="submit">Delete</button></form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
