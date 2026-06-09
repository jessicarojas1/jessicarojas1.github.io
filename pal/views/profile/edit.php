<?php
$pageTitle    = 'Edit Profile';
$activeModule = 'profile_edit';
$breadcrumbs  = [['My Profile', null], ['Edit Profile', null]];
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Edit Profile</h1>
    <p class="page-subtitle">Manage your account details and password.</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
  <div>
    <!-- Profile details -->
    <div class="card" style="margin-bottom:18px">
      <div class="card-header">
        <div class="card-header-left"><span class="card-title"><i class="bi bi-person-badge"></i> Profile Details</span></div>
      </div>
      <div class="card-body">
        <form method="POST" action="/profile/edit">
          <?= Security::csrfField() ?>
          <div class="form-group">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required value="<?= Security::h($user['name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" value="<?= Security::h($user['email'] ?? '') ?>" readonly>
            <div class="form-hint">Email is managed by an administrator and cannot be changed here.</div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Department</label>
              <input type="text" name="department" class="form-control" value="<?= Security::h($user['department'] ?? '') ?>" placeholder="Quality Assurance">
            </div>
            <div class="form-group">
              <label class="form-label">Title</label>
              <input type="text" name="title" class="form-control" value="<?= Security::h($user['title'] ?? '') ?>" placeholder="Compliance Analyst">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Role</label>
            <div><span class="badge badge-blue"><?= Security::h(Auth::roleLabel($user['role'] ?? 'viewer')) ?></span></div>
            <div class="form-hint">Your role is assigned by an administrator and determines your permissions.</div>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save Profile</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Change password -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left"><span class="card-title"><i class="bi bi-shield-lock"></i> Change Password</span></div>
      </div>
      <div class="card-body">
        <form method="POST" action="/profile/edit">
          <?= Security::csrfField() ?>
          <div class="form-group">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-control" autocomplete="current-password">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">New Password</label>
              <input type="password" name="new_password" class="form-control" autocomplete="new-password">
            </div>
            <div class="form-group">
              <label class="form-label">Confirm New Password</label>
              <input type="password" name="confirm_password" class="form-control" autocomplete="new-password">
            </div>
          </div>
          <div class="form-hint">Leave the password fields blank to keep your current password.</div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-key"></i> Update Password</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Account meta -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> Account</span></div>
    </div>
    <div class="card-body">
      <div class="meta-grid">
        <div class="meta-item"><div class="meta-label">Role</div><div class="meta-value"><?= Security::h(Auth::roleLabel($user['role'] ?? 'viewer')) ?></div></div>
        <div class="meta-item"><div class="meta-label">Last Login</div><div class="meta-value"><?= !empty($user['last_login']) ? View::fmtDate($user['last_login'], 'M j, Y g:ia') : '—' ?></div></div>
        <div class="meta-item"><div class="meta-label">Password Last Changed</div><div class="meta-value"><?= !empty($user['password_changed_at']) ? View::fmtDate($user['password_changed_at'], 'M j, Y g:ia') : '—' ?></div></div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
