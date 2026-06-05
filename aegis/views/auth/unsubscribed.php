<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Unsubscribed — AEGIS GRC</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Inter,system-ui,sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.card{background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.1);padding:48px 40px;max-width:480px;width:100%;text-align:center}
.icon{width:72px;height:72px;border-radius:50%;background:var(--success-subtle);color:var(--primary);display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:32px}
h1{font-size:24px;font-weight:700;color:var(--text);margin-bottom:12px}
p{color:var(--text-muted);line-height:1.6;margin-bottom:24px}
.type-badge{display:inline-block;padding:4px 14px;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;border-radius:20px;font-size:13px;font-weight:600;margin-bottom:20px}
.btn{display:inline-block;padding:12px 28px;background:var(--primary);color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:15px}
.btn-ghost{background:transparent;color:var(--primary);border:1px solid var(--primary);margin-left:8px}
.logo{font-size:13px;color:#a1a1aa;margin-top:32px;padding-top:24px;border-top:1px solid #f4f4f5}
</style>
</head>
<body>
<div class="card">
  <?php if (!empty($error)): ?>
    <div class="icon" style="background:var(--danger-subtle);color:var(--danger)">&#10007;</div>
    <h1>Invalid Link</h1>
    <p>This unsubscribe link is invalid or has already been used. Please log in to manage your notification preferences directly.</p>
  <?php else: ?>
    <div class="icon">&#10003;</div>
    <h1>Unsubscribed</h1>
    <?php if ($notificationType === 'all'): ?>
      <p>You have been unsubscribed from <strong>all AEGIS notification emails</strong>.</p>
    <?php else: ?>
      <div class="type-badge"><?= Security::h(ucwords(str_replace('_',' ',$notificationType))) ?></div>
      <p>You have been unsubscribed from <strong><?= Security::h(str_replace('_',' ',$notificationType)) ?></strong> notification emails.</p>
    <?php endif; ?>
    <p style="font-size:13px">To manage all notification preferences, log in and visit your profile settings.</p>
  <?php endif; ?>
  <a href="/login" class="btn">Log In</a>
  <a href="/profile/notifications" class="btn btn-ghost">Preferences</a>
  <div class="logo">AEGIS GRC &mdash; Enterprise Governance &amp; Compliance Platform</div>
</div>
</body>
</html>
