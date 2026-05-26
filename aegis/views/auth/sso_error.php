<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SSO Error — AEGIS GRC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/public/css/app.css">
</head>
<body class="auth-body">
<div class="auth-split">
  <div class="auth-left">
    <div class="auth-left-content">
      <div class="auth-brand">
        <div class="auth-brand-icon"><i class="bi bi-shield-fill-check"></i></div>
        <div>
          <div class="auth-brand-name">AEGIS GRC</div>
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
</body>
</html>
