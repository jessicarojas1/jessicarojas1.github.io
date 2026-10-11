<?php
/** @var array $campaign @var array $items @var array $progress @var bool $canManage @var int $total @var int $page @var int $pageSize @var array $filters @var string $csrf */
use Verity\Support\Security;

$appScript = '/assets/campaigns.js';
require __DIR__ . '/partials/app_header.php';
$lastPage = max(1, (int) ceil($total / $pageSize));
$pct = $progress['total'] > 0 ? (int) round(100 * ($progress['total'] - $progress['pending']) / $progress['total']) : 0;
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($campaign['name']) ?></h1>
    <p><?= Security::h(ucwords($campaign['scope_type'])) ?><?= $campaign['scope_application_name'] ? ' — ' . Security::h($campaign['scope_application_name']) : '' ?> · Reviewer assignment: <?= $campaign['reviewer_strategy'] === 'manager' ? 'each account holder\'s manager' : 'fixed — ' . Security::h($campaign['default_reviewer_name'] ?? '—') ?></p>
  </div>
</div>

<?php if (!empty($_GET['launched'])): ?>
<div class="card"><span class="badge b-ok">Campaign launched</span> <?= (int) $_GET['launched'] ?> entitlement assignment(s) snapshotted for review.</div>
<?php endif; ?>

<div class="card">
  <h2>Progress — <?= $pct ?>% decided</h2>
  <table class="grid">
    <tbody>
      <tr><th>Total items</th><td><?= (int) $progress['total'] ?></td></tr>
      <tr><th>Approved</th><td><span class="badge b-ok"><?= (int) $progress['approved'] ?></span></td></tr>
      <tr><th>Revoked</th><td><span class="badge b-risk"><?= (int) $progress['revoked'] ?></span></td></tr>
      <tr><th>Pending</th><td><span class="badge b-warn"><?= (int) $progress['pending'] ?></span></td></tr>
    </tbody>
  </table>
  <?php if ($progress['revoked'] > 0): ?>
  <p class="empty-state-sm">"Revoked" items above are a reviewer's decision that access should NOT continue — this app does not auto-remove it (no connector here can push a revocation back to a source system; see <code>OPEN_ITEMS.md</code>'s remediation-task-management entry). Act on these through the Access Matrix or the account's own detail page.</p>
  <?php endif; ?>
  <?php if ($canManage && $campaign['status'] === 'active'): ?>
  <div class="field-row mt-12">
    <form method="post" action="/app/campaigns/complete">
      <?= $csrf ?><input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
      <button type="submit" class="btn primary" data-confirm="Mark this campaign completed? Any still-pending items will remain recorded as pending.">Complete Campaign</button>
    </form>
    <form method="post" action="/app/campaigns/cancel">
      <?= $csrf ?><input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
      <button type="submit" class="btn danger" data-confirm="Cancel this campaign? Items already decided keep their decisions, but the campaign stops accepting new ones.">Cancel Campaign</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<form method="get" class="filter-bar">
  <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
  <div class="field">
    <label for="decision">Decision</label>
    <select id="decision" name="decision">
      <option value="">All</option>
      <?php foreach (['pending', 'approved', 'revoked'] as $d): ?>
      <option value="<?= $d ?>" <?= $filters['decision'] === $d ? 'selected' : '' ?>><?= ucwords($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<?php if (isset($_GET['bulk_ok'])): ?>
<div class="card"><span class="badge b-ok"><?= (int) $_GET['bulk_ok'] ?> decided</span>
  <?php if ((int) ($_GET['bulk_failed'] ?? 0) > 0): ?> <span class="badge b-risk"><?= (int) $_GET['bulk_failed'] ?> skipped — already decided</span><?php endif; ?>
</div>
<?php endif; ?>
<?php if (!empty($_GET['granted'])): ?>
<div class="card"><span class="badge b-ok">Entitlement granted</span></div>
<?php endif; ?>

<?php if ($canManage): ?>
<p class="empty-state-sm">As an admin you can decide or grant for any item below, not only ones assigned to you as reviewer — every decision still records who acted and when.</p>
<form method="post" action="/app/campaigns/decide-bulk" id="bulkForm" class="filter-bar">
  <?= $csrf ?>
  <input type="hidden" name="return_campaign_id" value="<?= (int) $campaign['id'] ?>" form="bulkForm">
  <input type="text" name="note" placeholder="Note for selected items (optional)" form="bulkForm">
  <button type="submit" name="decision" value="approved" class="btn sm primary" form="bulkForm" data-confirm="Approve every selected item?">Approve Selected</button>
  <button type="submit" name="decision" value="revoked" class="btn sm danger" form="bulkForm" data-confirm="Mark every selected item as revoked? This records the decision — it does not itself remove the access.">Revoke Selected</button>
  <span id="selectedCount" class="hint"></span>
</form>
<?php endif; ?>

<div class="table-wrap">
<table class="grid">
  <thead><tr>
    <?php if ($canManage): ?><th><input type="checkbox" id="selectAll" class="w-auto"></th><?php endif; ?>
    <th>Person</th><th>Account</th><th>Application</th><th>Entitlement</th><th>Reviewer</th><th>Decision</th><th>Decided At</th><th>Decided By</th>
    <?php if ($canManage): ?><th></th><?php endif; ?>
  </tr></thead>
  <tbody>
    <?php if ($items === []): ?>
    <tr class="empty-row"><td colspan="<?= $canManage ? 10 : 8 ?>" class="empty-state-sm">No items match this filter.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $i): ?>
    <tr>
      <?php if ($canManage): ?>
      <td><?php if ($i['decision'] === 'pending'): ?><input type="checkbox" name="item_id[]" value="<?= (int) $i['id'] ?>" form="bulkForm" class="row-select w-auto"><?php endif; ?></td>
      <?php endif; ?>
      <td><?= Security::h($i['person_name'] ?? '—') ?></td>
      <td><?= Security::h($i['username'] ?? $i['external_account_id']) ?></td>
      <td><?= Security::h($i['application_name']) ?></td>
      <td><?= Security::h($i['entitlement_name']) ?><?= $i['is_privileged'] ? ' <span class="badge b-risk">Privileged</span>' : '' ?></td>
      <td><?= Security::h($i['reviewer_name'] ?? '—') ?></td>
      <td>
        <?php $dcls = $i['decision'] === 'approved' ? 'b-ok' : ($i['decision'] === 'revoked' ? 'b-risk' : 'b-warn'); ?>
        <span class="badge <?= $dcls ?>"><?= Security::h(ucwords($i['decision'])) ?></span>
      </td>
      <td><?= $i['decided_at'] ? Security::h(substr((string) $i['decided_at'], 0, 16)) : '—' ?></td>
      <td><?= Security::h($i['decided_by_name'] ?? '—') ?></td>
      <?php if ($canManage): ?>
      <td>
        <?php if ($i['decision'] === 'pending'): ?>
        <form method="post" action="/app/campaigns/decide" class="field-row">
          <?= $csrf ?>
          <input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>">
          <input type="hidden" name="return_campaign_id" value="<?= (int) $campaign['id'] ?>">
          <input type="text" name="note" placeholder="Note (optional)">
          <button type="submit" name="decision" value="approved" class="btn sm primary">Approve</button>
          <button type="submit" name="decision" value="revoked" class="btn sm danger" data-confirm="Mark this access as revoked? This records the decision — it does not itself remove the access.">Revoke</button>
        </form>
        <details class="mt-8">
          <summary class="btn sm">+ Add entitlement</summary>
          <form method="post" action="/app/campaigns/grant" class="field-row mt-8"
                data-add-entitlement data-application-id="<?= (int) $i['application_id'] ?>" data-account-id="<?= (int) $i['system_account_id'] ?>">
            <?= $csrf ?>
            <input type="hidden" name="system_account_id" value="<?= (int) $i['system_account_id'] ?>">
            <input type="hidden" name="return_campaign_id" value="<?= (int) $campaign['id'] ?>">
            <select name="entitlement_id" class="entitlement-select" disabled required>
              <option value="">Loading…</option>
            </select>
            <button type="submit" class="btn sm primary" data-confirm="Grant this entitlement to the account now? This creates real access in Verity's own record immediately.">Grant</button>
          </form>
        </details>
        <?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="pager">
  <div>Showing <?= count($items) ?> of <?= number_format($total) ?></div>
  <div class="pages">
    <?php $qs = $_GET; ?>
    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $lastPage ?></span>
    <?php if ($page < $lastPage): $qs['page'] = $page + 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
