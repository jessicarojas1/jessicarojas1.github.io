<?php
$pageTitle    = 'Settings';
$activeModule = 'admin_settings';
$breadcrumbs  = [['Administration', '/admin'], ['Settings', null]];
ob_start();
$g = fn(string $k, string $def = '') => Security::h($settings[$k] ?? $def);
$on = fn(string $k, string $def = '0') => (($settings[$k] ?? $def) === '1') ? 'checked' : '';
?>
<div class="page-header">
  <div><h1 class="page-title">Settings</h1><p class="page-subtitle">Application, security, email and storage configuration</p></div>
</div>

<form method="POST" action="/admin/settings">
  <?= Security::csrfField() ?>

  <!-- General -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-sliders"></i> General</span></div></div>
    <div class="card-body">
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="date_format">Date Format</label><input type="text" id="date_format" name="date_format" class="form-control" value="<?= $g('date_format', 'Y-m-d') ?>"><div class="form-hint">PHP date() format, e.g. <code>Y-m-d</code> or <code>M j, Y</code>.</div></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="timezone">Timezone</label><input type="text" id="timezone" name="timezone" class="form-control" value="<?= $g('timezone', 'UTC') ?>"></div>
      </div>
    </div>
  </div>

  <!-- Uploads -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-cloud-arrow-up-fill"></i> Uploads</span></div></div>
    <div class="card-body">
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="upload_max_size_mb">Max Upload Size (MB)</label><input type="number" min="1" id="upload_max_size_mb" name="upload_max_size_mb" class="form-control" value="<?= $g('upload_max_size_mb', '25') ?>"></div>
        <div class="form-group" style="flex:2"><label class="form-label" for="upload_allowed_types">Allowed File Types</label><input type="text" id="upload_allowed_types" name="upload_allowed_types" class="form-control" value="<?= $g('upload_allowed_types') ?>"><div class="form-hint"><strong>Field reference:</strong> comma-separated extensions, e.g. <code>pdf,docx,png,jpg,zip</code> (no dots).</div></div>
      </div>
    </div>
  </div>

  <!-- Password Policy -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-shield-lock-fill"></i> Password Policy</span></div></div>
    <div class="card-body">
      <div class="form-group" style="max-width:240px"><label class="form-label" for="password_min_length">Minimum Length</label><input type="number" min="6" id="password_min_length" name="password_min_length" class="form-control" value="<?= $g('password_min_length', '12') ?>"></div>
      <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px"><input type="checkbox" name="password_require_uppercase" value="1" <?= $on('password_require_uppercase', '1') ?>> Require an uppercase letter</label>
      <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px"><input type="checkbox" name="password_require_numbers" value="1" <?= $on('password_require_numbers', '1') ?>> Require a number</label>
      <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="password_require_special" value="1" <?= $on('password_require_special', '1') ?>> Require a special character</label>
      <hr style="border:none;border-top:1px solid var(--border-light);margin:14px 0">
      <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:8px"><input type="checkbox" name="require_esignature" value="1" <?= $on('require_esignature') ?>> <span><i class="bi bi-pen"></i> Require e-signature (password re-authentication) on workflow transitions</span></label>
      <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="auto_archive_on_expiry" value="1" <?= $on('auto_archive_on_expiry') ?>> <span><i class="bi bi-calendar-x"></i> Auto-archive controlled documents when past their expiration date</span></label>
      <hr style="border:none;border-top:1px solid var(--border-light);margin:14px 0">
      <div class="form-group" style="max-width:320px;margin:0"><label class="form-label" for="mfa_required"><i class="bi bi-shield-lock"></i> Require two-factor authentication</label>
        <select id="mfa_required" name="mfa_required" class="form-select">
          <?php $mfaCur = $g('mfa_required', 'off'); foreach (['off' => 'Optional (user choice)', 'admins' => 'Required for admins', 'all' => 'Required for all users'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $mfaCur === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <p class="form-hint">When required, affected users are sent to 2FA setup after login until they enrol.</p>
      </div>
    </div>
  </div>

  <!-- Email -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-envelope-fill"></i> Email</span></div></div>
    <div class="card-body">
      <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:14px"><input type="checkbox" name="email_notifications" value="1" <?= $on('email_notifications') ?>> Enable outbound email notifications</label>
      <div class="form-row">
        <div class="form-group" style="flex:2"><label class="form-label" for="smtp_host">SMTP Host</label><input type="text" id="smtp_host" name="smtp_host" class="form-control" value="<?= $g('smtp_host') ?>"></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="smtp_port">Port</label><input type="number" id="smtp_port" name="smtp_port" class="form-control" value="<?= $g('smtp_port', '587') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="smtp_user">SMTP Username</label><input type="text" id="smtp_user" name="smtp_user" class="form-control" value="<?= $g('smtp_user') ?>"></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="smtp_pass">SMTP Password</label><input type="password" id="smtp_pass" name="smtp_pass" class="form-control" autocomplete="new-password" placeholder="<?= ($settings['smtp_pass'] ?? '') !== '' ? 'unchanged' : '' ?>"><div class="form-hint">Leave blank to keep the existing password.</div></div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="smtp_from">From Address</label><input type="email" id="smtp_from" name="smtp_from" class="form-control" value="<?= $g('smtp_from') ?>"></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="smtp_from_name">From Name</label><input type="text" id="smtp_from_name" name="smtp_from_name" class="form-control" value="<?= $g('smtp_from_name') ?>"></div>
      </div>
    </div>
  </div>

  <!-- Storage -->
  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-hdd-fill"></i> Storage</span></div></div>
    <div class="card-body">
      <div class="form-group" style="max-width:280px">
        <label class="form-label" for="storage_driver">Storage Driver</label>
        <select id="storage_driver" name="storage_driver" class="form-select">
          <?php $sd = $settings['storage_driver'] ?? 'local'; ?>
          <option value="local" <?= $sd==='local'?'selected':'' ?>>Local Disk</option>
          <option value="s3" <?= $sd==='s3'?'selected':'' ?>>S3 / Compatible</option>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="s3_bucket">S3 Bucket</label><input type="text" id="s3_bucket" name="s3_bucket" class="form-control" value="<?= $g('s3_bucket') ?>"></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="s3_region">Region</label><input type="text" id="s3_region" name="s3_region" class="form-control" value="<?= $g('s3_region') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="s3_access_key">Access Key</label><input type="text" id="s3_access_key" name="s3_access_key" class="form-control" value="<?= $g('s3_access_key') ?>"></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="s3_secret_key">Secret Key</label><input type="password" id="s3_secret_key" name="s3_secret_key" class="form-control" autocomplete="new-password" placeholder="<?= ($settings['s3_secret_key'] ?? '') !== '' ? 'unchanged' : '' ?>"><div class="form-hint">Leave blank to keep the existing secret.</div></div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:1"><label class="form-label" for="s3_endpoint">Endpoint</label><input type="text" id="s3_endpoint" name="s3_endpoint" class="form-control" value="<?= $g('s3_endpoint') ?>"></div>
        <div class="form-group" style="flex:1"><label class="form-label" for="s3_public_url">Public URL</label><input type="text" id="s3_public_url" name="s3_public_url" class="form-control" value="<?= $g('s3_public_url') ?>"></div>
      </div>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
  </div>
</form>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
