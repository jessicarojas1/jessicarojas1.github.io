<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $verified ? 'Email Verified' : 'Verification Failed' ?> — AEGIS GRC</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.1);padding:48px 40px;max-width:480px;width:100%;text-align:center}
.icon{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:32px}
.icon.success{background:#f0fdf4;color:#16a34a}
.icon.error{background:#fef2f2;color:#dc2626}
h1{font-size:24px;font-weight:700;color:var(--text);margin-bottom:12px}
p{color:var(--text-muted);line-height:1.6;margin-bottom:24px}
.btn{display:inline-block;padding:12px 28px;background:var(--primary);color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:15px;transition:opacity .15s}
.btn:hover{opacity:.9}
.logo{font-size:13px;color:#a1a1aa;margin-top:32px;padding-top:24px;border-top:1px solid #f4f4f5}
</style>
</head>
<body>
<div class="card">
  <?php if ($verified): ?>
    <div class="icon success">&#10003;</div>
    <h1>Email Verified</h1>
    <p>Your AEGIS GRC email address has been verified successfully. Your account is now active.</p>
    <a href="/login" class="btn">Log In</a>
  <?php else: ?>
    <div class="icon error">&#10007;</div>
    <h1>Verification Failed</h1>
    <p>This verification link is invalid or has expired (links are valid for 24 hours). Please contact your administrator to resend a new verification email.</p>
    <a href="/login" class="btn">Back to Login</a>
  <?php endif; ?>
  <div class="logo">AEGIS GRC &mdash; Enterprise Governance &amp; Compliance Platform</div>
</div>
</body>
</html>
