<?php $__brand = Branding::name(); $__logo = Branding::logo(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= Security::h($space['name']) ?> — <?= Security::h($__brand) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style nonce="<?= Security::nonce() ?>">
  *{box-sizing:border-box}
  body{font-family:Inter,system-ui,sans-serif;color:#111827;max-width:820px;margin:0 auto;padding:40px 32px;line-height:1.65}
  .doc-head{display:flex;align-items:center;gap:12px;border-bottom:2px solid #e5e7eb;padding-bottom:14px;margin-bottom:20px}
  .doc-head .mark{width:40px;height:40px;border-radius:9px;background:#0ea5e9;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}
  .doc-head img{width:40px;height:40px;object-fit:contain;border-radius:9px}
  .brand{font-weight:800;letter-spacing:1px}
  .muted{color:#6b7280;font-size:12px}
  h1.space-title{font-size:1.9rem;margin:6px 0 2px}
  .toc{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;margin:18px 0 24px}
  .toc h2{font-size:.85rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:0 0 8px}
  .toc ol{margin:0;padding-left:20px}
  .toc a{color:#0ea5e9;text-decoration:none}
  article{border-top:1px solid #e5e7eb;padding-top:22px;margin-top:22px;page-break-before:always}
  article:first-of-type{page-break-before:avoid}
  h2.page-title{font-size:1.45rem;margin:0 0 4px}
  .meta{font-size:12px;color:#6b7280;margin-bottom:10px}
  .prose h1,.prose h2,.prose h3{margin:1.1em 0 .4em}
  .prose table{border-collapse:collapse;width:100%;margin:1em 0}
  .prose th,.prose td{border:1px solid #d1d5db;padding:7px 10px;text-align:left}
  .prose pre{background:#f3f4f6;padding:12px;border-radius:6px;overflow:auto}
  .prose blockquote{border-left:3px solid #0ea5e9;margin:1em 0;padding:2px 14px;color:#4b5563}
  .footer{margin-top:30px;border-top:1px solid #e5e7eb;padding-top:10px;font-size:11px;color:#9ca3af}
  .toolbar{margin-bottom:18px}
  .toolbar button,.toolbar a{font:inherit;font-size:13px;padding:7px 14px;border-radius:7px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;cursor:pointer;text-decoration:none}
  .toolbar button{background:#0ea5e9;color:#fff;border-color:#0ea5e9}
  .cover{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:78vh;border-bottom:1px solid #e5e7eb;margin-bottom:26px}
  .cover .cmark{width:72px;height:72px;border-radius:16px;background:#0ea5e9;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:2rem;margin-bottom:18px}
  .cover img.clogo{width:84px;height:84px;object-fit:contain;border-radius:16px;margin-bottom:18px}
  .cover .cbrand{font-weight:800;letter-spacing:2px;color:#64748b;text-transform:uppercase;font-size:.8rem}
  .cover h1{font-size:2.4rem;margin:10px 0 4px}
  .cover .ckey{display:inline-block;background:#f1f5f9;color:#334155;border-radius:999px;padding:3px 14px;font-size:.85rem;margin-top:6px}
  .cover .cmeta{color:#6b7280;font-size:13px;margin-top:18px;line-height:1.8}
  .cover .cdesc{max-width:520px;color:#4b5563;margin:14px auto 0}
  @media print{.toolbar{display:none}body{padding:0}.toc a{color:#111827}.cover{min-height:96vh;page-break-after:always}}
</style>
</head>
<body>
  <div class="toolbar">
    <button type="button" id="printBtn">Save as PDF / Print</button>
    <a href="/spaces/<?= (int)$space['id'] ?>">← Back to space</a>
  </div>

  <!-- Cover page -->
  <section class="cover">
    <?php if ($__logo): ?><img class="clogo" src="<?= Security::h($__logo) ?>" alt=""><?php else: ?><div class="cmark"><?= Security::h(strtoupper(substr($__brand,0,1))) ?></div><?php endif; ?>
    <div class="cbrand"><?= Security::h($__brand) ?></div>
    <h1><?= Security::h($space['name']) ?></h1>
    <div class="ckey"><?= Security::h($space['space_key']) ?></div>
    <?php if (!empty($space['description'])): ?><p class="cdesc"><?= Security::h($space['description']) ?></p><?php endif; ?>
    <div class="cmeta">
      Owner: <?= Security::h($space['owner_name'] ?: '—') ?><br>
      <?= count($pages) ?> published page(s)<br>
      Exported <?= date('F j, Y') ?>
    </div>
  </section>

  <div class="doc-head">
    <?php if ($__logo): ?><img src="<?= Security::h($__logo) ?>" alt=""><?php else: ?><div class="mark"><?= Security::h(strtoupper(substr($__brand,0,1))) ?></div><?php endif; ?>
    <div>
      <div class="brand"><?= Security::h($__brand) ?></div>
      <div class="muted">Space export</div>
    </div>
  </div>

  <h1 class="space-title"><?= Security::h($space['name']) ?> <span class="muted">(<?= Security::h($space['space_key']) ?>)</span></h1>
  <div class="meta">Owner: <?= Security::h($space['owner_name'] ?: '—') ?> · <?= count($pages) ?> published page(s) · Exported <?= date('M j, Y g:ia') ?></div>

  <?php if ($pages): ?>
  <div class="toc">
    <h2>Contents</h2>
    <ol>
      <?php foreach ($pages as $p): ?>
        <li><a href="#page-<?= (int)$p['id'] ?>"><?= Security::h($p['title']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </div>

  <?php foreach ($pages as $p): ?>
    <article id="page-<?= (int)$p['id'] ?>">
      <h2 class="page-title"><?= Security::h($p['title']) ?></h2>
      <div class="meta">Updated <?= View::fmtDate($p['updated_at'], 'M j, Y') ?></div>
      <div class="prose"><?= $p['body'] ?: '<p class="muted">This page has no content.</p>' ?></div>
    </article>
  <?php endforeach; ?>
  <?php else: ?>
    <p class="muted">This space has no published pages to export.</p>
  <?php endif; ?>

  <div class="footer">Exported from <?= Security::h($__brand) ?> · <?= Security::h($space['space_key']) ?> · <?= date('M j, Y g:ia') ?></div>

<script nonce="<?= Security::nonce() ?>">
  document.getElementById('printBtn').addEventListener('click', function(){ window.print(); });
</script>
</body>
</html>
