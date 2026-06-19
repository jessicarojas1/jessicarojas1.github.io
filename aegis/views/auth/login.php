<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__brandName = Branding::name(); $__brandLogo = Branding::logo(); ?>
<title>Sign In — <?= Security::h($__brandName) ?></title>
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
          <div class="auth-brand-tagline">Enterprise Governance & Compliance Platform</div>
        </div>
      </div>
      <div class="auth-features">
        <div class="auth-feature"><i class="bi bi-shield-check"></i><span>Import any compliance framework via JSON</span></div>
        <div class="auth-feature"><i class="bi bi-clipboard2-check"></i><span>Automated audit scheduling & tracking</span></div>
        <div class="auth-feature"><i class="bi bi-exclamation-triangle"></i><span>Real-time risk matrix & treatment plans</span></div>
        <div class="auth-feature"><i class="bi bi-file-earmark-text"></i><span>Policy lifecycle management</span></div>
        <div class="auth-feature"><i class="bi bi-graph-up"></i><span>Compliance dashboards & reporting</span></div>
      </div>
      <div class="auth-left-footer">Securing enterprise governance, intelligently.</div>
    </div>
  </div>

  <!-- Right panel -->
  <div class="auth-right">
    <div class="auth-form-card">
      <div class="auth-form-header">
        <h1>Welcome back</h1>
        <p>Sign in to your AEGIS GRC workspace</p>
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

      <?php
        // SSO availability check
        if (!class_exists('SSO')) @require_once AEGIS_ROOT . '/src/SSO.php';
        $ssoEnabled  = class_exists('SSO') && SSO::isEnabled();
        $ssoCfg      = class_exists('SSO') ? SSO::config() : [];
        $ssoName     = !empty($ssoCfg['sso_provider_name']) ? $ssoCfg['sso_provider_name'] : 'Enterprise SSO';
        $ssoError    = $_SESSION['sso_error'] ?? '';
        unset($_SESSION['sso_error']);
      ?>

      <?php if ($ssoError): ?>
        <div class="alert-box error" style="margin-bottom:12px">
          <i class="bi bi-exclamation-circle-fill"></i>
          <?= Security::h($ssoError) ?>
        </div>
      <?php endif; ?>

      <?php if ($ssoEnabled): ?>
        <a href="/sso/login" class="btn btn-secondary btn-full btn-lg" style="margin-bottom:16px;display:flex;align-items:center;justify-content:center;gap:8px">
          <i class="bi bi-shield-lock-fill"></i>
          Sign in with <?= Security::h($ssoName) ?>
        </a>
      <?php else: ?>
        <a href="/sso/login" class="btn btn-full btn-lg" style="margin-bottom:16px;display:flex;align-items:center;justify-content:center;gap:8px;background:var(--bg-secondary);border:1px solid var(--border);color:var(--text-muted);cursor:pointer;border-radius:var(--radius)">
          <i class="bi bi-shield-lock-fill"></i>
          Sign in with Enterprise SSO
        </a>
      <?php endif; ?>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
        <hr style="flex:1;border:none;border-top:1px solid var(--border)">
        <span style="color:var(--text-muted);font-size:12px;white-space:nowrap">or sign in with password</span>
        <hr style="flex:1;border:none;border-top:1px solid var(--border)">
      </div>

      <form method="POST" action="/login" class="auth-form" autocomplete="off">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="email">
            <i class="bi bi-envelope"></i> Email address
          </label>
          <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" required autofocus autocomplete="email">
        </div>

        <div class="form-group">
          <label class="form-label" for="password">
            <i class="bi bi-lock"></i> Password
          </label>
          <div class="input-group">
            <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
            <button type="button" class="input-addon" data-click="togglePassword" data-arg="password">
              <i class="bi bi-eye" id="toggleIcon"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </form>

      <div class="auth-form-footer">
        <p>AEGIS GRC &copy; <?= date('Y') ?> &mdash; Enterprise Security Platform</p>
        <p style="margin-top:6px;font-size:11px;color:var(--text-muted)">Protected by rate limiting, MFA, and session security</p>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function togglePassword(id) {
  const input = document.getElementById(id);
  const icon  = document.getElementById('toggleIcon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'bi bi-eye';
  }
}
// Branding logo fallback: if a configured logo fails to load, show the shield mark.
document.querySelectorAll('img[data-logo-fallback]').forEach(function (img) {
  img.addEventListener('error', function () {
    img.style.display = 'none';
    var fb = img.parentElement ? img.parentElement.querySelector('.brand-logo-fallback') : null;
    if (fb) fb.style.display = '';
  });
});
// data-click delegation (login page has no app.js)
document.addEventListener('click', function (e) {
  var el = e.target.closest('[data-click]');
  if (!el) return;
  var fn = window[el.getAttribute('data-click')];
  if (typeof fn === 'function') fn(el.getAttribute('data-arg'));
});
</script>
</body>
</html>
