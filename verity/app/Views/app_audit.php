<?php
/** @var array $events @var int $total @var int $page @var int $pageSize */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
$lastPage = max(1, (int) ceil($total / $pageSize));
?>
<div class="page-header">
  <div><h1 class="page-title">Audit History</h1><p>Append-only. Corrections create additional records — nothing here is ever overwritten.</p></div>
</div>

<form method="get" class="filter-bar">
  <div class="field"><label for="action">Action contains</label><input type="text" id="action" name="action" value="<?= Security::h($_GET['action'] ?? '') ?>" placeholder="e.g. account.link"></div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>When (UTC)</th><th>Actor</th><th>Action</th><th>Target</th><th>Result</th><th>Correlation</th></tr></thead>
  <tbody>
    <?php if ($events === []): ?>
    <tr class="empty-row"><td colspan="6" class="empty-state-sm">No audit events match this filter.</td></tr>
    <?php endif; ?>
    <?php foreach ($events as $e): ?>
    <tr>
      <td><?= Security::h(substr((string) $e['created_at'], 0, 19)) ?></td>
      <td><?= Security::h($e['actor_name'] ?? 'system') ?></td>
      <td><code><?= Security::h($e['action']) ?></code></td>
      <td><?= Security::h($e['target'] ?? '—') ?></td>
      <td>
        <?php $r = $e['result']; $cls = $r === 'success' ? 'b-ok' : ($r === 'denied' ? 'b-warn' : 'b-risk'); ?>
        <span class="badge <?= $cls ?>"><?= Security::h(ucwords($r)) ?></span>
      </td>
      <td class="hint"><?= Security::h($e['correlation_id'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="pager">
  <div>Showing <?= count($events) ?> of <?= number_format($total) ?></div>
  <div class="pages">
    <?php $qs = $_GET; ?>
    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $lastPage ?></span>
    <?php if ($page < $lastPage): $qs['page'] = $page + 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
