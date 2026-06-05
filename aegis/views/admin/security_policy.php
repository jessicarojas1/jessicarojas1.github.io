<?php
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$breadcrumbs = [['Admin', '/admin'], ['Security Policy', null]];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Security Policy</h1>
    <p class="page-subtitle">Configure password rules, authentication requirements, and network restrictions</p>
  </div>
  <div class="page-actions">
    <a href="/admin" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Admin</a>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert-box success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert-box error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<form method="POST" action="/admin/security-policy/save">
  <?= Security::csrfField() ?>

  <div style="display:flex;flex-direction:column;gap:20px;max-width:800px">

    <!-- Password Policy Card -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-key-fill" style="color:var(--primary)"></i>
          <span class="card-title">Password Policy</span>
        </div>
      </div>
      <div class="card-body">

        <div class="form-group">
          <label class="form-label" for="password_min_length">Minimum Password Length</label>
          <input type="number"
                 id="password_min_length"
                 name="password_min_length"
                 class="form-control"
                 style="max-width:120px"
                 min="8" max="128"
                 value="<?= Security::h($policy['password_min_length']) ?>"
                 required>
          <span class="form-text">Between 8 and 128 characters.</span>
        </div>

        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
            <input type="checkbox"
                   name="password_require_uppercase"
                   value="1"
                   style="width:16px;height:16px"
                   <?= $policy['password_require_uppercase'] === '1' ? 'checked' : '' ?>>
            <span><strong>Require uppercase letter</strong> — Password must contain at least one A–Z character</span>
          </label>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
            <input type="checkbox"
                   name="password_require_numbers"
                   value="1"
                   style="width:16px;height:16px"
                   <?= $policy['password_require_numbers'] === '1' ? 'checked' : '' ?>>
            <span><strong>Require numbers</strong> — Password must contain at least one digit (0–9)</span>
          </label>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
            <input type="checkbox"
                   name="password_require_special"
                   value="1"
                   style="width:16px;height:16px"
                   <?= $policy['password_require_special'] === '1' ? 'checked' : '' ?>>
            <span><strong>Require special characters</strong> — Password must contain at least one non-alphanumeric character</span>
          </label>
        </div>

        <div class="form-group" style="max-width:200px">
          <label class="form-label" for="password_expiry_days">Password Expiry (days)</label>
          <input type="number"
                 id="password_expiry_days"
                 name="password_expiry_days"
                 class="form-control"
                 min="0" max="365"
                 value="<?= Security::h($policy['password_expiry_days']) ?>">
          <span class="form-text">Set to 0 to disable expiry.</span>
        </div>

      </div>
    </div>

    <!-- Authentication Card -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-shield-lock-fill" style="color:var(--primary)"></i>
          <span class="card-title">Authentication</span>
        </div>
      </div>
      <div class="card-body">

        <div class="form-group">
          <label class="form-label" for="mfa_enforcement">MFA Enforcement</label>
          <select id="mfa_enforcement" name="mfa_enforcement" class="form-control" style="max-width:340px">
            <option value="optional"          <?= $policy['mfa_enforcement'] === 'optional'          ? 'selected' : '' ?>>Optional (users may enable MFA)</option>
            <option value="admin_required"    <?= $policy['mfa_enforcement'] === 'admin_required'    ? 'selected' : '' ?>>Require for administrators</option>
            <option value="manager_required"  <?= $policy['mfa_enforcement'] === 'manager_required'  ? 'selected' : '' ?>>Require for administrators &amp; managers</option>
            <option value="all_required"      <?= $policy['mfa_enforcement'] === 'all_required'      ? 'selected' : '' ?>>Require for all users</option>
          </select>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:1;max-width:200px">
            <label class="form-label" for="max_failed_logins">Max Failed Login Attempts</label>
            <input type="number"
                   id="max_failed_logins"
                   name="max_failed_logins"
                   class="form-control"
                   min="3" max="20"
                   value="<?= Security::h($policy['max_failed_logins']) ?>">
            <span class="form-text">Between 3 and 20 attempts.</span>
          </div>
          <div class="form-group" style="flex:1;max-width:240px">
            <label class="form-label" for="account_lockout_minutes">Account Lockout Duration (minutes)</label>
            <input type="number"
                   id="account_lockout_minutes"
                   name="account_lockout_minutes"
                   class="form-control"
                   min="5" max="1440"
                   value="<?= Security::h($policy['account_lockout_minutes']) ?>">
            <span class="form-text">Between 5 and 1440 minutes (24 h).</span>
          </div>
        </div>

        <div class="form-group" style="max-width:240px">
          <label class="form-label" for="session_timeout_minutes">Session Timeout (minutes)</label>
          <input type="number"
                 id="session_timeout_minutes"
                 name="session_timeout_minutes"
                 class="form-control"
                 min="15" max="10080"
                 value="<?= Security::h($policy['session_timeout_minutes']) ?>">
          <span class="form-text">Between 15 and 10080 minutes (480 = 8 h).</span>
        </div>

      </div>
    </div>

    <!-- Network Card -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-diagram-3-fill" style="color:var(--primary)"></i>
          <span class="card-title">Network Restrictions <span style="font-size:12px;font-weight:400;color:var(--text-muted)">(optional)</span></span>
        </div>
      </div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label" for="allowed_ip_ranges">Allowed IP Ranges</label>
          <textarea id="allowed_ip_ranges"
                    name="allowed_ip_ranges"
                    class="form-control"
                    rows="5"
                    style="font-family:monospace;font-size:13px"
                    placeholder="192.168.1.0/24&#10;10.0.0.0/8&#10;203.0.113.42"><?= Security::h($policy['allowed_ip_ranges']) ?></textarea>
          <span class="form-text">One CIDR block or IP address per line. Leave empty to allow all IP addresses.</span>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:12px;padding-bottom:32px">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-save"></i> Save Security Policy
      </button>
      <a href="/admin" class="btn btn-ghost">Cancel</a>
    </div>

  </div>
</form>
