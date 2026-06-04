<?php
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Load fresh MFA status from DB
$dbUser = Database::fetchOne("SELECT mfa_enabled, role FROM users WHERE id = ?", [Auth::id()]);
$mfaEnabled = !empty($dbUser['mfa_enabled']);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">My Profile</h1>
    <p class="page-subtitle">Manage your account information and security settings</p>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:1000px">

  <!-- Profile Information Card -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-person-fill" style="color:var(--primary)"></i>
        <span class="card-title">Profile Information</span>
      </div>
    </div>
    <div class="card-body">
      <form method="POST" action="/profile/update">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="name">Full Name</label>
          <input type="text"
                 id="name"
                 name="name"
                 class="form-control"
                 value="<?= Security::h($user['name'] ?? '') ?>"
                 minlength="2"
                 maxlength="100"
                 required
                 autocomplete="name">
        </div>

        <div class="form-group">
          <label class="form-label" for="email">Email Address</label>
          <input type="email"
                 id="email"
                 name="email"
                 class="form-control"
                 value="<?= Security::h($user['email'] ?? '') ?>"
                 required
                 autocomplete="email">
        </div>

        <div class="form-group">
          <label class="form-label">Role</label>
          <div>
            <span class="badge badge-<?= Security::h($user['role'] ?? 'viewer') ?>"
                  style="font-size:13px;padding:4px 12px;border-radius:20px;background:var(--primary-light,rgba(11,97,4,.06));color:var(--primary);font-weight:600">
              <?= Security::h(ucfirst($user['role'] ?? 'viewer')) ?>
            </span>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Two-Factor Authentication</label>
          <div style="display:flex;align-items:center;gap:10px">
            <?php if ($mfaEnabled): ?>
              <span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#059669">
                <i class="bi bi-shield-check-fill"></i> Enabled
              </span>
            <?php else: ?>
              <span style="display:inline-flex;align-items:center;gap:5px;font-size:13px;color:var(--text-muted)">
                <i class="bi bi-shield-slash"></i> Not configured
              </span>
              <a href="/mfa/setup" class="btn btn-ghost" style="font-size:12px;padding:4px 10px">Set up MFA</a>
            <?php endif; ?>
          </div>
        </div>

        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Save Profile
        </button>
      </form>
    </div>
  </div>

  <!-- Change Password Card -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-lock-fill" style="color:var(--primary)"></i>
        <span class="card-title">Change Password</span>
      </div>
    </div>
    <div class="card-body">
      <form method="POST" action="/profile/change-password" autocomplete="off">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="current_password">Current Password</label>
          <input type="password"
                 id="current_password"
                 name="current_password"
                 class="form-control"
                 required
                 autocomplete="current-password">
        </div>

        <div class="form-group">
          <label class="form-label" for="new_password">New Password</label>
          <input type="password"
                 id="new_password"
                 name="new_password"
                 class="form-control"
                 required
                 autocomplete="new-password"
                 data-input="updateStrength" data-input-val="1">
          <!-- Strength meter -->
          <div id="strength-meter-wrap" style="margin-top:8px">
            <div style="height:6px;border-radius:3px;background:#e5e7eb;overflow:hidden">
              <div id="strength-bar" style="height:100%;width:0%;border-radius:3px;transition:width 0.3s,background 0.3s"></div>
            </div>
            <div id="strength-label" style="font-size:12px;margin-top:4px;color:var(--text-muted)"></div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="confirm_password">Confirm New Password</label>
          <input type="password"
                 id="confirm_password"
                 name="confirm_password"
                 class="form-control"
                 required
                 autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary">
          <i class="bi bi-lock"></i> Change Password
        </button>
      </form>
    </div>
  </div>

</div>

<script nonce="<?= Security::nonce() ?>">
function updateStrength(pwd) {
    var score = 0;
    if (pwd.length >= 8)  score++;
    if (pwd.length >= 12) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^a-zA-Z0-9]/.test(pwd)) score++;

    var bar   = document.getElementById('strength-bar');
    var label = document.getElementById('strength-label');

    if (!pwd.length) {
        bar.style.width = '0%';
        label.textContent = '';
        return;
    }

    var pct, color, text;
    if (score <= 1) {
        pct = '20%'; color = '#ef4444'; text = 'Very weak';
    } else if (score === 2) {
        pct = '40%'; color = '#f97316'; text = 'Weak';
    } else if (score === 3) {
        pct = '60%'; color = '#f59e0b'; text = 'Fair';
    } else if (score === 4) {
        pct = '80%'; color = '#84cc16'; text = 'Good';
    } else {
        pct = '100%'; color = '#22c55e'; text = 'Strong';
    }

    bar.style.width = pct;
    bar.style.background = color;
    label.textContent = text;
    label.style.color = color;
}
</script>
