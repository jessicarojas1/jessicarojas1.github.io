<?php
$cfg = $cfg ?? SSO::config();
$roles = ['admin', 'manager', 'auditor', 'analyst', 'viewer'];
$breadcrumbs = [['Admin', '/admin'], ['SSO Configuration', null]];
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

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:flex-start">

  <form method="POST" action="/admin/settings/sso/save" class="card">
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

  <!-- Setup Guide -->
  <div class="card" style="font-size:0.875rem">
    <div class="card-body">

      <h3 class="section-title" style="margin-top:0"><i class="bi bi-book"></i> Setup Guide</h3>

      <!-- Quick Setup Steps -->
      <h4 style="font-size:0.8125rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 10px">Quick Setup Steps</h4>
      <ol style="margin:0 0 20px;padding-left:1.25rem;line-height:1.7;color:var(--text-muted)">
        <li>Register a new OAuth 2.0 / OIDC application in your identity provider.</li>
        <li>Add the <strong style="color:var(--text)">Callback URI</strong> (shown below) as an allowed redirect URI.</li>
        <li>Copy the <strong style="color:var(--text)">Discovery URL</strong>, <strong style="color:var(--text)">Client ID</strong>, and <strong style="color:var(--text)">Client Secret</strong> into the form.</li>
        <li>Configure role/group claims in your IdP and map them in the <em>Role Mapping</em> section.</li>
        <li>Enable SSO, save settings, and use <em>Test SSO Login</em> to verify the flow end-to-end.</li>
      </ol>

      <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

      <!-- Provider Examples -->
      <h4 style="font-size:0.8125rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 10px">Provider Discovery URLs</h4>
      <p style="color:var(--text-muted);margin:0 0 8px">Click a URL to copy it into the Discovery URL field.</p>

      <?php
      $providers = [
        'Azure AD'  => 'https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration',
        'Okta'      => 'https://your-org.okta.com/.well-known/openid-configuration',
        'Google'    => 'https://accounts.google.com/.well-known/openid-configuration',
        'Keycloak'  => 'https://your-keycloak/realms/{realm}/.well-known/openid-configuration',
      ];
      ?>
      <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:20px">
        <?php foreach ($providers as $name => $url): ?>
          <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:6px;padding:8px 10px">
            <div style="font-weight:600;color:var(--text);margin-bottom:3px"><?= Security::h($name) ?></div>
            <button type="button"
                    style="background:none;border:none;padding:0;cursor:pointer;text-align:left;width:100%;word-break:break-all;font-family:monospace;font-size:0.75rem;color:var(--text-muted)"
                    data-copy-to="sso_discovery_url"
                    data-copy-value="<?= Security::h($url) ?>"
                    title="Click to copy into Discovery URL field"><?= Security::h($url) ?></button>
          </div>
        <?php endforeach; ?>
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

      <!-- Role Mapping Examples -->
      <h4 style="font-size:0.8125rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 10px">Role Mapping Examples</h4>
      <p style="color:var(--text-muted);margin:0 0 8px">The JSON object maps IdP group/role names (left) to AEGIS roles (right).</p>
      <pre style="background:var(--card-bg);border:1px solid var(--border);border-radius:6px;padding:10px;font-size:0.75rem;color:var(--text-muted);overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:0 0 20px">{
  "GRC-Admins":    "admin",
  "GRC-Managers":  "manager",
  "GRC-Auditors":  "auditor",
  "GRC-Analysts":  "analyst",
  "GRC-Viewers":   "viewer"
}</pre>

      <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

      <!-- Field Definitions -->
      <h4 style="font-size:0.8125rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 10px">Field Definitions</h4>
      <table style="width:100%;border-collapse:collapse;font-size:0.8125rem">
        <tbody>
          <?php
          $defs = [
            ['Discovery URL',        'The OIDC metadata endpoint. AEGIS fetches token, authorization, and JWKS endpoints from this URL automatically.'],
            ['Client ID',            'The unique identifier for your AEGIS application registration in the IdP.'],
            ['Client Secret',        'The confidential secret issued by the IdP. Stored encrypted at rest.'],
            ['Provider Display Name','Text shown on the SSO login button (e.g. "Sign in with Azure AD").'],
            ['Default Role',         'AEGIS role assigned to new SSO users when no role mapping matches.'],
            ['Auto-Provision',       'When on, AEGIS creates a local account on the first successful SSO login. When off, accounts must be pre-created.'],
            ['Role Claim Name',      'The JWT claim in the ID token that carries group/role values (commonly <code>roles</code> or <code>groups</code>).'],
            ['Role Mapping (JSON)',  'Maps IdP role/group names to AEGIS roles. Unmatched users receive the Default Role.'],
          ];
          foreach ($defs as [$field, $desc]):
          ?>
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:7px 8px 7px 0;font-weight:600;color:var(--text);white-space:nowrap;vertical-align:top"><?= $field ?></td>
            <td style="padding:7px 0;color:var(--text-muted);vertical-align:top"><?= $desc ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">

      <!-- Info box -->
      <div style="background:var(--card-bg);border:1px solid var(--border);border-left:3px solid var(--info, #3b82f6);border-radius:6px;padding:12px 14px;color:var(--text-muted);font-size:0.8125rem;line-height:1.6">
        <div style="font-weight:600;color:var(--text);margin-bottom:6px"><i class="bi bi-info-circle"></i> Important</div>
        <p style="margin:0 0 8px">
          Register the following <strong style="color:var(--text)">Callback / Redirect URI</strong> in your IdP:<br>
          <code style="word-break:break-all"><?= Security::h(rtrim($_ENV['APP_URL'] ?? '', '/') . '/sso/callback') ?></code>
        </p>
        <p style="margin:0">
          <i class="bi bi-shield-lock"></i> SSO requires <strong style="color:var(--text)">HTTPS</strong> in production. Most IdPs reject redirect URIs served over plain HTTP.
        </p>
      </div>

    </div>
  </div>

</div>

<script nonce="<?= Security::nonce() ?>">
(function () {
  document.querySelectorAll('[data-copy-to]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-copy-to');
      var value    = btn.getAttribute('data-copy-value');
      var field    = document.getElementById(targetId);
      if (field) {
        field.value = value;
        field.focus();
        field.select();
      }
    });
  });
}());
</script>

<?php
