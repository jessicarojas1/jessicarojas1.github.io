<?php ob_start();
$breadcrumbs = [['Admin', '/admin'], ['Storage', null]]; ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Storage Settings</h1>
    <p class="page-subtitle">Configure where uploaded files are stored — local disk or S3-compatible object storage</p>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

<form method="POST" action="/admin/storage/save" style="flex:2;min-width:320px">
  <?= Security::csrfField() ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Storage Driver</h3></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
      <div class="form-group">
        <label class="form-label">Driver</label>
        <select name="storage_driver" class="form-control" id="driverSelect" data-change="toggleS3Fields">
          <option value="local" <?= ($storageSettings['storage_driver'] ?? 'local') === 'local' ? 'selected' : '' ?>>Local Disk (uploads/ directory)</option>
          <option value="s3" <?= ($storageSettings['storage_driver'] ?? '') === 's3' ? 'selected' : '' ?>>S3 / MinIO / Cloudflare R2</option>
        </select>
        <p class="form-hint">Local disk is sufficient for single-server deployments. Use S3-compatible storage for multi-server or cloud deployments.</p>
      </div>
    </div>
  </div>

  <div class="card" id="s3Fields" style="margin-bottom:16px;<?= ($storageSettings['storage_driver'] ?? 'local') !== 's3' ? 'display:none' : '' ?>">
    <div class="card-header"><h3>S3 Configuration</h3></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
      <div class="form-row">
        <div class="form-group" style="flex:2">
          <label class="form-label">Bucket Name <span class="required">*</span></label>
          <input type="text" name="s3_bucket" class="form-control" value="<?= Security::h($storageSettings['s3_bucket'] ?? '') ?>" placeholder="my-aegis-bucket">
        </div>
        <div class="form-group">
          <label class="form-label">Region</label>
          <input type="text" name="s3_region" class="form-control" value="<?= Security::h($storageSettings['s3_region'] ?? 'us-east-1') ?>" placeholder="us-east-1">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Access Key ID <span class="required">*</span></label>
          <input type="text" name="s3_access_key" class="form-control" autocomplete="off" value="<?= Security::h($storageSettings['s3_access_key'] ?? '') ?>" placeholder="AKIAIOSFODNN7EXAMPLE">
        </div>
        <div class="form-group">
          <label class="form-label">Secret Access Key</label>
          <input type="password" name="s3_secret_key" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep current value">
          <p class="form-hint">Only enter a value to change the stored key.</p>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Custom Endpoint <span class="text-muted text-xs">(MinIO / R2 — leave blank for AWS S3)</span></label>
        <input type="url" name="s3_endpoint" class="form-control" value="<?= Security::h($storageSettings['s3_endpoint'] ?? '') ?>" placeholder="https://your-minio.example.com">
      </div>
      <div class="form-group">
        <label class="form-label">Public CDN URL <span class="text-muted text-xs">(optional — skips presigned URLs)</span></label>
        <input type="url" name="s3_public_url" class="form-control" value="<?= Security::h($storageSettings['s3_public_url'] ?? '') ?>" placeholder="https://cdn.your-domain.com">
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-footer" style="display:flex;gap:8px;padding:16px">
      <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
      <button type="button" class="btn btn-secondary" id="testBtn" data-click="testStorage"><i class="bi bi-lightning-charge"></i> Test Connection</button>
    </div>
  </div>
</form>

<div style="flex:1;min-width:260px">
  <div class="card" style="margin-bottom:16px">
    <div class="card-header"><h3>Current Status</h3></div>
    <div class="card-body">
      <table class="desc-table">
        <tr><th>Driver</th><td><strong><?= Security::h(ucfirst($storageSettings['storage_driver'] ?? 'local')) ?></strong></td></tr>
        <?php if (($storageSettings['storage_driver'] ?? 'local') === 'local'): ?>
          <tr><th>Path</th><td><code>uploads/</code></td></tr>
          <?php $free = disk_free_space(AEGIS_ROOT . '/uploads') ?: disk_free_space(AEGIS_ROOT); ?>
          <tr><th>Disk Free</th><td><?= $free !== false ? round($free / 1073741824, 1) . ' GB' : '—' ?></td></tr>
        <?php else: ?>
          <tr><th>Bucket</th><td><?= Security::h($storageSettings['s3_bucket'] ?? '—') ?></td></tr>
          <tr><th>Region</th><td><?= Security::h($storageSettings['s3_region'] ?? '—') ?></td></tr>
          <?php if (!empty($storageSettings['s3_endpoint'])): ?>
            <tr><th>Endpoint</th><td class="text-xs text-muted"><?= Security::h($storageSettings['s3_endpoint']) ?></td></tr>
          <?php endif; ?>
        <?php endif; ?>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>Test Result</h3></div>
    <div class="card-body" id="testResult">
      <p class="text-muted text-sm">Click "Test Connection" to verify storage is reachable.</p>
    </div>
  </div>
</div>

</div>

<script nonce="<?= Security::nonce() ?>">
function toggleS3Fields() {
  var driver = document.getElementById('driverSelect').value;
  document.getElementById('s3Fields').style.display = driver === 's3' ? 'block' : 'none';
}
function testStorage() {
  var btn = document.getElementById('testBtn');
  var res = document.getElementById('testResult');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing…';
  res.innerHTML = '<p class="text-muted text-sm">Testing…</p>';
  var csrf = document.querySelector('input[name=csrf_token]').value;
  fetch('/admin/storage/test', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'csrf_token=' + encodeURIComponent(csrf) })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-lightning-charge"></i> Test Connection';
      res.innerHTML = d.ok
        ? '<div class="alert-box success" style="margin:0"><i class="bi bi-check-circle-fill"></i> ' + d.message + '</div>'
        : '<div class="alert-box error" style="margin:0"><i class="bi bi-x-circle-fill"></i> ' + (d.error || 'Test failed') + '</div>';
    })
    .catch(function() {
      btn.disabled = false; btn.innerHTML = '<i class="bi bi-lightning-charge"></i> Test Connection';
      res.innerHTML = '<div class="alert-box error" style="margin:0">Request failed.</div>';
    });
}
</script>
<?php $content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php'; ?>
