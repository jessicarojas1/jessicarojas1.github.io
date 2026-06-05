<?php
$pageTitle    = 'User Management';
$activeModule = 'admin_users';
$breadcrumbs  = [['Admin','/admin'],['Users',null]];
ob_start();
?>

<?php if (!empty($_GET['created'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> User created.</div><?php endif; ?>
<?php if (!empty($_GET['updated'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> User updated.</div><?php endif; ?>
<?php if (!empty($_GET['deleted'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> User deactivated.</div><?php endif; ?>
<?php if (!empty($_SESSION['user_errors'])): ?>
  <div class="alert-box error">
    <ul style="margin:0;padding-left:16px"><?php foreach($_SESSION['user_errors'] as $e): ?><li><?= Security::h($e) ?></li><?php endforeach; unset($_SESSION['user_errors']); ?></ul>
  </div>
<?php endif; ?>

<div class="page-header">
  <h1 class="page-title">User Management</h1>
  <button class="btn btn-secondary" data-show-modal="importUsersModal"><i class="bi bi-upload"></i> Import Users</button>
  <button class="btn btn-primary" data-show-modal="createUserModal"><i class="bi bi-person-plus-fill"></i> New User</button>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr <?= !$u['is_active'] ? 'class="row-muted"' : '' ?>>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <div class="user-avatar sm"><?= strtoupper(substr($u['name'],0,1)) ?></div>
                <strong><?= Security::h($u['name']) ?></strong>
              </div>
            </td>
            <td><?= Security::h($u['email']) ?></td>
            <td><span class="role-badge role-<?= Security::h($u['role']) ?>"><?= Security::h(ucfirst($u['role'])) ?></span></td>
            <td><?= Security::h($u['department'] ?? '—') ?></td>
            <td><?= $u['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Inactive</span>' ?></td>
            <td class="text-muted text-sm"><?= $u['last_login'] ? date('M j, Y g:ia', strtotime($u['last_login'])) : 'Never' ?></td>
            <td>
              <div class="action-btns">
                <button type="button" class="btn btn-ghost btn-sm"
                        data-user="<?= htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') ?>"
                        data-click="editUserFromBtn"><i class="bi bi-pencil"></i></button>
                <?php if ($u['id'] !== Auth::id()): ?>
                <form method="POST" action="/admin/users/<?= $u['id'] ?>/delete" style="display:inline">
                  <?= Security::csrfField() ?>
                  <button class="btn btn-ghost btn-sm text-danger" data-confirm-click="Deactivate this user?"><i class="bi bi-person-slash"></i></button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create User Modal -->
<div class="modal-overlay" id="createUserModal" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3><i class="bi bi-person-plus-fill"></i> New User</h3><button data-close-modal="createUserModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <form method="POST" action="/admin/users/create">
        <?= Security::csrfField() ?>
        <div class="form-row">
          <div class="form-group"><label class="form-label required">Full Name</label><input type="text" name="name" class="form-control" required></div>
          <div class="form-group"><label class="form-label required">Email</label><input type="email" name="email" class="form-control" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Department</label><input type="text" name="department" class="form-control"></div>
          <div class="form-group"><label class="form-label">Job Title</label><input type="text" name="job_title" class="form-control"></div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Role</label>
            <select name="role" class="form-control">
              <?php foreach (['admin'=>'Administrator','manager'=>'Manager','auditor'=>'Auditor','analyst'=>'Analyst','viewer'=>'Viewer'] as $v=>$l): ?>
                <option value="<?= $v ?>"><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label required">Password</label>
            <input type="password" id="newUserPassword" name="password" class="form-control" required placeholder="Min 12 chars, upper, number, special" autocomplete="new-password">
            <div id="pwStrength" style="margin-top:8px;display:flex;flex-direction:column;gap:4px;font-size:0.8rem;">
              <div id="pw-len"    style="color:var(--text-muted);"><i class="bi bi-x-circle-fill" style="margin-right:4px;"></i>At least 12 characters</div>
              <div id="pw-upper"  style="color:var(--text-muted);"><i class="bi bi-x-circle-fill" style="margin-right:4px;"></i>At least 1 uppercase letter</div>
              <div id="pw-num"    style="color:var(--text-muted);"><i class="bi bi-x-circle-fill" style="margin-right:4px;"></i>At least 1 number</div>
              <div id="pw-special"style="color:var(--text-muted);"><i class="bi bi-x-circle-fill" style="margin-right:4px;"></i>At least 1 special character (!@#$%^&amp;*...)</div>
            </div>
          </div>
        </div>
        <div class="form-actions"><button type="submit" id="btnCreateUser" class="btn btn-primary">Create User</button><button type="button" class="btn btn-ghost" data-close-modal="createUserModal">Cancel</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="editUserModal" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3><i class="bi bi-pencil-fill"></i> Edit User</h3><button data-close-modal="editUserModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <form method="POST" id="editUserForm" action="">
        <?= Security::csrfField() ?>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Full Name</label><input type="text" name="name" id="eu_name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Department</label><input type="text" name="department" id="eu_dept" class="form-control"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label class="form-label">Job Title</label><input type="text" name="job_title" id="eu_title" class="form-control"></div>
          <div class="form-group">
            <label class="form-label">Role</label>
            <select name="role" id="eu_role" class="form-control">
              <?php foreach (['admin'=>'Administrator','manager'=>'Manager','auditor'=>'Auditor','analyst'=>'Analyst','viewer'=>'Viewer'] as $v=>$l): ?>
                <option value="<?= $v ?>"><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">New Password <span class="text-muted">(leave blank to keep current)</span></label>
          <input type="password" name="new_password" class="form-control" placeholder="Min 12 chars" minlength="12">
        </div>
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="is_active" id="eu_active" value="1"> Active account
          </label>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Save Changes</button><button type="button" class="btn btn-ghost" data-close-modal="editUserModal">Cancel</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Import Users Modal -->
<div class="modal-overlay" id="importUsersModal" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3><i class="bi bi-upload"></i> Import Users</h3><button data-close-modal="importUsersModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <form method="POST" action="/admin/users/import" enctype="multipart/form-data">
        <?= Security::csrfField() ?>
        <div class="form-group">
          <label class="form-label required">CSV File <span class="text-muted">(max 5MB)</span></label>
          <input type="file" name="csv_file" class="form-control" accept=".csv" required>
        </div>
        <div class="form-group">
          <p class="text-muted text-sm" style="margin:0 0 8px 0">If no <code>password</code> column is present, a random password will be generated and emailed to each user.</p>
          <a href="/admin/users/import-template" class="btn btn-ghost btn-sm"><i class="bi bi-download"></i> Download CSV Template</a>
        </div>
        <div class="form-group">
          <p class="form-label" style="margin-bottom:6px">CSV Field Reference</p>
          <table class="table" style="font-size:0.85rem">
            <thead><tr><th>Field</th><th>Type</th><th>Required</th></tr></thead>
            <tbody>
              <tr><td><code>name</code></td><td>text</td><td>Yes</td></tr>
              <tr><td><code>email</code></td><td>email</td><td>Yes</td></tr>
              <tr><td><code>role</code></td><td>admin / manager / auditor / analyst / viewer</td><td>Yes</td></tr>
              <tr><td><code>department</code></td><td>text</td><td>No</td></tr>
              <tr><td><code>job_title</code></td><td>text</td><td>No</td></tr>
              <tr><td><code>password</code></td><td>text (min 12 chars)</td><td>Yes*</td></tr>
            </tbody>
          </table>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Import Users</button><button type="button" class="btn btn-ghost" data-close-modal="importUsersModal">Cancel</button></div>
      </form>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
// Password strength indicator — visual only, no submit blocking
(function() {
  var pwInput = document.getElementById('newUserPassword');
  if (!pwInput) return;
  var rules = [
    { id: 'pw-len',     test: function(v) { return v.length >= 12; } },
    { id: 'pw-upper',   test: function(v) { return /[A-Z]/.test(v); } },
    { id: 'pw-num',     test: function(v) { return /[0-9]/.test(v); } },
    { id: 'pw-special', test: function(v) { return /[^A-Za-z0-9]/.test(v); } },
  ];
  pwInput.addEventListener('input', function() {
    rules.forEach(function(r) {
      var ok = r.test(pwInput.value);
      var el = document.getElementById(r.id);
      if (!el) return;
      el.style.color = ok ? 'var(--success)' : 'var(--text-muted)';
      el.querySelector('i').className = ok ? 'bi bi-check-circle-fill' : 'bi bi-x-circle-fill';
      el.querySelector('i').style.marginRight = '4px';
    });
  });
})();
function editUserFromBtn(btn) {
  editUser(JSON.parse(btn.getAttribute('data-user')));
}
function editUser(u) {
  document.getElementById('editUserForm').action = '/admin/users/' + u.id + '/update';
  document.getElementById('eu_name').value  = u.name;
  document.getElementById('eu_dept').value  = u.department || '';
  document.getElementById('eu_title').value = u.job_title || '';
  document.getElementById('eu_role').value  = u.role;
  document.getElementById('eu_active').checked = u.is_active === '1' || u.is_active === true || u.is_active === 't';
  showModal('editUserModal');
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
