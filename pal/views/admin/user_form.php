<?php
$pageTitle    = 'New User';
$activeModule = 'admin_users';
$breadcrumbs  = [['Administration', '/admin'], ['Users', '/admin/users'], ['New User', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">New User</h1><p class="page-subtitle">Create an account and assign a role</p></div>
</div>

<div class="card" style="max-width:680px">
  <div class="card-body">
    <form method="POST" action="/admin/users/create">
      <?= Security::csrfField() ?>
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="f_name">Name</label><input type="text" id="f_name" name="name" class="form-control" required></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="f_email">Email</label><input type="email" id="f_email" name="email" class="form-control" required></div>
      </div>
      <div class="form-group"><label class="form-label" for="f_password">Password</label><input type="password" id="f_password" name="password" class="form-control" autocomplete="new-password" required><div class="form-hint">Must meet the configured password policy.</div></div>
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="f_role">Role</label><select id="f_role" name="role" class="form-select"><?php foreach (Auth::roleKeys() as $rk): ?><option value="<?= Security::h($rk) ?>" <?= $rk==='viewer'?'selected':'' ?>><?= Security::h(Auth::roleLabel($rk)) ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="f_dept">Department</label><input type="text" id="f_dept" name="department" class="form-control"></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="f_title">Title</label><input type="text" id="f_title" name="title" class="form-control"></div>
      </div>
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="force_password_change" value="1" checked> Force password change at next login
        </label>
      </div>
      <div class="form-actions">
        <a href="/admin/users" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus-fill"></i> Create User</button>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
