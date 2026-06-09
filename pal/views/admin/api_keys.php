<?php
$pageTitle    = 'API Keys';
$activeModule = 'admin_api_keys';
$breadcrumbs  = [['Administration', '/admin'], ['API Keys', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">API Keys</h1><p class="page-subtitle">Programmatic access credentials</p></div>
</div>

<div class="iam" style="grid-template-columns:1fr 320px">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-key-fill"></i> Keys</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0">
        <thead><tr><th>Name</th><th>Prefix</th><th>Status</th><th>Last Used</th><th>Expires</th><th>Created</th></tr></thead>
        <tbody>
        <?php foreach ($keys as $k): ?>
          <tr>
            <td><?= Security::h($k['name']) ?><?php if (!empty($k['creator'])): ?><br><span class="form-hint">by <?= Security::h($k['creator']) ?></span><?php endif; ?></td>
            <td><span class="chip"><?= Security::h($k['key_prefix']) ?>…</span></td>
            <td><?php if (in_array(strtolower((string)$k['is_active']), ['1','t','true','yes','on'], true)): ?><span class="badge badge-green">Active</span><?php else: ?><span class="badge badge-gray">Inactive</span><?php endif; ?></td>
            <td class="form-hint"><?= $k['last_used'] ? Security::h(View::timeAgo($k['last_used'])) : 'never' ?></td>
            <td class="form-hint"><?= $k['expires_at'] ? Security::h(View::fmtDate($k['expires_at'])) : '—' ?></td>
            <td class="form-hint"><?= Security::h(View::fmtDate($k['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$keys): ?>
          <tr><td colspan="6" class="empty-row"><div class="empty-state-sm"><i class="bi bi-key"></i><p>No API keys yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-plus-lg"></i> New API Key</span></div></div>
    <div class="card-body">
      <form method="POST" action="/admin/api-keys">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label" for="key_name">Name</label><input type="text" id="key_name" name="name" class="form-control" required maxlength="120"></div>
        <div class="form-group"><label class="form-label" for="key_expires">Expires (optional)</label><input type="date" id="key_expires" name="expires_at" class="form-control"></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary btn-full"><i class="bi bi-plus-lg"></i> Generate Key</button></div>
        <div class="form-hint" style="margin-top:10px"><i class="bi bi-info-circle"></i> The full key is shown once after creation — copy it immediately.</div>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
