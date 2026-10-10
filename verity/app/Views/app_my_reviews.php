<?php
/** @var array $items @var int $total @var int $page @var int $pageSize @var string $csrf */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
$lastPage = max(1, (int) ceil($total / $pageSize));
$showAll = ($_GET['all'] ?? '') === '1';
?>
<div class="page-header">
  <div><h1 class="page-title">My Reviews</h1><p>Entitlement assignments a certification campaign has assigned to you for review.</p></div>
</div>

<form method="get" class="filter-bar">
  <div class="field">
    <label for="all">Show</label>
    <select id="all" name="all">
      <option value="0" <?= !$showAll ? 'selected' : '' ?>>Pending only</option>
      <option value="1" <?= $showAll ? 'selected' : '' ?>>All (including decided)</option>
    </select>
  </div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Campaign</th><th>Person</th><th>Account</th><th>Application</th><th>Entitlement</th><th>Due</th><th></th></tr></thead>
  <tbody>
    <?php if ($items === []): ?>
    <tr class="empty-row"><td colspan="7" class="empty-state-sm">Nothing waiting on you right now.</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $i): ?>
    <tr>
      <td><?= Security::h($i['campaign_name']) ?></td>
      <td><?= Security::h($i['person_name'] ?? '—') ?></td>
      <td><?= Security::h($i['username'] ?? $i['external_account_id']) ?></td>
      <td><?= Security::h($i['application_name']) ?></td>
      <td><?= Security::h($i['entitlement_name']) ?><?= $i['is_privileged'] ? ' <span class="badge b-risk">Privileged</span>' : '' ?></td>
      <td><?= $i['due_at'] ? Security::h(substr((string) $i['due_at'], 0, 10)) : '—' ?></td>
      <td>
        <?php if ($i['decision'] === 'pending'): ?>
        <form method="post" action="/app/campaigns/decide" class="field-row">
          <?= $csrf ?>
          <input type="hidden" name="item_id" value="<?= (int) $i['id'] ?>">
          <input type="text" name="note" placeholder="Note (optional)">
          <button type="submit" name="decision" value="approved" class="btn sm primary">Approve</button>
          <button type="submit" name="decision" value="revoked" class="btn sm danger" data-confirm="Mark this access as revoked? This records your decision — it does not itself remove the access.">Revoke</button>
        </form>
        <?php else: ?>
        <?php $dcls = $i['decision'] === 'approved' ? 'b-ok' : 'b-risk'; ?>
        <span class="badge <?= $dcls ?>"><?= Security::h(ucwords($i['decision'])) ?></span>
        <?php endif; ?>
      </td>
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
