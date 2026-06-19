<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__brandName = Branding::name(); $__brandLogo = Branding::logo(); ?>
<title>Forgot Password — <?= Security::h($__brandName) ?></title>
<link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="/public/css/app.css">
<?= Branding::accentStyleTag() ?>
</head>
<body class="auth-body">

<div class="auth-split">
  <!-- Left panel -->
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
      <div class="auth-features">
        <div class="auth-feature"><i class="bi bi-shield-check"></i><span>Import any compliance framework via JSON</span></div>
        <div class="auth-feature"><i class="bi bi-clipboard2-check"></i><span>Automated audit scheduling &amp; tracking</span></div>
        <div class="auth-feature"><i class="bi bi-exclamation-triangle"></i><span>Real-time risk matrix &amp; treatment plans</span></div>
        <div class="auth-feature"><i class="bi bi-file-earmark-text"></i><span>Policy lifecycle management</span></div>
        <div class="auth-feature"><i class="bi bi-graph-up"></i><span>Compliance dashboards &amp; reporting</span></div>
      </div>
      <div class="auth-left-footer">Securing enterprise governance, intelligently.</div>
    </div>
  </div>

  <!-- Right panel -->
  <div class="auth-right">
    <div class="auth-form-card">
      <div class="auth-form-header">
        <h1>Forgot password?</h1>
        <p>Enter your email and we'll send you a reset link</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert-box error">
          <i class="bi bi-exclamation-circle-fill"></i>
          <?= Security::h($error) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($success)): ?>
        <div class="alert-box success">
          <i class="bi bi-check-circle-fill"></i>
          <?= Security::h($success) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/forgot-password" class="auth-form">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="email">
            <i class="bi bi-envelope"></i> Email address
          </label>
          <input type="email"
                 id="email"
                 name="email"
                 class="form-control"
                 placeholder="you@company.com"
                 required
                 autofocus
                 autocomplete="email">
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg">
          <i class="bi bi-send"></i> Send Reset Link
        </button>
      </form>

      <div class="auth-form-footer">
        <p><a href="/login" style="color:var(--primary);text-decoration:none"><i class="bi bi-arrow-left"></i> Back to Sign In</a></p>
        <p style="margin-top:12px"><?= Security::h($__brandName) ?> &copy; <?= date('Y') ?> &mdash; Enterprise Security Platform</p>
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
