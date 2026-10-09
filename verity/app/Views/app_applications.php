<?php
/** @var array $applications @var bool $canManage @var array $people @var string $csrf */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div><h1 class="page-title">Application Catalog</h1><p>Every connected system and the ownership record behind it.</p></div>
</div>

<form method="get" class="filter-bar">
  <div class="field"><label for="q">Search</label><input type="text" id="q" name="q" value="<?= Security::h($_GET['q'] ?? '') ?>" placeholder="Application name"></div>
  <button type="submit" class="btn primary">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Application</th><th>Classification</th><th>System Owner</th><th class="num">Accounts</th><th class="num">Entitlements</th><th class="num">Connectors</th><th>Status</th></tr></thead>
  <tbody>
    <?php if ($applications === []): ?>
    <tr class="empty-row"><td colspan="7" class="empty-state-sm">No applications in the catalog yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($applications as $a): ?>
    <tr>
      <td><a href="/app/applications/view?id=<?= (int) $a['id'] ?>"><?= Security::h($a['name']) ?></a></td>
      <td><?= Security::h(ucwords($a['classification'])) ?></td>
      <td><?= Security::h($a['system_owner_name'] ?? '—') ?></td>
      <td class="num"><?= (int) $a['account_count'] ?></td>
      <td class="num"><?= (int) $a['entitlement_count'] ?></td>
      <td class="num"><?= (int) $a['connector_count'] ?></td>
      <td><?= $a['status'] === 'active' ? '<span class="badge b-ok">Active</span>' : '<span class="badge b-neutral">Inactive</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($canManage): ?>
<div class="card mt-16">
  <h2>Add Application</h2>
  <form method="post" action="/app/applications">
    <?= $csrf ?>
    <div class="field-row">
      <div class="field"><label for="name">Name</label><input type="text" id="name" name="name" required></div>
      <div class="field">
        <label for="classification">Classification</label>
        <select id="classification" name="classification">
          <?php foreach (['public','internal','confidential','restricted'] as $c): ?>
          <option value="<?= $c ?>"><?= ucwords($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="system_owner_person_id">System Owner</label>
        <select id="system_owner_person_id" name="system_owner_person_id">
          <option value="">—</option>
          <?php foreach ($people as $p): ?><option value="<?= (int) $p['id'] ?>"><?= Security::h($p['display_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="field"><label for="description">Description</label><textarea id="description" name="description" rows="2"></textarea></div>
    <button type="submit" class="btn primary">Add Application</button>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
