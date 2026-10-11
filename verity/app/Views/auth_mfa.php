<?php
/** @var string $NONCE @var string $csrf @var ?string $error */
use Verity\Support\Security;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify it's you — Verity</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="login-page">
<div class="login-box">
  <div class="brand"><span class="logo" aria-hidden="true">V</span> Verity</div>
  <div class="tagline">Two-factor verification</div>

  <?php if (!empty($error)): ?><div class="error"><?= Security::h($error) ?></div><?php endif; ?>

  <form method="post" action="/auth/mfa">
    <?= $csrf ?>
    <div class="field">
      <label for="code">Code from your authenticator app</label>
      <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456 or a recovery code" required autofocus>
    </div>
    <button type="submit" class="btn primary w-full">Verify</button>
  </form>
  <p class="hint mt-14 text-center">
    Lost your device? Enter one of your recovery codes instead of a 6-digit code.
  </p>
  <p class="hint text-center">
    <a href="/auth/logout">Cancel and sign in as someone else</a>
  </p>
</div>
</body>
</html>
