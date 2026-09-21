<?php
/** Sign-in / landing page. Provided by AuthController::login().
 *  In scope: $NONCE, $entraConfigured, $dbConfigured, $error. */
use Redoubt\Support\Security;
$NONCE = $NONCE ?? '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in — REDOUBT</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css">
<style nonce="<?= Security::h($NONCE) ?>">
  :root{--navy:#0a1a30;--navy2:#12294a;--gold:#c8992e}
  body{margin:0;font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg)}
  .classline{background:#14532d;color:#eaf2fb;text-align:center;font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:700;padding:6px 12px}
  .auth-wrap{display:grid;grid-template-columns:1.1fr .9fr;min-height:calc(100vh - 28px)}
  /* Hero */
  .hero{background:radial-gradient(1200px 500px at -10% -10%,rgba(200,153,46,.25),transparent 55%),linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:52px 56px;display:flex;flex-direction:column;justify-content:center;position:relative;overflow:hidden;border-bottom:3px solid var(--gold)}
  .hero::after{content:"";position:absolute;inset:0;background:repeating-linear-gradient(135deg,rgba(255,255,255,.03) 0 2px,transparent 2px 22px);pointer-events:none}
  .hero .mark{display:flex;align-items:center;gap:12px;font-weight:800;font-size:26px;letter-spacing:-.4px}
  .hero .logo{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--gold),#e6c05a);box-shadow:0 6px 18px rgba(200,153,46,.35)}
  .hero .logo span{display:block}
  .hero h1{font-size:clamp(26px,3.2vw,40px);line-height:1.15;margin:26px 0 10px;font-weight:800;max-width:15ch}
  .hero h1 em{color:var(--gold);font-style:normal}
  .hero p.lead{color:#cfe0f2;font-size:16px;max-width:44ch;margin:0 0 26px}
  .hero ul{list-style:none;padding:0;margin:0;display:grid;gap:12px;max-width:46ch}
  .hero li{display:flex;gap:12px;align-items:flex-start;font-size:14.5px;color:#eaf2fb}
  .hero li .ck{flex:0 0 auto;width:22px;height:22px;border-radius:50%;background:rgba(200,153,46,.2);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;margin-top:1px}
  .hero .foot{margin-top:34px;color:#9fc6ea;font-size:12px;letter-spacing:.04em}
  /* Panel */
  .panel{display:flex;align-items:center;justify-content:center;padding:40px 24px;background:var(--bg)}
  .auth{width:100%;max-width:380px}
  .auth h2{margin:0 0 4px;font-size:20px}
  .auth .sub{color:var(--muted);font-size:13px;margin:0 0 18px}
  .auth .err{background:color-mix(in srgb,var(--risk) 12%,transparent);border:1px solid var(--risk);color:var(--risk);padding:10px 12px;border-radius:9px;font-size:13.5px;margin-bottom:12px}
  .auth .foot{text-align:center;margin-top:16px;font-size:12.5px;color:var(--muted)}
  .divider{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:12px;margin:14px 0}
  .divider::before,.divider::after{content:"";flex:1;height:1px;background:var(--line)}
  .btn-ms{display:flex;width:100%;justify-content:center;align-items:center;gap:8px}
  @media(max-width:820px){
    .auth-wrap{grid-template-columns:1fr}
    .hero{padding:34px 28px}
    .hero h1{margin-top:16px}
    .hero ul,.hero .foot{display:none}
  }
</style>
</head>
<body>
<div class="classline">Controlled — For Authorized Program Personnel · classification banner configurable per program</div>
<div class="auth-wrap">
  <section class="hero">
    <div class="mark"><span class="logo"></span> REDOUBT</div>
    <h1>One secure workspace where <em>primes, subcontractors,</em> and the <em>customer</em> execute together.</h1>
    <p class="lead">The GMRE Program Portal Framework — the authoritative entry point for proposal &amp; program execution. One framework, many isolated program instances.</p>
    <ul>
      <li><span class="ck">✓</span> <span>Role-aware access down to <strong>program × company × zone</strong>, with a hard <strong>US-person export gate</strong> for CUI / ITAR.</span></li>
      <li><span class="ck">✓</span> <span>Announcements, documents, task orders, jobs, milestones, directory &amp; permission-aware search — one place.</span></li>
      <li><span class="ck">✓</span> <span>Built to <strong>CMMC / NIST SP 800-171</strong> expectations; Microsoft 365 GCC High as the document system of record.</span></li>
      <li><span class="ck">✓</span> <span>Rapid, governed subcontractor onboarding — bring a whole team online in a day.</span></li>
    </ul>
    <div class="foot">Aerospace &amp; Defense · Pre-decisional · Handle per program classification guidance</div>
  </section>

  <section class="panel">
    <div class="auth">
      <h2>Sign in</h2>
      <p class="sub">Access your program workspace</p>
      <?php if (!empty($error)): ?><div class="err"><?= Security::h($error) ?></div><?php endif; ?>
      <?php if (empty($dbConfigured)): ?>
        <div class="err">The portal database is not configured. Set <code>DATABASE_URL</code> to enable sign-in.</div>
      <?php else: ?>
      <div class="card">
        <form method="post" action="/auth/local">
          <?= Security::csrfField() ?>
          <label>Email</label>
          <input type="email" name="email" required autofocus autocomplete="username">
          <label>Password</label>
          <input type="password" name="password" required autocomplete="current-password">
          <div style="margin-top:14px"><button class="btn btn-primary" type="submit" style="width:100%;justify-content:center">Sign in</button></div>
        </form>
        <?php if (!empty($entraConfigured)): ?>
          <div class="divider">or</div>
          <a class="btn btn-ms" href="/auth/entra">Sign in with Microsoft (Entra ID)</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="foot"><a href="/">View the program &amp; architecture overview</a></div>
    </div>
  </section>
</div>
</body>
</html>
