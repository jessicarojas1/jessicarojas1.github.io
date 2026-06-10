<?php
$pageTitle    = 'OIDC SSO';
$activeModule = 'admin_oidc';
$breadcrumbs  = [['Admin', '/admin'], ['OIDC SSO', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">OpenID Connect Single Sign-On</h1>
    <p class="page-subtitle">Federate login with any OIDC provider (Okta, Entra ID, Google, Auth0, Keycloak…) via the Authorization Code flow with PKCE.</p></div>
</div>

<div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> Relying Party detail (register this with your provider)</span></div></div>
  <div class="card-body">
    <div class="form-group" style="margin:0"><label class="form-label">Redirect URI (callback)</label><input type="text" class="form-control" value="<?= Security::h($redirectUri) ?>" readonly></div>
  </div>
</div>

<form method="POST" action="/admin/oidc">
  <?= Security::csrfField() ?>
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-shield-lock"></i> Provider</span></div></div>
    <div class="card-body">
      <label class="form-check" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
        <input type="checkbox" name="oidc_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
        <span>Enable OIDC SSO (shows a “Sign in with OpenID” button on the login page)</span>
      </label>
      <div class="form-group"><label class="form-label">Issuer URL <span class="form-hint">(discovery: &lt;issuer&gt;/.well-known/openid-configuration)</span></label>
        <input type="url" name="oidc_issuer" class="form-control" value="<?= Security::h($cfg['issuer']) ?>" placeholder="https://accounts.example.com"></div>
      <div class="form-row" style="gap:14px">
        <div class="form-group" style="flex:1"><label class="form-label">Client ID <span style="color:var(--danger)">*</span></label>
          <input type="text" name="oidc_client_id" class="form-control" value="<?= Security::h($cfg['client_id']) ?>"></div>
        <div class="form-group" style="flex:1"><label class="form-label">Client secret</label>
          <input type="password" name="oidc_client_secret" class="form-control" placeholder="<?= $cfg['client_secret'] !== '' ? '•••••• (leave blank to keep)' : 'client secret' ?>" autocomplete="off"></div>
      </div>
      <div class="form-group" style="margin:0"><label class="form-label">Scopes</label>
        <input type="text" name="oidc_scopes" class="form-control" value="<?= Security::h($cfg['scopes']) ?>" placeholder="openid email profile"></div>
    </div>
  </div>

  <div class="card" style="margin-top:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-link-45deg"></i> Explicit endpoints (optional — override discovery)</span></div></div>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Authorization endpoint</label><input type="url" name="oidc_authorize_url" class="form-control" value="<?= Security::h($cfg['authorize_url']) ?>"></div>
      <div class="form-group"><label class="form-label">Token endpoint</label><input type="url" name="oidc_token_url" class="form-control" value="<?= Security::h($cfg['token_url']) ?>"></div>
      <div class="form-group" style="margin:0"><label class="form-label">JWKS URI</label><input type="url" name="oidc_jwks_url" class="form-control" value="<?= Security::h($cfg['jwks_url']) ?>"></div>
    </div>
  </div>

  <div class="card" style="margin-top:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-person-gear"></i> Claims &amp; provisioning</span></div></div>
    <div class="card-body">
      <div class="form-row" style="gap:14px">
        <div class="form-group" style="flex:1"><label class="form-label">Email claim</label><input type="text" name="oidc_attr_email" class="form-control" value="<?= Security::h($cfg['attr_email']) ?>" placeholder="email"></div>
        <div class="form-group" style="flex:1"><label class="form-label">Name claim</label><input type="text" name="oidc_attr_name" class="form-control" value="<?= Security::h($cfg['attr_name']) ?>" placeholder="name"></div>
      </div>
      <label class="form-check" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
        <input type="checkbox" name="oidc_auto_provision" value="1" <?= $cfg['auto_provision'] ? 'checked' : '' ?>>
        <span>Just-in-time provisioning — create a local account on first OIDC login</span>
      </label>
      <div class="form-group" style="margin:0;max-width:240px"><label class="form-label">Default role for new accounts</label>
        <select name="oidc_default_role" class="form-select">
          <?php foreach (['viewer'=>'Viewer','contributor'=>'Contributor','approver'=>'Approver','admin'=>'Admin'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($cfg['default_role']===$k)?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save OIDC settings</button>
  </div>
</form>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
