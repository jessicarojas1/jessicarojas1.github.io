<?php
/**
 * @var array $result @var array $scope @var string $view @var array $fieldDefinitions
 * @var array $applications @var array $departments @var array $savedViews @var array $availableViews
 * @var int $page @var int $pageSize @var string $csrf
 */
use Verity\Support\Security;

$appScript = '/assets/matrix.js';
require __DIR__ . '/partials/app_header.php';
$total = $result['total'];
$rows = $result['rows'];
$lastPage = max(1, (int) ceil($total / $pageSize));
$viewLabels = [
    'enterprise' => 'Enterprise', 'person' => 'Person', 'application' => 'Application',
    'supervisor' => 'Supervisor', 'privileged' => 'Privileged Access', 'exception' => 'Exception',
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Enterprise Access Matrix</h1>
    <p>Who has access to what, discovered across every connected application. Server-side pagination — never the full inventory in the browser.</p>
  </div>
  <a class="btn" href="/app/matrix/export?<?= Security::h(http_build_query($_GET)) ?>">Export CSV (current view)</a>
</div>

<div class="matrix-tabs">
  <?php foreach ($availableViews as $v => $label): ?>
    <a href="/app/matrix?view=<?= $v ?>" class="<?= $view === $v ? 'active' : '' ?>"><?= Security::h($label) ?></a>
  <?php endforeach; ?>
  <?php if ($view === 'person' || $view === 'application'): ?>
    <span class="btn sm cursor-default"><?= Security::h($viewLabels[$view]) ?> view (opened from a detail page)</span>
  <?php endif; ?>
</div>

<?php if ($savedViews !== []): ?>
<div class="filter-bar">
  <strong class="hint">Saved views:</strong>
  <?php foreach ($savedViews as $sv): $cfg = is_string($sv['config']) ? json_decode($sv['config'], true) : $sv['config']; ?>
    <a class="btn sm" href="/app/matrix?<?= Security::h(http_build_query(array_filter($cfg))) ?>"><?= Security::h($sv['name']) ?><?= $sv['is_shared'] ? ' 🌐' : '' ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="get" class="filter-bar">
  <input type="hidden" name="view" value="<?= Security::h($view) ?>">
  <?php if ($view === 'person'): ?><input type="hidden" name="person_id" value="<?= (int) ($scope['person_id'] ?? 0) ?>"><?php endif; ?>
  <?php if ($view === 'application'): ?><input type="hidden" name="application_id" value="<?= (int) ($scope['application_id'] ?? 0) ?>"><?php endif; ?>
  <div class="field"><label for="q">Search</label><input type="text" id="q" name="q" value="<?= Security::h($scope['search']) ?>" placeholder="Person, account, entitlement, application"></div>
  <div class="field">
    <label for="department">Department</label>
    <select id="department" name="department">
      <option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= Security::h($d) ?>" <?= $scope['department'] === $d ? 'selected' : '' ?>><?= Security::h($d) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="account_status">Account Status</label>
    <select id="account_status" name="account_status">
      <option value="">All</option>
      <option value="enabled" <?= $scope['account_status'] === 'enabled' ? 'selected' : '' ?>>Enabled</option>
      <option value="disabled" <?= $scope['account_status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
    </select>
  </div>
  <div class="field">
    <label for="assignment_type">Assignment Type</label>
    <select id="assignment_type" name="assignment_type">
      <option value="">All</option>
      <?php foreach (['direct','inherited','privileged','temporary','external'] as $t): ?>
      <option value="<?= $t ?>" <?= $scope['assignment_type'] === $t ? 'selected' : '' ?>><?= ucwords($t) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary">Filter</button>
  <button type="button" class="btn sm" id="saveViewToggle">Save this view</button>
</form>

<form method="post" action="/app/matrix/saved-views" id="saveViewForm" class="card hidden mb-14">
  <?= $csrf ?>
  <input type="hidden" name="view" value="<?= Security::h($view) ?>">
  <input type="hidden" name="q" value="<?= Security::h($scope['search']) ?>">
  <input type="hidden" name="department" value="<?= Security::h($scope['department']) ?>">
  <input type="hidden" name="account_status" value="<?= Security::h($scope['account_status']) ?>">
  <input type="hidden" name="assignment_type" value="<?= Security::h($scope['assignment_type']) ?>">
  <div class="field-row">
    <div class="field"><label for="sv_name">View name</label><input type="text" id="sv_name" name="name" required></div>
    <div class="field field-end">
      <label><input type="checkbox" name="is_shared" value="1" class="check-gap">Share organization-wide</label>
    </div>
  </div>
  <button type="submit" class="btn primary sm">Save</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead>
    <tr>
      <th>Person</th><th>Department</th><th>Application</th><th>Account</th><th>Account Status</th>
      <th>Entitlement</th><th>Assignment</th><th>Risk</th><th>Granted</th><th>Expires</th>
      <?php foreach ($fieldDefinitions as $fd): ?><th><?= Security::h($fd['label']) ?></th><?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php if ($rows === []): ?>
    <tr class="empty-row"><td colspan="<?= 10 + count($fieldDefinitions) ?>" class="empty-state-sm">No access records match this view and filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= $r['person_id'] ? '<a href="/app/identities/view?id=' . (int) $r['person_id'] . '">' . Security::h($r['person_name']) . '</a>' : '<span class="badge b-warn">Unmatched</span>' ?></td>
      <td><?= Security::h($r['department'] ?? '—') ?></td>
      <td><a href="/app/applications/view?id=<?= (int) $r['application_id'] ?>"><?= Security::h($r['application_name']) ?></a></td>
      <td><a href="/app/accounts/view?id=<?= (int) $r['account_id'] ?>"><?= Security::h($r['username'] ?? $r['external_account_id']) ?></a></td>
      <td><?= $r['account_status'] === 'enabled' ? '<span class="badge b-ok">Enabled</span>' : '<span class="badge b-neutral">Disabled</span>' ?></td>
      <td><?= $r['entitlement_name'] ? Security::h($r['entitlement_name']) . ($r['is_privileged'] ? ' <span class="badge b-risk">Privileged</span>' : '') : '<span class="hint">No entitlements</span>' ?></td>
      <td><?= $r['assignment_type'] ? Security::h(ucwords($r['assignment_type'])) : '—' ?></td>
      <td>
        <?php if ($r['risk_level']): $cls = $r['risk_level'] === 'low' ? 'b-ok' : ($r['risk_level'] === 'medium' ? 'b-warn' : 'b-risk'); ?>
          <span class="badge <?= $cls ?>"><?= Security::h(ucwords($r['risk_level'])) ?></span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td><?= $r['granted_at'] ? Security::h(substr((string) $r['granted_at'], 0, 10)) : '—' ?></td>
      <td>
        <?php if ($r['expires_at']): $expired = strtotime((string) $r['expires_at']) < time(); ?>
          <span class="<?= $expired ? 'badge b-risk' : '' ?>"><?= Security::h(substr((string) $r['expires_at'], 0, 10)) ?></span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <?php foreach ($fieldDefinitions as $fd): ?>
        <td class="dynamic-col"><?= Security::h((string) ($r['dynamic'][$fd['field_key']] ?? '—')) ?></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="pager">
  <div>Showing <?= count($rows) ?> of <?= number_format($total) ?> access records</div>
  <div class="pages">
    <?php $qs = $_GET; ?>
    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $lastPage ?></span>
    <?php if ($page < $lastPage): $qs['page'] = $page + 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
