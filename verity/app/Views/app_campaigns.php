<?php
/** @var array $campaigns @var bool $canManage @var array $applications @var array $people @var int $total @var int $page @var int $pageSize @var array $filters @var string $csrf */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
$lastPage = max(1, (int) ceil($total / $pageSize));
?>
<div class="page-header">
  <div><h1 class="page-title">Certification Campaigns</h1><p>Access-review campaigns — a frozen snapshot at launch, a reviewer decision per item, and an audited result.</p></div>
</div>

<form method="get" class="filter-bar">
  <div class="field">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All</option>
      <?php foreach (['active', 'completed', 'cancelled'] as $s): ?>
      <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucwords($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Name</th><th>Scope</th><th>Status</th><th class="num">Items</th><th class="num">Decided</th><th class="num">Revoked</th><th>Due</th></tr></thead>
  <tbody>
    <?php if ($campaigns === []): ?>
    <tr class="empty-row"><td colspan="7" class="empty-state-sm">No campaigns yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($campaigns as $c): ?>
    <tr>
      <td><a href="/app/campaigns/view?id=<?= (int) $c['id'] ?>"><?= Security::h($c['name']) ?></a></td>
      <td><?= Security::h(ucwords($c['scope_type'])) ?><?= $c['scope_application_name'] ? ' — ' . Security::h($c['scope_application_name']) : '' ?></td>
      <td>
        <?php $scls = $c['status'] === 'active' ? 'b-ok' : ($c['status'] === 'completed' ? 'b-neutral' : 'b-risk'); ?>
        <span class="badge <?= $scls ?>"><?= Security::h(ucwords($c['status'])) ?></span>
      </td>
      <td class="num"><?= (int) $c['total_items'] ?></td>
      <td class="num"><?= (int) $c['decided_items'] ?></td>
      <td class="num"><?= (int) $c['revoked_items'] ?></td>
      <td><?= $c['due_at'] ? Security::h(substr((string) $c['due_at'], 0, 10)) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="pager">
  <div>Showing <?= count($campaigns) ?> of <?= number_format($total) ?></div>
  <div class="pages">
    <?php $qs = $_GET; ?>
    <?php if ($page > 1): $qs['page'] = $page - 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $lastPage ?></span>
    <?php if ($page < $lastPage): $qs['page'] = $page + 1; ?><a class="btn sm" href="?<?= Security::h(http_build_query($qs)) ?>">Next</a><?php endif; ?>
  </div>
</div>

<?php if ($canManage): ?>
<div class="card mt-16">
  <h2>Launch Campaign</h2>
  <p class="empty-state-sm">Scope is frozen the moment you launch — every currently in-scope entitlement assignment is snapshotted for review; nothing added to the system afterward joins this campaign.</p>
  <form method="post" action="/app/campaigns">
    <?= $csrf ?>
    <div class="field-row">
      <div class="field"><label for="name">Name</label><input type="text" id="name" name="name" required></div>
      <div class="field">
        <label for="scope_type">Scope</label>
        <select id="scope_type" name="scope_type" required>
          <option value="application">One application</option>
          <option value="privileged">All privileged access</option>
          <option value="all">Entire enterprise</option>
        </select>
      </div>
      <div class="field">
        <label for="scope_application_id">Application (if scope is "One application")</label>
        <select id="scope_application_id" name="scope_application_id">
          <option value="">—</option>
          <?php foreach ($applications as $a): ?><option value="<?= (int) $a['id'] ?>"><?= Security::h($a['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field-row">
      <div class="field">
        <label for="reviewer_strategy">Reviewer assignment</label>
        <select id="reviewer_strategy" name="reviewer_strategy" required>
          <option value="manager">Each account holder's manager</option>
          <option value="fixed">One named reviewer for everything</option>
        </select>
      </div>
      <div class="field">
        <label for="default_reviewer_person_id">Default / fallback reviewer</label>
        <select id="default_reviewer_person_id" name="default_reviewer_person_id" required>
          <option value="">—</option>
          <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"><?= Security::h($p['display_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label for="due_at">Due date</label><input type="date" id="due_at" name="due_at"></div>
    </div>
    <div class="field"><label for="description">Description</label><textarea id="description" name="description" rows="2"></textarea></div>
    <button type="submit" class="btn primary" data-confirm="Launch this campaign now? Scope will be frozen immediately.">Launch Campaign</button>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
