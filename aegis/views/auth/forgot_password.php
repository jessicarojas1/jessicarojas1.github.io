<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — AEGIS GRC</title>
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
          <div class="auth-brand-tagline">Enterprise Governance &amp; Compliance Platform</div>
        </div>
      </div>
      <div class="auth-features">
        <div class="auth-feature"><i class="bi bi-shield-check"></i><span>CMMC, ISO 27001, ISO 42001 built-in</span></div>
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
        <p style="margin-top:12px">AEGIS GRC &copy; <?= date('Y') ?> &mdash; Enterprise Security Platform</p>
      </div>
    </div>
  </div>
</div>

</body>
</html>
