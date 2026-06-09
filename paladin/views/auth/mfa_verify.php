<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__brandName = Branding::name(); $__brandLogo = Branding::logo(); ?>
<title>Two-Factor Verification — <?= Security::h($__brandName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="/public/css/app.css?v=3">
<script nonce="<?= Security::nonce() ?>">(function(){if(localStorage.getItem('paladin-theme')==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
<?= Branding::accentStyleTag() ?>
</head>
<body class="auth-body">
<div class="auth-split">
  <div class="auth-left">
    <div class="auth-left-content">
      <div class="auth-brand">
        <?php if ($__brandLogo): ?><img src="<?= Security::h($__brandLogo) ?>" class="auth-brand-logo" data-logo-fallback style="width:48px;height:48px;object-fit:contain;border-radius:10px"><div class="auth-brand-icon brand-logo-fallback" style="display:none"><i class="bi bi-shield-lock-fill"></i></div>
        <?php else: ?><div class="auth-brand-icon"><i class="bi bi-shield-lock-fill"></i></div><?php endif; ?>
        <div><div class="auth-brand-name"><?= Security::h($__brandName) ?></div><div class="auth-brand-tagline">Process · Approval · Library</div></div>
      </div>
      <div class="auth-features">
        <div class="auth-feature"><i class="bi bi-shield-check"></i><span>Two-factor authentication protects your account</span></div>
        <div class="auth-feature"><i class="bi bi-phone"></i><span>Enter the 6-digit code from your authenticator app</span></div>
      </div>
      <div class="auth-left-footer">Securing organizational knowledge.</div>
    </div>
  </div>
  <div class="auth-right">
    <div class="auth-form-card">
      <div class="auth-form-header"><h1>Two-step verification</h1><p>Enter the 6-digit code from your authenticator app</p></div>
      <?php if (!empty($error)): ?><div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($error) ?></div><?php endif; ?>
      <form method="POST" action="/mfa/verify" class="auth-form" autocomplete="off">
        <?= Security::csrfField() ?>
        <div class="form-group">
          <label class="form-label" for="code"><i class="bi bi-123"></i> Authentication code</label>
          <input type="text" id="code" name="code" class="form-control" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="123456" required autofocus autocomplete="one-time-code" style="letter-spacing:.3em;font-size:1.2rem;text-align:center">
        </div>
        <button type="submit" class="btn btn-primary btn-full btn-lg"><i class="bi bi-shield-check"></i> Verify</button>
      </form>
      <div class="auth-form-footer"><p><a href="/login">← Back to sign in</a></p></div>
    </div>
  </div>
</div>
<script nonce="<?= Security::nonce() ?>">
document.querySelectorAll('img[data-logo-fallback]').forEach(function(img){img.addEventListener('error',function(){img.style.display='none';var fb=img.parentElement?img.parentElement.querySelector('.brand-logo-fallback'):null;if(fb)fb.style.display='';});});
</script>
</body>
</html>
