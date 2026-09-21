<?php
/** First-run setup. Provided by SetupController::index(). $NONCE, $error. */
use Redoubt\Support\Security;
$NONCE = $NONCE ?? '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up REDOUBT</title>
<link rel="stylesheet" href="/assets/app.css">
<style nonce="<?= Security::h($NONCE) ?>">
  body{margin:0;min-height:100vh;display:flex;flex-direction:column}
  .classline{background:#14532d;color:#eaf2fb;text-align:center;font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:700;padding:6px 12px}
  .center{flex:1;display:flex;align-items:center;justify-content:center;padding:24px}
  .auth{width:100%;max-width:480px}
  .card{border-top:3px solid var(--gold)}
  .brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:22px;justify-content:center;margin:2px 0 2px;color:var(--ink)}
  .logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--gold),#e6c05a);display:inline-block}
  .sub{text-align:center;color:var(--muted);font-size:13px;margin:0 0 18px}
  .err{background:color-mix(in srgb,var(--risk) 12%,transparent);border:1px solid var(--risk);color:var(--risk);padding:10px 12px;border-radius:9px;font-size:13.5px;margin-bottom:12px}
</style>
</head>
<body>
<div class="classline">Controlled — First-run configuration · you will become the Enterprise Administrator</div>
<div class="center">
<div class="auth">
  <div class="card">
    <div class="brand"><span class="logo"></span> REDOUBT</div>
    <p class="sub">Stand up a new program in minutes — create your program &amp; administrator</p>
    <?php if ($error): ?><div class="err"><?= Security::h($error) ?></div><?php endif; ?>
    <form method="post" action="/setup">
      <?= Security::csrfField() ?>
      <label>Program name</label>
      <input type="text" name="program_name" required placeholder="Falcon Program" autofocus>
      <label>Your name (administrator)</label>
      <input type="text" name="admin_name" required>
      <label>Your email</label>
      <input type="email" name="admin_email" required autocomplete="username">
      <label>Password (min 8 characters)</label>
      <input type="password" name="admin_password" required minlength="8" autocomplete="new-password">
      <label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-weight:500">
        <input type="checkbox" name="load_sample" value="1" style="width:auto" checked> Load sample program data (announcements, documents, task orders, jobs, contacts)
      </label>
      <div style="margin-top:16px"><button class="btn btn-primary" type="submit" style="width:100%">Create &amp; continue</button></div>
    </form>
    <p class="sub" style="margin-top:14px">You'll become the Enterprise Administrator. Microsoft Entra SSO is optional and can be added later.</p>
  </div>
</div>
</div>
</body>
</html>
