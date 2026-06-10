<?php
$pageTitle    = 'SCIM Provisioning';
$activeModule = 'admin_scim';
$breadcrumbs  = [['Admin', '/admin'], ['SCIM Provisioning', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">SCIM 2.0 User Provisioning</h1>
    <p class="page-subtitle">Let your IdP provision, update and deprovision PALADIN accounts (RFC 7643/7644).</p></div>
</div>

<div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> Endpoint detail (give these to your IdP)</span></div></div>
  <div class="card-body">
    <div class="form-group"><label class="form-label">SCIM Base URL</label><input type="text" class="form-control" value="<?= Security::h($baseUrl) ?>/scim/v2" readonly></div>
    <?php if ($newToken): ?>
    <div class="banner ok" style="margin:0"><i class="bi bi-key-fill"></i><div class="banner-body"><strong>New bearer token (copy now — shown only once):</strong><br><code style="word-break:break-all"><?= Security::h($newToken) ?></code></div></div>
    <?php else: ?>
    <div class="form-group" style="margin:0"><label class="form-label">Bearer token</label><input type="text" class="form-control" value="<?= $hasToken ? '•••••• (configured — regenerate to reveal a new one)' : 'not generated yet' ?>" readonly></div>
    <?php endif; ?>
  </div>
</div>

<form method="POST" action="/admin/scim">
  <?= Security::csrfField() ?>
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-people"></i> Configuration</span></div></div>
    <div class="card-body">
      <label class="form-check" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
        <input type="checkbox" name="scim_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <span>Enable SCIM provisioning</span>
      </label>
      <div class="form-group" style="max-width:240px"><label class="form-label">Default role for provisioned users</label>
        <select name="scim_default_role" class="form-select">
          <?php foreach (['viewer'=>'Viewer','contributor'=>'Contributor','approver'=>'Approver','admin'=>'Admin'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($defaultRole===$k)?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <label class="form-check" style="display:flex;align-items:center;gap:8px;margin:0">
        <input type="checkbox" name="regenerate_token" value="1">
        <span><?= $hasToken ? 'Rotate the bearer token (invalidates the current one)' : 'Generate a bearer token' ?></span>
      </label>
    </div>
  </div>
  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save SCIM settings</button>
  </div>
</form>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
