<?php
/** @var array $user @var string $csrf @var ?string $error @var bool $mfaEnabled @var array|null $mfaPending @var int $mfaRecoveryCodeCount @var array|null $newRecoveryCodes */
use Verity\Support\Roles;
use Verity\Support\Security;

$error = $error ?? null;
$changed = !empty($_GET['changed']);

require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div><h1 class="page-title">My Account</h1><p>Profile information and sign-in credentials.</p></div>
</div>

<div class="field-row">
  <div class="card flex-1-min260">
    <h2>Profile</h2>
    <table class="grid">
      <tbody>
        <tr><th>Name</th><td><?= Security::h($user['name']) ?></td></tr>
        <tr><th>Email</th><td><?= Security::h($user['email']) ?></td></tr>
        <tr><th>Roles</th><td><?= Security::h(implode(', ', array_map([Roles::class, 'label'], $user['roles'])) ?: 'None') ?></td></tr>
        <tr><th>Status</th><td><?= Security::h(ucwords($user['status'])) ?></td></tr>
      </tbody>
    </table>
    <p class="hint">Role and permission changes are made by an administrator in Access &amp; Security.</p>
  </div>

  <div class="card flex-1-min260">
    <h2>Change Password</h2>
    <?php if ($changed): ?><div class="badge b-ok mb-12">Password updated.</div><?php endif; ?>
    <?php if ($error): ?><div class="badge b-risk mb-12"><?= Security::h($error) ?></div><?php endif; ?>
    <form method="post" action="/app/profile/password">
      <?= $csrf ?>
      <div class="field">
        <label for="current_password">Current Password</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
      </div>
      <div class="field">
        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="12" required>
        <p class="hint">At least 12 characters. Length matters more than symbol complexity.</p>
      </div>
      <div class="field">
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="12" required>
      </div>
      <button type="submit" class="btn primary">Change Password</button>
    </form>
  </div>
</div>

<div class="card mt-14">
  <h2>Two-Factor Authentication</h2>

  <?php if ($newRecoveryCodes !== null): ?>
  <div class="card">
    <span class="badge b-warn">Save these recovery codes now — shown only once</span>
    <p class="mt-6">Each code works once, if you ever lose access to your authenticator app. Store them somewhere safe (a password manager, not this page — it won't show them again).</p>
    <ul class="mt-8">
      <?php foreach ($newRecoveryCodes as $code): ?><li><code><?= Security::h($code) ?></code></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($mfaEnabled): ?>
  <span class="badge b-ok">Enabled</span>
  <p class="hint mt-6"><?= $mfaRecoveryCodeCount ?> recovery code(s) remaining.<?= $mfaRecoveryCodeCount <= 2 ? ' Consider regenerating a fresh set below.' : '' ?></p>
  <div class="field-row mt-12">
    <form method="post" action="/app/profile/mfa/regenerate-codes">
      <?= $csrf ?>
      <div class="field"><label for="regen_password">Current Password</label><input type="password" id="regen_password" name="current_password" autocomplete="current-password" required></div>
      <button type="submit" class="btn sm" data-confirm="This invalidates every existing recovery code and issues a new set. Continue?">Regenerate Recovery Codes</button>
    </form>
    <form method="post" action="/app/profile/mfa/disable">
      <?= $csrf ?>
      <div class="field"><label for="disable_password">Current Password</label><input type="password" id="disable_password" name="current_password" autocomplete="current-password" required></div>
      <button type="submit" class="btn sm danger" data-confirm="Disable two-factor authentication? You'll be able to sign in with just your password again.">Disable Two-Factor Authentication</button>
    </form>
  </div>

  <?php elseif ($mfaPending !== null): ?>
  <span class="badge b-warn">Setup started — not yet confirmed</span>
  <p class="mt-6">This build generates no QR code (no new dependency, no external image-generation service that would see your secret) — enter this key manually in your authenticator app (Google Authenticator, Authy, 1Password, etc.):</p>
  <p><code><?= Security::h($mfaPending['secret']) ?></code></p>
  <p class="hint">Full setup URI, if your app can import one directly: <code><?= Security::h($mfaPending['uri']) ?></code></p>
  <form method="post" action="/app/profile/mfa/confirm" class="mt-12">
    <?= $csrf ?>
    <div class="field">
      <label for="mfa_confirm_code">Enter the current 6-digit code to confirm</label>
      <input type="text" id="mfa_confirm_code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus>
    </div>
    <button type="submit" class="btn primary">Confirm and Enable</button>
  </form>
  <form method="post" action="/app/profile/mfa/enroll" class="mt-8">
    <?= $csrf ?>
    <button type="submit" class="btn sm">Start over with a new key</button>
  </form>

  <?php else: ?>
  <span class="badge b-neutral">Not enabled</span>
  <p class="hint mt-6">Add a second sign-in step using an authenticator app, in addition to your password.</p>
  <form method="post" action="/app/profile/mfa/enroll" class="mt-8">
    <?= $csrf ?>
    <button type="submit" class="btn primary">Enable Two-Factor Authentication</button>
  </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
