<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — AEGIS GRC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/public/css/app.css">
</head>
<body class="auth-body">

<div class="auth-split">
  <!-- Left panel -->
  <div class="auth-left">
    <div class="auth-left-content">
      <div class="auth-brand">
        <div class="auth-brand-icon"><i class="bi bi-shield-fill-check"></i></div>
        <div>
          <div class="auth-brand-name">AEGIS GRC</div>
          <div class="auth-brand-tagline">Enterprise Governance & Compliance Platform</div>
        </div>
      </div>
      <div class="auth-features">
        <div class="auth-feature"><i class="bi bi-shield-check"></i><span>CMMC, ISO 27001, ISO 42001 built-in</span></div>
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
        // Require SSO.php for SSO check on login page
        if (!class_exists('SSO')) @require_once AEGIS_ROOT . '/src/SSO.php';
        $ssoEnabled = class_exists('SSO') && SSO::isEnabled();
        $ssoName    = $ssoEnabled ? (SSO::config()['sso_provider_name'] ?: 'SSO') : '';
      ?>

      <?php if ($ssoEnabled): ?>
        <a href="/sso/login" class="btn btn-secondary btn-full btn-lg" style="margin-bottom:16px;display:flex;align-items:center;justify-content:center;gap:8px">
          <i class="bi bi-box-arrow-in-right"></i>
          Sign in with <?= Security::h($ssoName) ?>
        </a>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
          <hr style="flex:1;border:none;border-top:1px solid #e5e7eb">
          <span style="color:#9ca3af;font-size:12px;white-space:nowrap">or use password</span>
          <hr style="flex:1;border:none;border-top:1px solid #e5e7eb">
        </div>
      <?php endif; ?>

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
            <button type="button" class="input-addon" onclick="togglePassword('password')">
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

<script>
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
</script>
</body>
</html>
