<?php
$pageTitle    = 'API Keys';
$activeModule = 'admin_api_keys';
$breadcrumbs  = [['Admin','/admin'],['API Keys',null]];
ob_start();
?>

<?php if (!empty($_GET['created']) && !empty($_SESSION['new_api_key'])): ?>
  <div class="alert-box success" style="font-family:monospace">
    <i class="bi bi-key-fill"></i>
    <strong>API Key Created — copy now, it won't be shown again:</strong><br>
    <code id="newKey" style="background:#1e293b;color:#a5f3fc;padding:8px 12px;border-radius:6px;display:block;margin-top:8px;font-size:14px;word-break:break-all"><?= Security::h($_SESSION['new_api_key']) ?></code>
    <button id="copyKeyBtn" class="btn btn-ghost btn-sm" style="margin-top:8px"><i class="bi bi-clipboard"></i> Copy</button>
    <?php unset($_SESSION['new_api_key']); ?>
  </div>
<?php endif; ?>

<?php if (!empty($_GET['revoked'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> API key revoked.</div><?php endif; ?>

<div class="page-header">
  <h1 class="page-title">API Key Management</h1>
  <button class="btn btn-primary" id="openCreateKeyBtn"><i class="bi bi-plus-lg"></i> New API Key</button>
</div>

<!-- API Overview -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><h3 class="card-title"><i class="bi bi-code-square"></i> API Documentation</h3></div>
  <div class="card-body">
    <p class="text-muted">Base URL: <code>/api/v1</code></p>
    <div class="api-endpoints">
      <?php
      $endpoints = [
        ['GET', '/auth/token','Issue JWT token (email+password)'],
        ['GET', '/dashboard/stats','Compliance & risk statistics'],
        ['GET', '/standards','List all standards'],
        ['GET', '/compliance/packages','List compliance packages'],
        ['GET', '/compliance/packages/{id}/objectives','List objectives for a package'],
        ['PUT', '/compliance/objectives/{id}/status','Update control implementation status'],
        ['GET', '/risks','List all risks'],
        ['POST','/risks','Create a new risk'],
        ['GET', '/policies','List all policies'],
        ['GET', '/audits','List all audits'],
        ['GET', '/users','List users (admin only)'],
      ];
      foreach ($endpoints as [$method,$path,$desc]): ?>
        <div class="api-endpoint">
          <span class="method-badge method-<?= strtolower($method) ?>"><?= $method ?></span>
          <code class="endpoint-path"><?= Security::h($path) ?></code>
          <span class="text-muted text-sm"><?= $desc ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="api-auth-info">
      <strong>Authentication:</strong>
      <code>X-API-Key: aegis_your_key_here</code>
      or
      <code>Authorization: Bearer &lt;jwt_token&gt;</code>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead><tr><th>Name</th><th>User</th><th>Prefix</th><th>Permissions</th><th>Expires</th><th>Last Used</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php if ($keys): foreach ($keys as $key): ?>
          <tr <?= !$key['is_active'] ? 'class="row-muted"' : '' ?>>
            <td><strong><?= Security::h($key['name']) ?></strong></td>
            <td><?= Security::h($key['user_name'] ?? 'Unknown') ?></td>
            <td><code class="mono"><?= Security::h($key['key_prefix']) ?>...</code></td>
            <td>
              <?php foreach (json_decode($key['permissions'], true) ?? [] as $p): ?>
                <span class="tag"><?= Security::h($p) ?></span>
              <?php endforeach; ?>
            </td>
            <td><?= $key['expires_at'] ? date('M j, Y', strtotime($key['expires_at'])) : 'Never' ?></td>
            <td class="text-muted text-sm"><?= $key['last_used'] ? date('M j, Y g:ia', strtotime($key['last_used'])) : 'Never' ?></td>
            <td><?= $key['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Revoked</span>' ?></td>
            <td>
              <?php if ($key['is_active']): ?>
                <form method="POST" action="/admin/api-keys/<?= $key['id'] ?>/revoke" data-confirm="Revoke this API key?">
                  <?= Security::csrfField() ?>
                  <button class="btn btn-ghost btn-sm text-danger"><i class="bi bi-slash-circle"></i> Revoke</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="8" class="empty-row"><div class="empty-state-sm"><i class="bi bi-key"></i><p>No API keys. Create one to enable integrations.</p></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create key modal -->
<div class="modal-overlay" id="createKeyModal" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3><i class="bi bi-key-fill"></i> New API Key</h3><button id="closeCreateKeyBtn"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <form method="POST" action="/admin/api-keys/create">
        <?= Security::csrfField() ?>
        <div class="form-group">
          <label class="form-label required">Key Name</label>
          <input type="text" name="name" class="form-control" placeholder="e.g. SIEM Integration" required>
        </div>
        <div class="form-group">
          <label class="form-label">Assign To User</label>
          <select name="user_id" class="form-control">
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['id'] ?>" <?= $u['id']===Auth::id()?'selected':'' ?>><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Permissions</label>
          <div class="checkbox-group">
            <label><input type="checkbox" name="permissions[]" value="read" checked> Read</label>
            <label><input type="checkbox" name="permissions[]" value="write"> Write</label>
            <label><input type="checkbox" name="permissions[]" value="admin"> Admin</label>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Expiry Date (optional)</label>
          <input type="date" name="expires_at" class="form-control">
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i class="bi bi-key-fill"></i> Generate Key</button>
          <button type="button" class="btn btn-ghost" id="cancelCreateKeyBtn">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function showModal(id) { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

document.getElementById('openCreateKeyBtn').addEventListener('click', function() { showModal('createKeyModal'); });
document.getElementById('closeCreateKeyBtn').addEventListener('click', function() { closeModal('createKeyModal'); });
document.getElementById('cancelCreateKeyBtn').addEventListener('click', function() { closeModal('createKeyModal'); });

document.getElementById('createKeyModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal('createKeyModal');
});

var copyKeyBtn = document.getElementById('copyKeyBtn');
if (copyKeyBtn) {
  copyKeyBtn.addEventListener('click', function() {
    navigator.clipboard.writeText(document.getElementById('newKey').textContent.trim());
    copyKeyBtn.innerHTML = '<i class="bi bi-check-lg"></i> Copied!';
  });
}

document.querySelectorAll('form[data-confirm]').forEach(function(f) {
  f.addEventListener('submit', function(e) {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
  });
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
