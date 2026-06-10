<?php
$pageTitle    = 'SAML SSO';
$activeModule = 'admin_saml';
$breadcrumbs  = [['Admin', '/admin'], ['SAML SSO', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">SAML 2.0 Single Sign-On</h1>
    <p class="page-subtitle">Federate authentication with your identity provider (Okta, Entra ID, Google, Shibboleth…).</p></div>
</div>

<div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> Service Provider details (give these to your IdP)</span></div></div>
  <div class="card-body">
    <div class="form-group"><label class="form-label">ACS URL (Assertion Consumer Service)</label><input type="text" class="form-control" value="<?= Security::h($acsUrl) ?>" readonly></div>
    <div class="form-group"><label class="form-label">SP Entity ID</label><input type="text" class="form-control" value="<?= Security::h($spEntityId) ?>" readonly></div>
    <div class="form-group"><label class="form-label">SP Metadata URL</label><input type="text" class="form-control" value="<?= Security::h($metadataUrl) ?>" readonly></div>
    <div class="form-group" style="margin:0"><label class="form-label">SLO endpoint (Single Logout)</label><input type="text" class="form-control" value="<?= Security::h($sloEndpoint) ?>" readonly></div>
  </div>
</div>

<div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-upload"></i> Import IdP metadata</span></div></div>
  <div class="card-body">
    <form method="POST" action="/admin/saml/import">
      <?= Security::csrfField() ?>
      <div class="form-group"><label class="form-label">Paste your IdP's SAML metadata XML</label>
        <textarea name="metadata_xml" class="form-control" rows="5" placeholder="&lt;EntityDescriptor …&gt;…&lt;/EntityDescriptor&gt;" style="font-family:monospace;font-size:.8rem"></textarea>
        <p class="form-hint">Auto-fills the IdP entity ID, Redirect SSO/SLO URLs and the signing certificate below. Review, then Save.</p></div>
      <button class="btn btn-ghost" type="submit"><i class="bi bi-download"></i> Import &amp; pre-fill</button>
    </form>
  </div>
</div>

<form method="POST" action="/admin/saml">
  <?= Security::csrfField() ?>
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-shield-lock"></i> Identity Provider</span></div></div>
    <div class="card-body">
      <label class="form-check" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
        <input type="checkbox" name="saml_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
        <span>Enable SAML SSO (shows a “Sign in with SSO” button on the login page)</span>
      </label>
      <div class="form-group"><label class="form-label">IdP SSO URL (Redirect binding) <span style="color:var(--danger)">*</span></label>
        <input type="url" name="saml_idp_sso_url" class="form-control" value="<?= Security::h($cfg['idp_sso_url']) ?>" placeholder="https://idp.example.com/sso/saml"></div>
      <div class="form-group"><label class="form-label">IdP Entity ID</label>
        <input type="text" name="saml_idp_entity_id" class="form-control" value="<?= Security::h($cfg['idp_entity_id']) ?>" placeholder="https://idp.example.com/metadata"></div>
      <div class="form-group"><label class="form-label">IdP X.509 signing certificate (PEM or base64) <span style="color:var(--danger)">*</span></label>
        <textarea name="saml_idp_cert" class="form-control" rows="6" placeholder="-----BEGIN CERTIFICATE-----&#10;…&#10;-----END CERTIFICATE-----" style="font-family:monospace;font-size:.8rem"><?= Security::h($cfg['idp_cert']) ?></textarea>
        <p class="form-hint">Assertions are verified against this certificate. Required.</p></div>
      <div class="form-group" style="margin:0"><label class="form-label">IdP SLO URL (Single Logout, Redirect binding)</label>
        <input type="url" name="saml_idp_slo_url" class="form-control" value="<?= Security::h($cfg['idp_slo_url']) ?>" placeholder="https://idp.example.com/slo/saml">
        <p class="form-hint">When set, signing out triggers SAML Single Logout at the IdP; inbound IdP-initiated logout is honoured too.</p></div>
    </div>
  </div>

  <div class="card" style="margin-top:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-pen"></i> SP request signing (optional)</span></div></div>
    <div class="card-body">
      <label class="form-check" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
        <input type="checkbox" name="saml_sign_requests" value="1" <?= $cfg['sign_requests'] ? 'checked' : '' ?>>
        <span>Sign AuthnRequest / LogoutRequest (Redirect binding, RSA-SHA256)</span>
      </label>
      <div class="form-group"><label class="form-label">SP X.509 certificate (PEM) — share with the IdP</label>
        <textarea name="saml_sp_cert" class="form-control" rows="5" placeholder="-----BEGIN CERTIFICATE-----…" style="font-family:monospace;font-size:.8rem"><?= Security::h($cfg['sp_cert']) ?></textarea></div>
      <div class="form-group" style="margin:0"><label class="form-label">SP private key (PEM) — stored server-side, never displayed</label>
        <textarea name="saml_sp_key" class="form-control" rows="4" placeholder="<?= $cfg['sp_key'] !== '' ? '•••••• (leave blank to keep current key)' : '-----BEGIN PRIVATE KEY-----…' ?>" style="font-family:monospace;font-size:.8rem"></textarea>
        <p class="form-hint">Leave blank to keep the existing key.<?= $cfg['sp_key'] !== '' ? ' A key is currently configured.' : '' ?></p></div>
    </div>
  </div>

  <div class="card" style="margin-top:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-person-gear"></i> Attribute mapping &amp; provisioning</span></div></div>
    <div class="card-body">
      <div class="form-row" style="gap:14px">
        <div class="form-group" style="flex:1"><label class="form-label">Email attribute</label>
          <input type="text" name="saml_attr_email" class="form-control" value="<?= Security::h($cfg['attr_email']) ?>" placeholder="email (falls back to NameID)"></div>
        <div class="form-group" style="flex:1"><label class="form-label">Display-name attribute</label>
          <input type="text" name="saml_attr_name" class="form-control" value="<?= Security::h($cfg['attr_name']) ?>" placeholder="displayName"></div>
      </div>
      <div class="form-group"><label class="form-label">SP Entity ID (override)</label>
        <input type="text" name="saml_sp_entity_id" class="form-control" value="<?= Security::h($cfg['sp_entity_id']) ?>" placeholder="<?= Security::h($spEntityId) ?>"></div>
      <label class="form-check" style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
        <input type="checkbox" name="saml_auto_provision" value="1" <?= $cfg['auto_provision'] ? 'checked' : '' ?>>
        <span>Just-in-time provisioning — create a local account on first SSO login</span>
      </label>
      <div class="form-group" style="margin:0;max-width:240px"><label class="form-label">Default role for new accounts</label>
        <select name="saml_default_role" class="form-select">
          <?php foreach (['viewer'=>'Viewer','contributor'=>'Contributor','approver'=>'Approver','admin'=>'Admin'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($cfg['default_role']===$k)?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save SAML settings</button>
  </div>
</form>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
