<?php
$breadcrumbs  = $breadcrumbs ?? [['Platform', null], ['Tenants', null]];
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$tenants  = $tenants  ?? [];
$activeId = $activeId ?? 1;
$homeId   = $homeId   ?? 1;
$switched = $activeId !== $homeId;
?>

<?php if ($flashSuccess): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($flashError) ?></div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-buildings" style="margin-right:8px;"></i>Tenants</h1>
    <p class="page-subtitle">Platform-admin tenant context. Switching is explicit, audited, and reverts automatically within an hour.</p>
  </div>
  <?php if ($switched): ?>
    <div class="page-actions">
      <form method="POST" action="/platform/exit-tenant">
        <?= Security::csrfField() ?>
        <button type="submit" class="btn btn-secondary"><i class="bi bi-box-arrow-left"></i> Return to home tenant</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<?php if ($switched): ?>
  <div class="alert-box warning">
    <i class="bi bi-exclamation-triangle-fill"></i>
    You are acting inside tenant #<?= Security::h((string)$activeId) ?> (your home tenant is #<?= Security::h((string)$homeId) ?>). All actions are attributed to you and audited.
  </div>
<?php endif; ?>

<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th scope="col">ID</th>
        <th scope="col">Name</th>
        <th scope="col">Slug</th>
        <th scope="col">Status</th>
        <th scope="col" style="text-align:right;">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$tenants): ?>
        <tr><td colspan="5" class="empty-row"><div class="empty-state-sm">No tenants found.</div></td></tr>
      <?php else: foreach ($tenants as $t): ?>
        <?php $tid = (int)$t['id']; $isActive = !empty($t['is_active']); ?>
        <tr>
          <td><?= Security::h((string)$tid) ?></td>
          <td><?= Security::h($t['name']) ?></td>
          <td><code><?= Security::h($t['slug']) ?></code></td>
          <td>
            <?php if ($isActive): ?>
              <span class="badge badge-success">Active</span>
            <?php else: ?>
              <span class="badge badge-muted">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;">
            <?php if ($tid === $activeId): ?>
              <span class="badge badge-primary"><i class="bi bi-check-lg"></i> Current</span>
            <?php elseif ($isActive): ?>
              <form method="POST" action="/platform/switch-tenant" style="display:inline;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="tenant_id" value="<?= Security::h((string)$tid) ?>">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-right"></i> Switch</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
