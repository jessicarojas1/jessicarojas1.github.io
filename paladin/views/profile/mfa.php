<?php
$pageTitle    = 'Two-Factor Authentication';
$activeModule = 'profile_mfa';
$breadcrumbs  = [['Edit Profile', '/profile/edit'], ['Two-Factor Authentication', null]];
ob_start();
?>
<div class="page-header"><div><h1 class="page-title">Two-Factor Authentication</h1><p class="page-subtitle">Add a one-time code from an authenticator app to your sign-in</p></div></div>

<div class="card form-page"><div class="card-body">
  <?php if ($enabled): ?>
    <div class="banner ok"><i class="bi bi-shield-check"></i><div class="banner-body"><strong>Two-factor authentication is enabled.</strong> You'll be asked for a code from your authenticator app each time you sign in.</div></div>

    <?php if (!empty($recoveryCodes)): ?>
      <div class="banner warn" style="margin-top:14px"><i class="bi bi-key-fill"></i><div class="banner-body">
        <strong>Save your recovery codes.</strong> Each can be used once to sign in if you lose your authenticator. They won't be shown again.
        <div style="display:grid;grid-template-columns:repeat(2,max-content);gap:6px 24px;margin-top:10px;font-family:monospace;font-size:.95rem">
          <?php foreach ($recoveryCodes as $rc): ?><span><?= Security::h($rc) ?></span><?php endforeach; ?>
        </div>
      </div></div>
    <?php else: ?>
      <p class="form-hint" style="margin-top:14px"><i class="bi bi-key"></i> Recovery codes remaining: <strong><?= (int)$recoveryRemaining ?></strong>. Use these to sign in if you lose access to your authenticator.</p>
    <?php endif; ?>

    <form method="POST" action="/mfa/recovery-codes" style="margin-top:10px">
      <?= Security::csrfField() ?>
      <button class="btn btn-ghost" type="submit" data-confirm-click="Generate new recovery codes? Your existing codes will stop working."><i class="bi bi-arrow-repeat"></i> Regenerate recovery codes</button>
    </form>

    <form method="POST" action="/mfa/disable" style="margin-top:14px" data-confirm="Disable two-factor authentication?">
      <?= Security::csrfField() ?>
      <div class="form-group" style="max-width:320px"><label class="form-label">Confirm your password to disable</label><input type="password" name="password" class="form-control" autocomplete="current-password" required></div>
      <button class="btn btn-danger" type="submit"><i class="bi bi-shield-slash"></i> Disable 2FA</button>
    </form>
  <?php else: ?>
    <p class="form-hint" style="margin-top:0">Use an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Authy…). Add a new account using the key below, then enter the 6-digit code to confirm.</p>
    <div class="form-group">
      <label class="form-label">1. Setup key (Base32)</label>
      <div class="input-group" style="display:flex;gap:8px;max-width:420px">
        <input type="text" id="mfaSecret" class="form-control" readonly value="<?= Security::h($secret) ?>" style="font-family:monospace;letter-spacing:.1em">
        <button type="button" class="btn btn-ghost" data-copy="#mfaSecret"><i class="bi bi-clipboard"></i> Copy</button>
      </div>
      <div class="form-hint" style="margin-top:6px">Account: <code><?= Security::h(Auth::user()['email'] ?? '') ?></code> · Issuer: <code><?= Security::h(Branding::name()) ?></code> · Type: Time-based (TOTP), 6 digits</div>
    </div>
    <form method="POST" action="/mfa/setup">
      <?= Security::csrfField() ?>
      <div class="form-group" style="max-width:240px"><label class="form-label">2. Enter the 6-digit code</label><input type="text" name="code" class="form-control" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="123456" required style="letter-spacing:.3em;text-align:center;font-size:1.1rem"></div>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-shield-check"></i> Enable 2FA</button><a href="/profile/edit" class="btn btn-ghost">Cancel</a></div>
    </form>
  <?php endif; ?>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
