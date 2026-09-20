<?php
/** Local sign-in page. Provided by AuthController::login(). $NONCE, $entraConfigured, $dbConfigured, $error. */
use Redoubt\Support\Security;
$NONCE = $NONCE ?? '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — REDOUBT</title>
<link rel="stylesheet" href="/assets/app.css">
<style nonce="<?= Security::h($NONCE) ?>">
  body{display:flex;min-height:100vh;align-items:center;justify-content:center}
  .auth{width:100%;max-width:400px;padding:24px}
  .auth .brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;justify-content:center;margin-bottom:4px;color:var(--ink)}
  .auth .logo{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--gold),#e6c05a);display:inline-block}
  .auth .sub{text-align:center;color:var(--muted);font-size:13px;margin:0 0 18px}
  .auth .err{background:color-mix(in srgb,var(--risk) 12%,transparent);border:1px solid var(--risk);color:var(--risk);padding:10px 12px;border-radius:9px;font-size:13.5px;margin-bottom:12px}
  .auth .foot{text-align:center;margin-top:16px;font-size:12.5px}
  .divider{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:12px;margin:14px 0}
  .divider::before,.divider::after{content:"";flex:1;height:1px;background:var(--line)}
</style>
</head>
<body>
<div class="auth">
  <div class="card">
    <div class="brand"><span class="logo"></span> REDOUBT</div>
    <p class="sub">GMRE Program Portal</p>
    <?php if ($error): ?><div class="err"><?= Security::h($error) ?></div><?php endif; ?>
    <?php if (!$dbConfigured): ?>
      <div class="err">The portal database is not configured. Set <code>DATABASE_URL</code> to enable sign-in.</div>
    <?php else: ?>
    <form method="post" action="/auth/local">
      <?= Security::csrfField() ?>
      <label>Email</label>
      <input type="email" name="email" required autofocus autocomplete="username">
      <label>Password</label>
      <input type="password" name="password" required autocomplete="current-password">
      <div style="margin-top:14px"><button class="btn btn-primary" type="submit" style="width:100%">Sign in</button></div>
    </form>
    <?php if ($entraConfigured): ?>
      <div class="divider">or</div>
      <a class="btn" href="/auth/entra" style="width:100%;justify-content:center">Sign in with Microsoft (Entra)</a>
    <?php endif; ?>
    <?php endif; ?>
    <div class="foot"><a href="/about">View program documentation</a></div>
  </div>
</div>
</body>
</html>
