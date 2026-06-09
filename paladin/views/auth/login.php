<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__brandName = Branding::name(); $__brandLogo = Branding::logo(); ?>
<title>Sign In — <?= Security::h($__brandName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
          <img src="<?= Security::h($__brandLogo) ?>" alt="<?= Security::h($__brandName) ?> logo" class="auth-brand-logo" data-logo-fallback style="width:48px;height:48px;object-fit:contain;border-radius:10px">
          <div class="auth-brand-icon brand-logo-fallback" style="display:none"><i class="bi bi-journal-richtext"></i></div>
        <?php else: ?>
          <div class="auth-brand-icon"><i class="bi bi-journal-richtext"></i></div>
        <?php endif; ?>
        <div>
          <div class="auth-brand-name"><?= Security::h($__brandName) ?></div>
          <div class="auth-brand-tagline">Process · Approval · Library</div>
        </div>
      </div>
      <div class="auth-features">
        <div class="auth-feature"><i class="bi bi-collection"></i><span>Organize knowledge in controlled Spaces</span></div>
        <div class="auth-feature"><i class="bi bi-file-earmark-check"></i><span>Controlled documents with revision &amp; approval</span></div>
        <div class="auth-feature"><i class="bi bi-diagram-2"></i><span>Configurable workflow &amp; approval routing</span></div>
        <div class="auth-feature"><i class="bi bi-patch-check"></i><span>Acknowledgements &amp; compliance evidence</span></div>
        <div class="auth-feature"><i class="bi bi-shield-lock"></i><span>Immutable, hash-chained audit trail</span></div>
      </div>
      <div class="auth-left-footer">The authoritative source for organizational knowledge.</div>
    </div>
  </div>

  <div class="auth-right">
    <div class="auth-form-card">
      <div class="auth-form-header">
        <h1>Welcome back</h1>
        <p>Sign in to your <?= Security::h($__brandName) ?> workspace</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($error) ?></div>
      <?php endif; ?>
      <?php if (!empty($notice)): ?>
        <div class="alert-box warning"><i class="bi bi-info-circle-fill"></i> <?= Security::h($notice) ?></div>
      <?php endif; ?>

      <form method="POST" action="/login" class="auth-form" autocomplete="off">
        <?= Security::csrfField() ?>
        <div class="form-group">
          <label class="form-label" for="email"><i class="bi bi-envelope"></i> Email address</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" required autofocus autocomplete="email">
        </div>
        <div class="form-group">
          <label class="form-label" for="password"><i class="bi bi-lock"></i> Password</label>
          <div class="input-group">
            <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
            <button type="button" class="input-addon" data-click="togglePassword" data-arg="password"><i class="bi bi-eye" id="toggleIcon"></i></button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-full btn-lg"><i class="bi bi-box-arrow-in-right"></i> Sign In</button>
      </form>

      <div class="auth-form-footer">
        <p><?= Security::h($__brandName) ?> &copy; <?= date('Y') ?> — Enterprise PALADIN</p>
        <p style="margin-top:6px;font-size:11px;color:var(--text-muted)">Protected by rate limiting and session security</p>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function togglePassword(id) {
  var input = document.getElementById(id), icon = document.getElementById('toggleIcon');
  if (input.type === 'password') { input.type = 'text'; icon.className = 'bi bi-eye-slash'; }
  else { input.type = 'password'; icon.className = 'bi bi-eye'; }
}
document.querySelectorAll('img[data-logo-fallback]').forEach(function (img) {
  img.addEventListener('error', function () {
    img.style.display = 'none';
    var fb = img.parentElement ? img.parentElement.querySelector('.brand-logo-fallback') : null;
    if (fb) fb.style.display = '';
  });
});
document.addEventListener('click', function (e) {
  var el = e.target.closest('[data-click]');
  if (!el) return;
  var fn = window[el.getAttribute('data-click')];
  if (typeof fn === 'function') fn(el.getAttribute('data-arg'));
});
</script>
</body>
</html>
