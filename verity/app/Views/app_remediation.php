<?php
/** @var array $tasks @var bool $canManage @var int $total @var int $page @var int $pageSize @var array $filters @var string $csrf */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
$lastPage = max(1, (int) ceil($total / $pageSize));
?>
<div class="page-header">
  <div><h1 class="page-title">Remediation Tasks</h1><p>Access findings flagged for action — from a certification campaign's "revoked" decision, or flagged manually from an account's detail page. Resolving a task records that it was handled; this app does not remove access on its own.</p></div>
</div>

<form method="get" class="filter-bar">
  <div class="field">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All</option>
      <?php foreach (['open', 'resolved', 'dismissed'] as $s): ?>
      <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucwords($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Person</th><th>Account</th><th>Application</th><th>Type</th><th>Entitlement</th><th>Source</th><th>Status</th><th>Opened</th><th></th></tr></thead>
  <tbody>
    <?php if ($tasks === []): ?>
    <tr class="empty-row"><td colspan="9" class="empty-state-sm">No remediation tasks match this filter.</td></tr>
    <?php endif; ?>
    <?php foreach ($tasks as $t): ?>
    <tr>
      <td><?= Security::h($t['person_name'] ?? '—') ?></td>
      <td><a href="/app/accounts/view?id=<?= (int) $t['system_account_id'] ?>"><?= Security::h($t['username'] ?? $t['external_account_id']) ?></a></td>
      <td><?= Security::h($t['application_name']) ?></td>
      <td><?= Security::h(ucwords(str_replace('_', ' ', $t['task_type']))) ?></td>
      <td><?= Security::h($t['entitlement_name'] ?? 'Whole account') ?></td>
      <td><?= $t['source'] === 'campaign' ? 'Campaign revoke' : 'Manual' ?></td>
      <td>
        <?php $tcls = $t['status'] === 'open' ? 'b-warn' : ($t['status'] === 'resolved' ? 'b-ok' : 'b-neutral'); ?>
        <span class="badge <?= $tcls ?>"><?= Security::h(ucwords($t['status'])) ?></span>
      </td>
      <td><?= Security::h(substr((string) $t['created_at'], 0, 10)) ?></td>
      <td>
        <?php if ($t['status'] === 'open' && $canManage): ?>
        <form method="post" action="/app/remediation/resolve" class="field-row">
          <?= $csrf ?>
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <input type="text" name="note" placeholder="Note (optional)">
          <button type="submit" class="btn sm primary">Resolve</button>
          <button type="submit" formaction="/app/remediation/dismiss" class="btn sm" data-confirm="Dismiss this task without resolving it?">Dismiss</button>
        </form>
        <?php elseif ($t['status'] !== 'open'): ?>
        <?= Security::h($t['resolved_by_name'] ?? '—') ?><?= $t['resolution_note'] ? ' — ' . Security::h($t['resolution_note']) : '' ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="pager">
  <div>Showing <?= count($tasks) ?> of <?= number_format($total) ?></div>
  <div class="pages">
    <?php $qs = $_GET; ?>
    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $lastPage ?></span>
    <?php if ($page < $lastPage): $qs['page'] = $page + 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
