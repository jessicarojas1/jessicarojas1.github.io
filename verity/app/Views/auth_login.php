<?php
/** @var string $NONCE @var string $csrf @var ?string $error @var bool $dbConfigured */
use Verity\Support\Security;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — Verity</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-page">
<div class="login-box">
  <div class="brand"><span class="logo" aria-hidden="true">V</span> Verity</div>
  <div class="tagline">Unified Visibility. Verified Access. Complete Accountability.</div>

  <?php if (!empty($error)): ?><div class="error"><?= Security::h($error) ?></div><?php endif; ?>
  <?php if (!$dbConfigured): ?><div class="error">DATABASE_URL is not configured — sign-in cannot succeed until it is set.</div><?php endif; ?>

  <form method="post" action="/auth/login">
    <?= $csrf ?>
    <div class="field">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" autocomplete="username" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn primary w-full">Sign in</button>
  </form>
  <p class="hint mt-14 text-center">
    Microsoft Entra ID (GCC High) single sign-on is not yet enabled for this deployment.
  </p>
</div>
</body>
</html>
