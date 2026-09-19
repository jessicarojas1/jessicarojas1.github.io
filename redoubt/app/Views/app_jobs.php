<?php
/**
 * Jobs module view. Provided by JobsController::index().
 * In scope: $user, $NONCE, $programs, $programId, $items, $can, $csrf,
 *           $companies (id=>name), $taskOrders (id=>label), $filter, $myCompany.
 */
use Redoubt\Support\Security;

$title = 'Jobs';
$navActive = 'jobs';
$breadcrumbs = ['Home' => '/app', 'Jobs' => null];
require __DIR__ . '/partials/app_header.php';

$companies = $companies ?? [];
$taskOrders = $taskOrders ?? [];
$filter = $filter ?? [];
$myCompany = $myCompany ?? null;

$statusBadge = static function (string $s): string {
    $map = ['open' => 'b-ok', 'filled' => 'b-muted', 'closed' => 'b-muted', 'draft' => 'b-warn'];
    return '<span class="badge ' . ($map[$s] ?? 'b-muted') . '">' . Security::h(ucfirst($s)) . '</span>';
};
// Human-readable targeting summary for a job row.
$targeting = static function (array $j) use ($companies, $taskOrders): string {
    if (!empty($j['program_wide'])) {
        return '<span class="badge b-muted">Program-wide</span>';
    }
    $parts = [];
    foreach ($j['audience'] as $a) {
        if (in_array($a, ['internal', 'customer'], true)) {
            $parts[] = '<span class="badge b-muted">' . Security::h(ucfirst($a)) . '</span>';
        }
    }
    foreach ($j['company_scope'] as $cid) {
        $parts[] = '<span class="badge b-warn">Co: ' . Security::h($companies[$cid] ?? ('#' . $cid)) . '</span>';
    }
    foreach ($j['task_order_scope'] as $tid) {
        $lbl = $taskOrders[$tid] ?? ('TO#' . $tid);
        $parts[] = '<span class="badge b-warn">Contract: ' . Security::h(strtok($lbl, ' ')) . '</span>';
    }
    return $parts ? implode(' ', $parts) : '<span class="perm-key">—</span>';
};

$fields = function (array $j = []) use ($companies, $taskOrders) {
    $aud = $j['audience'] ?? [];
    $jco = $j['company_scope'] ?? [];
    $jto = $j['task_order_scope'] ?? [];
    $pw = $j['program_wide'] ?? (($j ? false : true)); // default new job = program-wide
    ob_start(); ?>
    <label>Title</label>
    <input type="text" name="title" required maxlength="200" value="<?= Security::h($j['title'] ?? '') ?>">
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div style="flex:1;min-width:220px"><label>Apply URL (ATS)</label><input type="url" name="ats_url" value="<?= Security::h($j['ats_url'] ?? '') ?>" placeholder="https://ats.example.us/apply/123"></div>
      <div><label>Expires (optional)</label><input type="datetime-local" name="expire_at" value="<?= Security::h(!empty($j['expire_at']) ? date('Y-m-d\TH:i', strtotime((string) $j['expire_at'])) : '') ?>"></div>
    </div>

    <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-top:12px">
      <label style="display:flex;gap:8px;align-items:center;margin:0 0 8px;font-weight:700;color:var(--ink)">
        <input type="checkbox" name="program_wide" value="1" style="width:auto"<?= $pw ? ' checked' : '' ?>> Program-wide (visible to all program members)
      </label>
      <p class="perm-key" style="margin:0 0 10px">If checked, the targeting below is ignored. Uncheck to target specific audiences, companies, or contracts.</p>

      <label>Audience</label>
      <span style="display:inline-flex;gap:12px;margin-bottom:6px">
        <?php foreach (['internal' => 'Internal', 'customer' => 'Customer'] as $tok => $lbl): ?>
          <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="aud_<?= $tok ?>" style="width:auto"<?= in_array($tok, $aud, true) ? ' checked' : '' ?>> <?= $lbl ?></label>
        <?php endforeach; ?>
      </span>

      <label>Companies</label>
      <?php if ($companies === []): ?><p class="perm-key" style="margin:0 0 6px">No companies attached to this program yet.</p><?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:6px">
        <?php foreach ($companies as $cid => $cname): ?>
          <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="company_scope[]" value="<?= (int) $cid ?>" style="width:auto"<?= in_array((int) $cid, array_map('intval', $jco), true) ? ' checked' : '' ?>> <?= Security::h($cname) ?></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <label>Contracts (task orders)</label>
      <?php if ($taskOrders === []): ?><p class="perm-key" style="margin:0">No task orders in this program yet.</p><?php else: ?>
      <div style="display:flex;flex-wrap:wrap;gap:10px">
        <?php foreach ($taskOrders as $tid => $tlabel): ?>
          <label style="display:inline-flex;gap:5px;align-items:center;margin:0;font-weight:500"><input type="checkbox" name="task_order_scope[]" value="<?= (int) $tid ?>" style="width:auto"<?= in_array((int) $tid, array_map('intval', $jto), true) ? ' checked' : '' ?>> <?= Security::h($tlabel) ?></label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

// Filter bar links preserving program context.
$base = '/app/jobs?program_id=' . (int) $programId;
$isActive = static fn (bool $c): string => $c ? ' btn-primary' : '';
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

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <span class="perm-key">Filter:</span>
  <a class="btn btn-sm<?= $isActive($filter === []) ?>" href="<?= $base ?>">All</a>
  <a class="btn btn-sm<?= $isActive(($filter['scope'] ?? '') === 'program') ?>" href="<?= $base ?>&amp;scope=program">Program-wide</a>
  <a class="btn btn-sm<?= $isActive(($filter['scope'] ?? '') === 'targeted') ?>" href="<?= $base ?>&amp;scope=targeted">Targeted</a>
  <?php if ($myCompany && isset($companies[$myCompany])): ?>
    <a class="btn btn-sm<?= $isActive(($filter['company_id'] ?? 0) === $myCompany) ?>" href="<?= $base ?>&amp;co=<?= (int) $myCompany ?>">My company</a>
  <?php endif; ?>
  <?php foreach ($taskOrders as $tid => $tlabel): ?>
    <a class="btn btn-sm<?= $isActive(($filter['task_order_id'] ?? 0) === (int) $tid) ?>" href="<?= $base ?>&amp;to=<?= (int) $tid ?>"><?= Security::h(strtok($tlabel, ' ')) ?></a>
  <?php endforeach; ?>
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
    <div class="empty-state-sm">No job requisitions match this view.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Title</th><th>Status</th><th>Targeting</th><th>Apply</th><th style="width:1%">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $j): ?>
        <tr>
          <td><strong><?= Security::h($j['title']) ?></strong></td>
          <td><?= $statusBadge($j['status'] ?? 'draft') ?></td>
          <td><?= $targeting($j) ?></td>
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
        <tr><td colspan="5" style="padding-top:0">
          <details>
            <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">Edit &amp; targeting</summary>
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
