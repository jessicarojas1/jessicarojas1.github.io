<?php
/** @var array $filters @var array $people @var array $departments @var int $total @var int $page @var int $pageSize */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
$lastPage = max(1, (int) ceil($total / $pageSize));
?>
<div class="page-header">
  <div><h1 class="page-title">Identity Directory</h1><p>Canonical person records. Each may link to multiple system accounts.</p></div>
</div>

<form method="get" class="filter-bar">
  <div class="field"><label for="q">Search</label><input type="text" id="q" name="q" value="<?= Security::h($filters['search']) ?>" placeholder="Name, email, employee ID"></div>
  <div class="field">
    <label for="department">Department</label>
    <select id="department" name="department">
      <option value="">All</option>
      <?php foreach ($departments as $d): ?>
      <option value="<?= Security::h($d) ?>" <?= $filters['department'] === $d ? 'selected' : '' ?>><?= Security::h($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="employment_status">Status</label>
    <select id="employment_status" name="employment_status">
      <option value="">All</option>
      <?php foreach (['active' => 'Active', 'on_leave' => 'On Leave', 'terminated' => 'Terminated'] as $k => $v): ?>
      <option value="<?= $k ?>" <?= $filters['employment_status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="identity_type">Identity Type</label>
    <select id="identity_type" name="identity_type">
      <option value="">All</option>
      <?php foreach (['employee','contractor','guest','external_partner','service_identity','shared_account','non_human_identity'] as $t): ?>
      <option value="<?= $t ?>" <?= $filters['identity_type'] === $t ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $t)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Name</th><th>Department</th><th>Title</th><th>Manager</th><th>Type</th><th>Status</th><th class="num">Accounts</th></tr></thead>
  <tbody>
    <?php if ($people === []): ?>
    <tr class="empty-row"><td colspan="7" class="empty-state-sm">No identities match these filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($people as $p): ?>
    <tr>
      <td><a href="/app/identities/view?id=<?= (int) $p['id'] ?>"><?= Security::h($p['display_name']) ?></a></td>
      <td><?= Security::h($p['department'] ?? '—') ?></td>
      <td><?= Security::h($p['position_title'] ?? '—') ?></td>
      <td><?= Security::h($p['manager_name'] ?? '—') ?></td>
      <td><?= Security::h(ucwords(str_replace('_', ' ', $p['identity_type']))) ?></td>
      <td>
        <?php if ($p['employment_status'] === 'active'): ?><span class="badge b-ok">Active</span>
        <?php elseif ($p['employment_status'] === 'terminated'): ?><span class="badge b-risk">Terminated</span>
        <?php else: ?><span class="badge b-warn">On Leave</span><?php endif; ?>
      </td>
      <td class="num"><?= (int) $p['account_count'] ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="pager">
  <div>Showing <?= count($people) ?> of <?= number_format($total) ?></div>
  <div class="pages">
    <?php $qs = $_GET; ?>
    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $lastPage ?></span>
    <?php if ($page < $lastPage): $qs['page'] = $page + 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
