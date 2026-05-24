<?php
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Email Settings</h1>
    <p class="page-subtitle">Configure SMTP for system notifications and alerts</p>
  </div>
  <div class="page-actions">
    <a href="/admin" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Admin</a>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div style="max-width:720px;display:flex;flex-direction:column;gap:20px">

  <div class="card">
    <div class="card-header"><div class="card-header-left"><i class="bi bi-envelope-fill" style="color:var(--primary)"></i><span class="card-title">SMTP Configuration</span></div></div>
    <div class="card-body">
      <form method="post" action="/admin/email/save">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

        <div class="form-row">
          <div class="form-group" style="flex:2">
            <label class="form-label" for="smtp_host">SMTP Host</label>
            <input type="text" id="smtp_host" name="smtp_host" class="form-control" placeholder="smtp.example.com" value="<?= Security::h($settings['smtp_host'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label" for="smtp_port">Port</label>
            <input type="number" id="smtp_port" name="smtp_port" class="form-control" placeholder="587" value="<?= Security::h($settings['smtp_port'] ?? '587') ?>">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label class="form-label" for="smtp_user">Username</label>
            <input type="text" id="smtp_user" name="smtp_user" class="form-control" placeholder="user@example.com" value="<?= Security::h($settings['smtp_user'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label" for="smtp_pass">Password</label>
            <input type="password" id="smtp_pass" name="smtp_pass" class="form-control" placeholder="<?= !empty($settings['smtp_pass']) ? '(saved — leave blank to keep)' : 'Enter password' ?>">
            <span class="form-text">Leave blank to keep the current password.</span>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:2">
            <label class="form-label" for="smtp_from">From Address</label>
            <input type="email" id="smtp_from" name="smtp_from" class="form-control" placeholder="noreply@example.com" value="<?= Security::h($settings['smtp_from'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:2">
            <label class="form-label" for="smtp_from_name">From Name</label>
            <input type="text" id="smtp_from_name" name="smtp_from_name" class="form-control" placeholder="AEGIS GRC" value="<?= Security::h($settings['smtp_from_name'] ?? 'AEGIS GRC') ?>">
          </div>
        </div>

        <div style="display:flex;gap:24px;margin-bottom:16px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
            <input type="checkbox" name="smtp_tls" value="1" <?= !empty($settings['smtp_tls']) && $settings['smtp_tls'] === '1' ? 'checked' : '' ?> style="width:16px;height:16px">
            <span>Use STARTTLS</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
            <input type="checkbox" name="email_notifications" value="1" <?= !empty($settings['email_notifications']) && $settings['email_notifications'] === '1' ? 'checked' : '' ?> style="width:16px;height:16px">
            <span>Enable email notifications</span>
          </label>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><i class="bi bi-send-fill" style="color:#059669"></i><span class="card-title">Send Test Email</span></div></div>
    <div class="card-body">
      <p style="font-size:14px;color:var(--text-muted);margin-bottom:16px">Send a test message to verify your SMTP configuration is working correctly.</p>
      <form method="post" action="/admin/email/test" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-group" style="flex:1;margin-bottom:0">
          <label class="form-label" for="test_email">Recipient</label>
          <input type="email" id="test_email" name="test_email" class="form-control" placeholder="you@example.com" required>
        </div>
        <button type="submit" class="btn btn-secondary"><i class="bi bi-envelope-check"></i> Send Test</button>
      </form>
    </div>
  </div>

</div>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
