<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — AEGIS GRC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
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
        <h1>Reset your password</h1>
        <p>Enter a new password for your account</p>
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

      <form method="POST" action="/reset-password/<?= Security::h($token) ?>" class="auth-form" autocomplete="off">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="new_password">
            <i class="bi bi-lock"></i> New Password
          </label>
          <input type="password"
                 id="new_password"
                 name="new_password"
                 class="form-control"
                 placeholder="Enter new password"
                 required
                 autofocus
                 autocomplete="new-password"
                 data-input="updateStrength" data-input-val="1">
          <!-- Strength meter -->
          <div style="margin-top:8px">
            <div style="height:6px;border-radius:3px;background:#e5e7eb;overflow:hidden">
              <div id="strength-bar" style="height:100%;width:0%;border-radius:3px;transition:width 0.3s,background 0.3s"></div>
            </div>
            <div id="strength-label" style="font-size:12px;margin-top:4px;color:#9ca3af"></div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="confirm_password">
            <i class="bi bi-lock-fill"></i> Confirm New Password
          </label>
          <input type="password"
                 id="confirm_password"
                 name="confirm_password"
                 class="form-control"
                 placeholder="Repeat new password"
                 required
                 autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg">
          <i class="bi bi-check-lg"></i> Set New Password
        </button>
      </form>

      <div class="auth-form-footer">
        <p><a href="/login" style="color:var(--primary);text-decoration:none"><i class="bi bi-arrow-left"></i> Back to Sign In</a></p>
        <p style="margin-top:12px">AEGIS GRC &copy; <?= date('Y') ?> &mdash; Enterprise Security Platform</p>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function updateStrength(pwd) {
    var score = 0;
    if (pwd.length >= 8)  score++;
    if (pwd.length >= 12) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^a-zA-Z0-9]/.test(pwd)) score++;

    var bar   = document.getElementById('strength-bar');
    var label = document.getElementById('strength-label');

    if (!pwd.length) {
        bar.style.width = '0%';
        label.textContent = '';
        return;
    }

    var pct, color, text;
    if (score <= 1) {
        pct = '20%'; color = '#ef4444'; text = 'Very weak';
    } else if (score === 2) {
        pct = '40%'; color = '#f97316'; text = 'Weak';
    } else if (score === 3) {
        pct = '60%'; color = '#f59e0b'; text = 'Fair';
    } else if (score === 4) {
        pct = '80%'; color = '#84cc16'; text = 'Good';
    } else {
        pct = '100%'; color = '#22c55e'; text = 'Strong';
    }

    bar.style.width = pct;
    bar.style.background = color;
    label.style.color = color;
    label.textContent = text;
}
</script>
</body>
</html>
