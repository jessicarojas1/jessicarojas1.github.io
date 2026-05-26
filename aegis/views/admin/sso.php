<?php
$cfg = $cfg ?? SSO::config();
$roles = ['admin', 'manager', 'auditor', 'analyst', 'viewer'];
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">SSO / OIDC Settings</h1>
    <p class="page-subtitle">Configure single sign-on via any OpenID Connect-compatible identity provider (Azure AD, Okta, Google Workspace, Keycloak, Auth0).</p>
  </div>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<form method="POST" action="/admin/settings/sso/save" class="card" style="max-width:760px">
  <?= Security::csrfField() ?>

  <div class="card-body">

    <div class="form-group">
      <label class="form-label">SSO Enabled</label>
      <label class="toggle-switch">
        <input type="hidden" name="sso_enabled" value="0">
        <input type="checkbox" name="sso_enabled" value="1" <?= ($cfg['sso_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
        <span class="toggle-slider"></span>
      </label>
      <p class="form-hint">When enabled, an SSO button appears on the login page. Password login remains available unless you disable it per-user.</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="sso_provider_name">Provider Display Name</label>
      <input type="text" id="sso_provider_name" name="sso_provider_name" class="form-control"
             value="<?= Security::h($cfg['sso_provider_name'] ?? '') ?>"
             placeholder="e.g. Azure AD, Okta, Google Workspace">
    </div>

    <hr class="section-divider">
    <h3 class="section-title">OIDC Credentials</h3>

    <div class="form-group">
      <label class="form-label" for="sso_discovery_url">Discovery URL <span class="badge badge-red">Required</span></label>
      <input type="url" id="sso_discovery_url" name="sso_discovery_url" class="form-control"
             value="<?= Security::h($cfg['sso_discovery_url'] ?? '') ?>"
             placeholder="https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration">
      <p class="form-hint">The <code>.well-known/openid-configuration</code> endpoint for your IdP.</p>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="sso_client_id">Client ID <span class="badge badge-red">Required</span></label>
        <input type="text" id="sso_client_id" name="sso_client_id" class="form-control"
               value="<?= Security::h($cfg['sso_client_id'] ?? '') ?>" autocomplete="off">
      </div>
      <div class="form-group">
        <label class="form-label" for="sso_client_secret">Client Secret <span class="badge badge-red">Required</span></label>
        <input type="password" id="sso_client_secret" name="sso_client_secret" class="form-control"
               value="<?= Security::h($cfg['sso_client_secret'] ?? '') ?>" autocomplete="new-password">
      </div>
    </div>

    <p class="form-hint" style="margin-bottom:16px">
      Callback / Redirect URI to register with your IdP:
      <code><?= Security::h(rtrim($_ENV['APP_URL'] ?? '', '/')) ?>/sso/callback</code>
    </p>

    <hr class="section-divider">
    <h3 class="section-title">User Provisioning</h3>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="sso_default_role">Default Role for New Users</label>
        <select id="sso_default_role" name="sso_default_role" class="form-control">
          <?php foreach ($roles as $r): ?>
            <option value="<?= $r ?>" <?= ($cfg['sso_default_role'] ?? 'viewer') === $r ? 'selected' : '' ?>>
              <?= ucfirst($r) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Auto-Provision Users</label>
        <label class="toggle-switch">
          <input type="hidden" name="sso_auto_provision" value="0">
          <input type="checkbox" name="sso_auto_provision" value="1" <?= ($cfg['sso_auto_provision'] ?? '') === '1' ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
        </label>
        <p class="form-hint">Create AEGIS accounts automatically on first SSO login.</p>
      </div>
    </div>

    <hr class="section-divider">
    <h3 class="section-title">Role Mapping <span class="badge badge-gray">Optional</span></h3>

    <div class="form-group">
      <label class="form-label" for="sso_role_claim">IdP Role Claim Name</label>
      <input type="text" id="sso_role_claim" name="sso_role_claim" class="form-control"
             value="<?= Security::h($cfg['sso_role_claim'] ?? '') ?>"
             placeholder="roles">
      <p class="form-hint">The claim in the ID token that contains the user's group/role list (e.g. <code>roles</code>, <code>groups</code>).</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="sso_role_mapping">Role Mapping (JSON)</label>
      <textarea id="sso_role_mapping" name="sso_role_mapping" class="form-control" rows="5"
                placeholder='{"GRC-Admin":"admin","GRC-Manager":"manager","GRC-Auditor":"auditor"}'><?= Security::h($cfg['sso_role_mapping'] ?? '{}') ?></textarea>
      <p class="form-hint">Maps IdP role/group names → AEGIS roles. Left side is the IdP value; right side is admin/manager/auditor/analyst/viewer.</p>
    </div>

  </div>

  <div class="card-footer">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save SSO Settings</button>
    <?php if (SSO::isEnabled()): ?>
      <a href="/sso/login" target="_blank" class="btn btn-secondary" style="margin-left:8px">
        <i class="bi bi-box-arrow-up-right"></i> Test SSO Login
      </a>
    <?php endif; ?>
  </div>
</form>

<?php
$content = ob_get_clean();
