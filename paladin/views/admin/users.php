<?php
$pageTitle    = 'Users';
$activeModule = 'admin_users';
$breadcrumbs  = [['Administration', '/admin'], ['Users', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Users</h1><p class="page-subtitle">Manage accounts, roles and access</p></div>
  <div class="page-actions"><a href="/admin/users/import" class="btn btn-ghost"><i class="bi bi-filetype-csv"></i> Import CSV</a><a href="/admin/users/create" class="btn btn-primary"><i class="bi bi-person-plus-fill"></i> New User</a></div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Last Login</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $usr): $uid = (int)$usr['id']; ?>
      <tr>
        <td><div style="display:flex;align-items:center;gap:10px"><?= View::avatar($usr['name']) ?><span><?= Security::h($usr['name']) ?><?php if (!empty($usr['title'])): ?><br><span class="form-hint"><?= Security::h($usr['title']) ?></span><?php endif; ?></span></div></td>
        <td class="form-hint"><?= Security::h($usr['email']) ?></td>
        <td><span class="badge badge-blue"><?= Security::h(Auth::roleLabel($usr['role'])) ?></span></td>
        <td class="form-hint"><?= Security::h($usr['department'] ?: '—') ?></td>
        <td><?php if (in_array(strtolower((string)$usr['is_active']), ['1','t','true','yes','on'], true)): ?><span class="badge badge-green">Active</span><?php else: ?><span class="badge badge-gray">Inactive</span><?php endif; ?></td>
        <td class="form-hint"><?= $usr['last_login'] ? Security::h(View::timeAgo($usr['last_login'])) : 'never' ?></td>
        <td style="text-align:right;white-space:nowrap">
          <button type="button" class="btn btn-sm btn-ghost" data-edit-toggle="#edit-<?= $uid ?>"><i class="bi bi-pencil"></i> Edit</button>
          <a href="/admin/users/<?= $uid ?>/permissions" class="btn btn-sm btn-ghost"><i class="bi bi-shield-lock"></i> Perms</a>
        </td>
      </tr>
      <tr id="edit-<?= $uid ?>" class="user-edit-row" hidden>
        <td colspan="7" style="background:var(--bg-subtle, rgba(0,0,0,.02))">
          <form method="POST" action="/admin/users/<?= $uid ?>/update" class="form-row" style="flex-wrap:wrap;gap:12px;align-items:flex-end;margin:6px 0">
            <?= Security::csrfField() ?>
            <div class="form-group" style="margin:0;min-width:160px"><label class="form-label">Name</label><input type="text" name="name" class="form-control" value="<?= Security::h($usr['name']) ?>" required></div>
            <div class="form-group" style="margin:0;min-width:200px"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= Security::h($usr['email']) ?>" required></div>
            <div class="form-group" style="margin:0"><label class="form-label">Role</label><select name="role" class="form-select"><?php foreach (Auth::allRoleOptions() as $rk => $rl): ?><option value="<?= Security::h($rk) ?>" <?= $usr["role"]===$rk?"selected":"" ?>><?= Security::h($rl) ?></option><?php endforeach; ?></select></div>
            <div class="form-group" style="margin:0;min-width:140px"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="<?= Security::h($usr['department'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0;min-width:140px"><label class="form-label">Title</label><input type="text" name="title" class="form-control" value="<?= Security::h($usr['title'] ?? '') ?>"></div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button>
          </form>
          <?php if ($uid !== Auth::id()): ?>
          <form method="POST" action="/admin/users/<?= $uid ?>/toggle" data-confirm="Change this user's active status?" style="margin:0 0 8px">
            <?= Security::csrfField() ?>
            <?php $isActive = in_array(strtolower((string)$usr['is_active']), ['1','t','true','yes','on'], true); ?>
            <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-danger' : 'btn-success' ?>"><i class="bi bi-<?= $isActive ? 'person-x' : 'person-check' ?>"></i> <?= $isActive ? 'Deactivate' : 'Activate' ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?>
      <tr><td colspan="7" class="empty-row"><div class="empty-state-sm"><i class="bi bi-people"></i><p>No users yet.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>

<script nonce="<?= Security::nonce() ?>">
(function () {
  document.querySelectorAll('.user-edit-row').forEach(function (r) { r.hidden = false; r.style.display = 'none'; });
  document.querySelectorAll('[data-edit-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.querySelector(btn.getAttribute('data-edit-toggle'));
      if (row) row.style.display = (row.style.display === 'none' || !row.style.display) ? 'table-row' : 'none';
    });
  });
})();
</script>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
