<?php
// Generic server-error page. NEVER render exception messages, stack traces,
// SQL, filesystem paths, or environment data here — only a correlation ID the
// user can quote to an administrator, who can then find the detail in the logs.
$__rid = defined('AEGIS_REQUEST_ID') ? AEGIS_REQUEST_ID : '';
?><!DOCTYPE html>
<html lang="en" style="color-scheme:light dark"><head><meta charset="UTF-8"><title>500 — AEGIS GRC</title>
<style>body{font-family:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f2f4;}.box{text-align:center;padding:48px;}.icon{font-size:64px;margin-bottom:16px;}.title{font-size:24px;font-weight:700;color:var(--text);}.sub{color:var(--text-muted);margin:8px 0 24px;}.rid{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;color:var(--text-muted);background:rgba(127,127,127,.12);padding:4px 10px;border-radius:6px;display:inline-block;margin-bottom:24px;}a{color:var(--primary);text-decoration:none;padding:10px 20px;border:2px solid var(--primary);border-radius:8px;}</style>
</head><body><div class="box"><div class="icon">⚠️</div><div class="title">500 — Something Went Wrong</div><div class="sub">An unexpected error occurred. Our team has been notified.</div>
<?php if ($__rid): ?><div class="rid">Reference: <?= htmlspecialchars($__rid, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div><br><?php endif; ?>
<a href="/">Back to Dashboard</a></div></body></html>
