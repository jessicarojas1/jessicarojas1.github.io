<?php
/** @var array $user @var string $csrf @var ?string $error */
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

<?php require __DIR__ . '/partials/app_footer.php'; ?>
