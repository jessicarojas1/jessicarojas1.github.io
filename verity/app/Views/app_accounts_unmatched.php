<?php
/** @var array $filters @var array $accounts @var array $applications @var int $total @var int $page @var int $pageSize @var string $csrf */
use Verity\Support\Security;

$appScript = '/assets/accounts.js';
require __DIR__ . '/partials/app_header.php';
$lastPage = max(1, (int) ceil($total / $pageSize));
?>
<div class="page-header">
  <div><h1 class="page-title">Unmatched Accounts</h1><p>Discovered accounts with no linked identity. Correlation is always manual and audited — never an automatic merge.</p></div>
</div>

<form method="get" class="filter-bar">
  <div class="field"><label for="q">Search</label><input type="text" id="q" name="q" value="<?= Security::h($filters['search']) ?>" placeholder="Username or external ID"></div>
  <div class="field">
    <label for="application_id">Application</label>
    <select id="application_id" name="application_id">
      <option value="">All</option>
      <?php foreach ($applications as $a): ?>
      <option value="<?= (int) $a['id'] ?>" <?= (int) $filters['application_id'] === (int) $a['id'] ? 'selected' : '' ?>><?= Security::h($a['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Application</th><th>Account</th><th>Type</th><th>Status</th><th>Discovered</th><th>Suggested Match</th><th></th></tr></thead>
  <tbody>
    <?php if ($accounts === []): ?>
    <tr class="empty-row"><td colspan="7" class="empty-state-sm">No unmatched accounts — every discovered account is linked to an identity.</td></tr>
    <?php endif; ?>
    <?php foreach ($accounts as $a): ?>
    <tr>
      <td><?= Security::h($a['application_name']) ?></td>
      <td><?= Security::h($a['username'] ?? $a['external_account_id']) ?></td>
      <td><?= Security::h(ucwords($a['account_type'])) ?></td>
      <td><?= $a['status'] === 'enabled' ? '<span class="badge b-ok">Enabled</span>' : '<span class="badge b-neutral">Disabled</span>' ?></td>
      <td><?= Security::h(substr((string) $a['created_at'], 0, 10)) ?></td>
      <td><?php if ($a['suggestion'] !== null): ?>
        <span class="badge <?= $a['suggestion']['confidence'] === 'high' ? 'b-ok' : 'b-warn' ?>" title="<?= Security::h($a['suggestion']['reason']) ?>"><?= Security::h($a['suggestion']['person_name']) ?></span>
      <?php else: ?>
        <span class="badge b-neutral">None</span>
      <?php endif; ?></td>
      <td><a class="btn sm" href="/app/accounts/view?id=<?= (int) $a['id'] ?>">Link / Review</a></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="pager">
  <div>Showing <?= count($accounts) ?> of <?= number_format($total) ?></div>
  <div class="pages">
    <?php $qs = $_GET; ?>
    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $lastPage ?></span>
    <?php if ($page < $lastPage): $qs['page'] = $page + 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
