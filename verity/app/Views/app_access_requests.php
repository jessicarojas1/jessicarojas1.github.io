<?php
/** @var array $requests @var bool $canViewAll @var bool $canApproveOwned @var bool $canCreate @var array $applications @var int $total @var int $page @var int $pageSize @var array $filters @var string $csrf @var array $user */
use Verity\Support\Security;

$appScript = '/assets/access_requests.js';
require __DIR__ . '/partials/app_header.php';
$lastPage = max(1, (int) ceil($total / $pageSize));
$myPersonId = $user['person_id'] ?? null;
$myUserId = (int) $user['id'];
?>
<div class="page-header">
  <div><h1 class="page-title">Access Requests</h1><p>Request an entitlement for an existing account; the application's system owner (or an admin) approves or denies it.</p></div>
</div>

<form method="get" class="filter-bar">
  <div class="field">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All</option>
      <?php foreach (['pending', 'approved', 'denied', 'cancelled'] as $s): ?>
      <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucwords($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Person</th><th>Account</th><th>Application</th><th>Entitlement</th><th>Requested By</th><th>Approver</th><th>Status</th><th></th></tr></thead>
  <tbody>
    <?php if ($requests === []): ?>
    <tr class="empty-row"><td colspan="8" class="empty-state-sm">No access requests match this filter.</td></tr>
    <?php endif; ?>
    <?php foreach ($requests as $r): ?>
    <?php $canDecideThis = $canViewAll || ($canApproveOwned && $myPersonId !== null && (int) $r['system_owner_person_id'] === (int) $myPersonId); ?>
    <tr>
      <td><?= Security::h($r['person_name'] ?? '—') ?></td>
      <td><a href="/app/accounts/view?id=<?= (int) $r['system_account_id'] ?>"><?= Security::h($r['username'] ?? $r['external_account_id']) ?></a></td>
      <td><?= Security::h($r['application_name']) ?></td>
      <td><?= Security::h($r['entitlement_name']) ?><?= $r['is_privileged'] ? ' <span class="badge b-risk">Privileged</span>' : '' ?></td>
      <td><?= Security::h($r['requested_by_name'] ?? '—') ?></td>
      <td><?= Security::h($r['approver_name'] ?? 'No designated owner') ?></td>
      <td>
        <?php $scls = $r['status'] === 'approved' ? 'b-ok' : ($r['status'] === 'pending' ? 'b-warn' : 'b-neutral'); ?>
        <span class="badge <?= $scls ?>"><?= Security::h(ucwords($r['status'])) ?></span>
      </td>
      <td>
        <?php if ($r['status'] === 'pending' && $canDecideThis): ?>
        <form method="post" action="/app/access-requests/approve" class="field-row">
          <?= $csrf ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="text" name="note" placeholder="Note (optional)">
          <button type="submit" class="btn sm primary">Approve</button>
          <button type="submit" formaction="/app/access-requests/deny" class="btn sm danger" data-confirm="Deny this access request?">Deny</button>
        </form>
        <?php elseif ($r['status'] === 'pending' && (int) $r['requested_by_user_id'] === $myUserId): ?>
        <form method="post" action="/app/access-requests/cancel">
          <?= $csrf ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button type="submit" class="btn sm" data-confirm="Withdraw this request?">Cancel</button>
        </form>
        <?php elseif ($r['status'] !== 'pending'): ?>
        <?= Security::h($r['decided_by_name'] ?? '—') ?><?= $r['decision_note'] ? ' — ' . Security::h($r['decision_note']) : '' ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="pager">
  <div>Showing <?= count($requests) ?> of <?= number_format($total) ?></div>
  <div class="pages">
    <?php $qs = $_GET; ?>
    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $lastPage ?></span>
    <?php if ($page < $lastPage): $qs['page'] = $page + 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php if ($canCreate): ?>
<div class="card mt-16">
  <h2>New Request</h2>
  <p class="empty-state-sm">Requests an entitlement for an account that already exists in Verity's inventory — this app doesn't provision new accounts, so the account you're requesting access on must already be on record.</p>
  <form method="post" action="/app/access-requests/create" id="newRequestForm">
    <?= $csrf ?>
    <div class="field-row">
      <div class="field">
        <label for="ar_application_id">Application</label>
        <select id="ar_application_id">
          <option value="">Select an application</option>
          <?php foreach ($applications as $a): ?><option value="<?= (int) $a['id'] ?>"><?= Security::h($a['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="ar_system_account_id">Account</label>
        <select id="ar_system_account_id" name="system_account_id" required disabled>
          <option value="">Select an application first</option>
        </select>
      </div>
      <div class="field">
        <label for="ar_entitlement_id">Entitlement</label>
        <select id="ar_entitlement_id" name="entitlement_id" required disabled>
          <option value="">Select an account first</option>
        </select>
      </div>
    </div>
    <div class="field"><label for="ar_justification">Justification</label><textarea id="ar_justification" name="justification" rows="2" required></textarea></div>
    <button type="submit" class="btn primary">Submit Request</button>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
