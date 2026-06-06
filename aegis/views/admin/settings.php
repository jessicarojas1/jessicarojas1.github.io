<?php
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$breadcrumbs = [['Admin', '/admin'], ['Settings', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">System Settings</h1>
    <p class="page-subtitle">Configure global AEGIS GRC settings</p>
  </div>
  <div class="page-actions">
    <a href="/admin" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Admin</a>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;max-width:1100px;align-items:start">
  <div style="display:flex;flex-direction:column;gap:20px">

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-gear-fill" style="color:var(--primary)"></i><span class="card-title">General Settings</span></div></div>
      <div class="card-body">
        <form method="post" action="/admin/settings/save">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

          <div class="form-group">
            <label class="form-label" for="org_name">Organization Name</label>
            <input type="text" id="org_name" name="org_name" class="form-control" value="<?= Security::h($settings['org_name']['value'] ?? '') ?>" placeholder="Your Organization">
          </div>

          <div class="form-row">
            <div class="form-group" style="flex:1">
              <label class="form-label" for="timezone">Timezone</label>
              <select id="timezone" name="timezone" class="form-control">
                <?php
                $tzList = ['UTC','America/New_York','America/Chicago','America/Denver','America/Los_Angeles',
                           'America/Toronto','America/Vancouver','Europe/London','Europe/Paris','Europe/Berlin',
                           'Europe/Amsterdam','Asia/Tokyo','Asia/Singapore','Asia/Hong_Kong','Australia/Sydney'];
                $curTz  = $settings['timezone']['value'] ?? 'UTC';
                foreach ($tzList as $tz): ?>
                  <option value="<?= $tz ?>" <?= $curTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label" for="date_format">Date Format</label>
              <select id="date_format" name="date_format" class="form-control">
                <?php $curFmt = $settings['date_format']['value'] ?? 'Y-m-d';
                $formats = ['Y-m-d'=>date('Y-m-d').' (ISO)','m/d/Y'=>date('m/d/Y').' (US)','d/m/Y'=>date('d/m/Y').' (EU)','M j, Y'=>date('M j, Y').' (Long)'];
                foreach ($formats as $fmt=>$label): ?>
                  <option value="<?= $fmt ?>" <?= $curFmt === $fmt ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="session_timeout">Session Timeout (minutes)</label>
            <input type="number" id="session_timeout" name="session_timeout" class="form-control" style="max-width:180px"
                   value="<?= Security::h($settings['session_timeout']['value'] ?? '60') ?>" min="5" max="480">
            <span class="form-text">Users will be logged out after this many minutes of inactivity.</span>
          </div>

          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Settings</button>
        </form>
      </div>
    </div>

    <!-- Branding -->
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-image" style="color:var(--primary)"></i><span class="card-title">Branding</span></div></div>
      <div class="card-body">
        <?php
        $logoData = $settings['company_logo_data']['value'] ?? '';
        $logoName = $settings['company_logo_name']['value'] ?? '';
        ?>
        <?php if ($logoData): ?>
        <div style="margin-bottom:16px;">
          <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:8px;">Current Logo</div>
          <img src="<?= htmlspecialchars($logoData, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
               alt="Company Logo"
               style="max-height:80px;max-width:240px;border:1px solid var(--border);border-radius:6px;padding:8px;background:var(--card-bg);">
          <?php if ($logoName): ?>
          <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;"><?= Security::h($logoName) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="post" action="/admin/settings/upload-logo" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <div class="form-group">
            <label class="form-label" for="logo_file">Upload Company Logo</label>
            <input type="file" id="logo_file" name="logo_file" class="form-control"
                   accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
            <span class="form-text">Accepted formats: JPG, PNG, GIF, WEBP, SVG &nbsp;·&nbsp; Max size: 2 MB</span>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Upload Logo</button>
        </form>

        <?php if ($logoData): ?>
        <form method="post" action="/admin/settings/remove-logo" style="margin-top:12px;">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <button type="submit" class="btn btn-danger btn-sm"
                  data-confirm-click="Remove the current company logo?">
            <i class="bi bi-trash"></i> Remove Logo
          </button>
        </form>
        <?php endif; ?>

        <!-- Logo Upload — Field Reference -->
        <div style="margin-top:16px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:6px;padding:10px;font-size:0.78rem;">
          <div style="font-weight:600;color:var(--text);margin-bottom:4px;"><i class="bi bi-info-circle" style="color:var(--primary)"></i> Logo Upload — Field Reference</div>
          <table style="width:100%;border-collapse:collapse;font-size:0.75rem;">
            <thead><tr><th style="text-align:left;padding:3px 6px;color:var(--text-muted);">Field</th><th style="text-align:left;padding:3px 6px;color:var(--text-muted);">Type</th><th style="text-align:left;padding:3px 6px;color:var(--text-muted);">Required</th></tr></thead>
            <tbody>
              <tr><td style="padding:2px 6px;font-family:monospace;">logo_file</td><td style="padding:2px 6px;">file (image/jpeg, image/png, image/gif, image/webp, image/svg+xml)</td><td style="padding:2px 6px;">Yes</td></tr>
              <tr><td style="padding:2px 6px;font-family:monospace;">csrf_token</td><td style="padding:2px 6px;">string (CSRF token)</td><td style="padding:2px 6px;">Yes</td></tr>
            </tbody>
          </table>
          <div style="margin-top:4px;color:var(--text-muted);">Stored as: base64 data URI in <code>company_logo_data</code> setting; original filename in <code>company_logo_name</code>.</div>
        </div>
      </div>
    </div>

  </div>

  <div style="display:flex;flex-direction:column;gap:20px">

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-stopwatch" style="color:var(--warning)"></i><span class="card-title">Incident SLA Policy</span></div></div>
      <div class="card-body" style="font-size:13px">
        <p style="margin:0 0 12px;color:var(--text-muted)">Configure time-to-acknowledge and time-to-resolve targets per incident severity.</p>
        <a href="/admin/sla-policy" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-right-circle"></i> Manage SLA Policy</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-info-circle" style="color:var(--primary)"></i><span class="card-title">System Info</span></div></div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <?php $infoKeys = ['version'=>'Version','installed_at'=>'Installed','org_name'=>'Organization']; ?>
          <?php foreach ($infoKeys as $key => $label): ?>
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:8px 0;color:var(--text-muted);width:110px"><?= $label ?></td>
            <td style="padding:8px 0">
              <?php $val = $settings[$key]['value'] ?? '—';
              if ($key === 'installed_at' && $val !== '—') {
                echo date('M j, Y', strtotime($val));
              } else {
                echo Security::h($val);
              } ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-list-ul" style="color:var(--text-muted)"></i><span class="card-title">All Settings</span></div></div>
      <div class="card-body" style="padding:0;max-height:320px;overflow-y:auto">
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <?php foreach ($settings as $key => $row): ?>
          <tr style="border-top:1px solid var(--border)">
            <td style="padding:7px 14px;font-family:monospace;color:var(--text-muted)"><?= Security::h($key) ?></td>
            <td style="padding:7px 14px;word-break:break-all">
              <?php
              $v = $row['value'] ?? '';
              if (str_contains(strtolower($key), 'pass') || str_contains(strtolower($key), 'secret')) {
                echo $v ? '••••••••' : '—';
              } else {
                echo Security::h($v ?: '—');
              }
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

  </div>
</div>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
