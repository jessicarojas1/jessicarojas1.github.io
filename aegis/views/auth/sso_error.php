<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__brandName = Branding::name(); $__brandLogo = Branding::logo(); ?>
<title>SSO Error — <?= Security::h($__brandName) ?></title>
<link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="/public/css/app.css">
<?= Branding::accentStyleTag() ?>
</head>
<body class="auth-body">
<div class="auth-split">
  <div class="auth-left">
    <div class="auth-left-content">
      <div class="auth-brand">
        <?php if ($__brandLogo): ?>
          <img src="<?= Security::h($__brandLogo) ?>" alt="<?= Security::h($__brandName) ?> logo"
               class="auth-brand-logo" data-logo-fallback
               style="width:48px;height:48px;object-fit:contain;border-radius:10px">
          <div class="auth-brand-icon brand-logo-fallback" style="display:none"><i class="bi bi-shield-fill-check"></i></div>
        <?php else: ?>
          <div class="auth-brand-icon"><i class="bi bi-shield-fill-check"></i></div>
        <?php endif; ?>
        <div>
          <div class="auth-brand-name"><?= Security::h($__brandName) ?></div>
          <div class="auth-brand-tagline">Enterprise Governance &amp; Compliance Platform</div>
        </div>
      </div>
    </div>
  </div>
  <div class="auth-right">
    <div class="auth-form-card">
      <div class="auth-form-header">
        <h1>Sign-In Failed</h1>
        <p>There was a problem completing your SSO login.</p>
      </div>
      <div class="alert-box error">
        <i class="bi bi-shield-exclamation"></i>
        <?= Security::h($error ?? 'An unexpected error occurred.') ?>
      </div>
      <div style="margin-top:24px;text-align:center">
        <a href="/login" class="btn btn-secondary" style="margin-right:8px">
          <i class="bi bi-key"></i> Use Password Login
        </a>
        <a href="/sso/login" class="btn btn-primary">
          <i class="bi bi-arrow-repeat"></i> Try SSO Again
        </a>
      </div>
      <div class="auth-form-footer" style="margin-top:24px">
        <p>Contact your administrator if this problem persists.</p>
      </div>
    </div>
  </div>
</div>
<script nonce="<?= Security::nonce() ?>">
// Branding logo fallback: if a configured logo fails to load, show the shield mark.
document.querySelectorAll('img[data-logo-fallback]').forEach(function (img) {
  img.addEventListener('error', function () {
    img.style.display = 'none';
    var fb = img.parentElement ? img.parentElement.querySelector('.brand-logo-fallback') : null;
    if (fb) fb.style.display = '';
  });
});
</script>
</body>
</html>
