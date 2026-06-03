<?php
ob_start();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Two-Factor Authentication</h1>
    <p class="page-subtitle">Secure your account with an authenticator app</p>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div style="max-width:640px">

  <?php if ($isMfaEnabled): ?>
  <!-- MFA is enabled -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;padding:24px">
      <div style="width:48px;height:48px;border-radius:50%;background:#05966920;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-shield-check-fill" style="color:#059669;font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;color:#059669">Two-Factor Authentication is Active</div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:2px">Your account is protected with TOTP authentication.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><i class="bi bi-shield-x" style="color:#dc2626"></i><span class="card-title">Disable 2FA</span></div></div>
    <div class="card-body">
      <p style="color:var(--text-muted);font-size:14px;margin-bottom:16px">Disabling two-factor authentication will make your account less secure. Only do this if you are switching authenticator apps or have a specific need.</p>
      <form method="post" action="/mfa/disable" data-confirm="Are you sure you want to disable two-factor authentication?">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <button type="submit" class="btn btn-danger"><i class="bi bi-shield-x"></i> Disable 2FA</button>
      </form>
    </div>
  </div>

  <?php else: ?>
  <!-- MFA is not enabled — show setup -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;padding:24px">
      <div style="width:48px;height:48px;border-radius:50%;background:#d9770620;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-shield-exclamation" style="color:#d97706;font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600">Two-Factor Authentication is Not Enabled</div>
        <div style="font-size:13px;color:var(--text-muted);margin-top:2px">Add an extra layer of security to your account by enabling TOTP authentication.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><i class="bi bi-qr-code" style="color:var(--primary)"></i><span class="card-title">Setup Instructions</span></div></div>
    <div class="card-body">
      <ol style="padding-left:20px;margin:0 0 20px;line-height:1.8;font-size:14px">
        <li>Install an authenticator app (Google Authenticator, Authy, 1Password, etc.)</li>
        <li>Scan the QR code below or enter the secret key manually</li>
        <li>Enter the 6-digit code from the app to verify and activate 2FA</li>
      </ol>

      <div style="text-align:center;margin-bottom:24px">
        <div style="display:inline-block;padding:16px;background:#fff;border-radius:12px;border:1px solid var(--border)">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($qrUri) ?>" alt="QR Code" width="180" height="180" style="display:block">
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-top:8px">Can't scan? Enter this key manually:</p>
        <code style="font-size:16px;letter-spacing:4px;font-weight:600;color:var(--primary)"><?= chunk_split(Security::h($secret), 4, ' ') ?></code>
      </div>

      <form method="post" action="/mfa/setup/verify">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label" for="code">Verification Code</label>
          <input type="text" id="code" name="code" class="form-control" style="max-width:200px;font-size:20px;letter-spacing:4px;text-align:center" inputmode="numeric" pattern="[0-9 ]{6,7}" placeholder="000000" autocomplete="one-time-code">
          <span class="form-text">Enter the 6-digit code shown in your authenticator app.</span>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check"></i> Enable 2FA</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
