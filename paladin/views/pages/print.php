<?php $__brand = Branding::name(); $__logo = Branding::logo(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= Security::h($page['title']) ?> — <?= Security::h($__brand) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style nonce="<?= Security::nonce() ?>">
  *{box-sizing:border-box}
  body{font-family:Inter,system-ui,sans-serif;color:#111827;max-width:820px;margin:0 auto;padding:40px 32px;line-height:1.65}
  .doc-head{display:flex;align-items:center;gap:12px;border-bottom:2px solid #e5e7eb;padding-bottom:14px;margin-bottom:20px}
  .doc-head .mark{width:40px;height:40px;border-radius:9px;background:#0ea5e9;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}
  .doc-head img{width:40px;height:40px;object-fit:contain;border-radius:9px}
  .brand{font-weight:800;letter-spacing:1px}
  .muted{color:#6b7280;font-size:12px}
  h1.title{font-size:1.7rem;margin:6px 0 4px}
  .meta{font-size:12px;color:#6b7280;margin-bottom:6px}
  .labels{margin:8px 0 18px}
  .labels .lbl{display:inline-block;background:#f1f5f9;color:#334155;border-radius:999px;padding:2px 10px;font-size:11px;margin-right:6px}
  .prose h1,.prose h2,.prose h3{margin:1.1em 0 .4em}
  .prose table{border-collapse:collapse;width:100%;margin:1em 0}
  .prose th,.prose td{border:1px solid #d1d5db;padding:7px 10px;text-align:left}
  .prose pre{background:#f3f4f6;padding:12px;border-radius:6px;overflow:auto}
  .prose blockquote{border-left:3px solid #0ea5e9;margin:1em 0;padding:2px 14px;color:#4b5563}
  .footer{margin-top:30px;border-top:1px solid #e5e7eb;padding-top:10px;font-size:11px;color:#9ca3af}
  .toolbar{margin-bottom:18px}
  .toolbar button,.toolbar a{font:inherit;font-size:13px;padding:7px 14px;border-radius:7px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;cursor:pointer;text-decoration:none}
  .toolbar button{background:#0ea5e9;color:#fff;border-color:#0ea5e9}
  @media print{.toolbar{display:none}body{padding:0}}
</style>
</head>
<body>
  <div class="toolbar">
    <button type="button" id="printBtn"><i></i>Save as PDF / Print</button>
    <a href="/pages/<?= (int)$page['id'] ?>">← Back to page</a>
  </div>
  <div class="doc-head">
    <?php if ($__logo): ?><img src="<?= Security::h($__logo) ?>" alt=""><?php else: ?><div class="mark"><i class="bi"></i><?= Security::h(strtoupper(substr($__brand,0,1))) ?></div><?php endif; ?>
    <div>
      <div class="brand"><?= Security::h($__brand) ?></div>
      <div class="muted"><?= Security::h($page['space_name']) ?> (<?= Security::h($page['space_key']) ?>)</div>
    </div>
  </div>

  <h1 class="title"><?= Security::h($page['title']) ?></h1>
  <div class="meta">
    Owner: <?= Security::h($page['owner_name'] ?: '—') ?> ·
    Status: <?= Security::h(ucfirst(str_replace('_',' ',$page['status']))) ?> ·
    Version <?= (int)$page['current_version'] ?> ·
    Updated <?= View::fmtDate($page['updated_at'], 'M j, Y') ?>
  </div>
  <?php if ($labels): ?><div class="labels"><?php foreach ($labels as $l): ?><span class="lbl"><?= Security::h($l['name']) ?></span><?php endforeach; ?></div><?php endif; ?>

  <div class="prose"><?= $page['body'] ?: '<p class="muted">This page has no content.</p>' ?></div>

  <div class="footer">Exported from <?= Security::h($__brand) ?> on <?= date('M j, Y g:ia') ?> · <?= Security::h($page['space_key']) ?> / <?= Security::h($page['title']) ?></div>

<script nonce="<?= Security::nonce() ?>">
  document.getElementById('printBtn').addEventListener('click', function(){ window.print(); });
  // Auto-open the print dialog shortly after load
  window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });
</script>
</body>
</html>
