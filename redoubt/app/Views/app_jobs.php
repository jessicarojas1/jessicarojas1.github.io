<?php
/**
 * Jobs module view. Provided by JobsController::index().
 * In scope: $user, $NONCE, $programs, $programId, $items, $can, $csrf.
 */
use Redoubt\Support\Security;

$title = 'Jobs';
$navActive = 'jobs';
$breadcrumbs = ['Home' => '/app', 'Jobs' => null];
require __DIR__ . '/partials/app_header.php';

$statusBadge = static function (string $s): string {
    $map = ['open' => 'b-ok', 'filled' => 'b-muted', 'closed' => 'b-muted', 'draft' => 'b-warn'];
    return '<span class="badge ' . ($map[$s] ?? 'b-muted') . '">' . Security::h(ucfirst($s)) . '</span>';
};
$fields = function (array $j = []) {
    $aud = $j['audience'] ?? [];
    ob_start(); ?>
    <label>Title</label>
    <input type="text" name="title" required maxlength="200" value="<?= Security::h($j['title'] ?? '') ?>">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:220px"><label>Apply URL (ATS)</label><input type="url" name="ats_url" value="<?= Security::h($j['ats_url'] ?? '') ?>" placeholder="https://ats.example.us/apply/123"></div>
      <div><label>Expires (optional)</label><input type="datetime-local" name="expire_at" value="<?= Security::h($j['expire_at'] ? date('Y-m-d\TH:i', strtotime((string) $j['expire_at'])) : '') ?>"></div>
      <div><label>Audience</label>
        <span style="display:inline-flex;gap:12px">
          <?php foreach (['all' => 'All', 'internal' => 'Internal', 'customer' => 'Customer'] as $tok => $lbl): ?>
            <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="aud_<?= $tok ?>" style="width:auto"<?= in_array($tok, $aud, true) ? ' checked' : '' ?>> <?= $lbl ?></label>
          <?php endforeach; ?>
        </span>
      </div>
    </div>
    <?php return ob_get_clean();
};
?>
<div class="page-header">
  <h1 class="page-title">Job Requisitions</h1>
  <form method="get" action="/app/jobs" style="display:flex;gap:8px;align-items:center">
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
    <summary style="cursor:pointer;font-weight:700">➕ New requisition</summary>
    <form method="post" action="/app/jobs" style="margin-top:12px">
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
    <div class="empty-state-sm">No job requisitions visible.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Title</th><th>Status</th><th>Apply</th><th style="width:1%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $j): ?>
        <tr>
          <td><strong><?= Security::h($j['title']) ?></strong>
            <div class="perm-key">audience: <?= Security::h($j['audience'] ? implode(', ', $j['audience']) : 'all') ?></div></td>
          <td><?= $statusBadge($j['status'] ?? 'draft') ?></td>
          <td><?php if ($j['ats_url']): ?><a href="<?= Security::h($j['ats_url']) ?>">Apply ↗</a><?php else: ?><span class="perm-key">—</span><?php endif; ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if ($can['publish'] && ($j['status'] ?? '') === 'draft'): ?>
                <form method="post" action="/app/jobs"><?= Security::csrfField() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button class="btn btn-sm btn-primary" type="submit">Post</button></form>
              <?php endif; ?>
              <?php if ($can['edit'] && ($j['status'] ?? '') === 'open'): ?>
                <form method="post" action="/app/jobs"><?= Security::csrfField() ?><input type="hidden" name="action" value="close"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button class="btn btn-sm" type="submit">Close</button></form>
              <?php endif; ?>
              <?php if ($can['edit']): ?>
                <form method="post" action="/app/jobs"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="program_id" value="<?= (int) $programId ?>"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><button class="btn btn-sm" type="submit">Delete</button></form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php if ($can['edit']): ?>
        <tr><td colspan="4" style="padding-top:0">
          <details>
            <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Edit</summary>
            <form method="post" action="/app/jobs" style="margin-top:10px">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="program_id" value="<?= (int) $programId ?>">
              <input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
              <?= $fields($j) ?>
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
