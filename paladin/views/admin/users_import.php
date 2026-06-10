<?php
$pageTitle    = 'Import Users';
$activeModule = 'admin_users';
$breadcrumbs  = [['Admin', '/admin'], ['Users', '/admin/users'], ['Import', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Bulk import users</h1><p class="page-subtitle">Create many accounts at once from CSV.</p></div>
  <div class="page-actions"><a href="/admin/users" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to users</a></div>
</div>

<?php if ($result): ?>
<div class="card" style="margin-bottom:18px"><div class="card-body">
  <div class="banner ok" style="margin:0 0 <?= $result['skipped'] ? '12px' : '0' ?>"><i class="bi bi-check-circle-fill"></i><div class="banner-body"><strong><?= (int)$result['created'] ?></strong> user(s) created. New accounts are set to <em>force password change</em>.</div></div>
  <?php if ($result['skipped']): ?>
  <div class="banner warn" style="margin:0"><i class="bi bi-exclamation-triangle-fill"></i><div class="banner-body"><strong><?= count($result['skipped']) ?> skipped:</strong>
    <ul style="margin:6px 0 0;padding-left:18px"><?php foreach ($result['skipped'] as $s): ?><li><?= Security::h($s) ?></li><?php endforeach; ?></ul>
  </div></div>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-filetype-csv"></i> CSV rows</span></div></div>
  <div class="card-body">
    <form method="POST" action="/admin/users/import">
      <?= Security::csrfField() ?>
      <div class="form-group">
        <label class="form-label" for="csv">Paste CSV</label>
        <textarea id="csv" name="csv" class="form-control" rows="10" placeholder="name,email,role,department,title&#10;Jane Roe,jane@example.gov,contributor,Quality,Analyst&#10;John Doe,john@example.gov,approver,Compliance,Manager" style="font-family:monospace;font-size:.85rem"></textarea>
      </div>
      <div class="form-hint" style="margin-bottom:12px">
        <strong>Field reference</strong> — one user per line, comma-separated:
        <ul style="margin:6px 0 0;padding-left:18px">
          <li><code>name</code> (required) · <code>email</code> (required, unique)</li>
          <li><code>role</code> (optional, defaults to <code>viewer</code>; unknown roles fall back to viewer)</li>
          <li><code>department</code> (optional) · <code>title</code> (optional)</li>
        </ul>
        A leading <code>name,email,…</code> header row is detected and skipped. Existing emails are skipped. Each new account gets a random password and must reset on first login (or use SSO).
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-upload"></i> Import users</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
