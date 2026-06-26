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

          <p style="margin:0 0 14px;color:var(--text-muted);font-size:13px">
            The organization display name and logo are managed in the
            <a href="#branding" class="table-link">Branding</a> section below.
          </p>

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
    <div class="card" id="branding">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-palette-fill" style="color:var(--primary)"></i><span class="card-title">Branding</span></div></div>
      <div class="card-body">
        <?php
        $logoData   = $settings['company_logo_data']['value'] ?? '';
        $logoName   = $settings['company_logo_name']['value'] ?? '';
        $brandName  = ($settings['org_name']['value'] ?? '') !== '' ? $settings['org_name']['value'] : Branding::DEFAULT_NAME;
        $brandAccent = Branding::sanitizeColor($settings['brand_accent']['value'] ?? '') ?: Branding::DEFAULT_ACCENT;
        $brandLogo   = Branding::sanitizeLogo($logoData);
        ?>

        <p style="margin:0 0 16px;color:var(--text-muted);font-size:13px">
          Customize the display name, logo and accent colour shown in the sidebar, login screen,
          document title and printed reports. Leave a field blank to use the AEGIS default.
        </p>

        <!-- Live preview -->
        <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);margin-bottom:16px">
          <img id="brandPreviewLogo"
               src="<?= $brandLogo ? Security::h($brandLogo) : '' ?>"
               alt="Logo preview" data-logo-fallback
               style="width:40px;height:40px;object-fit:contain;border-radius:8px;<?= $brandLogo ? '' : 'display:none' ?>">
          <div id="brandPreviewLogoIcon" class="brand-logo-fallback"
               style="width:40px;height:40px;border-radius:8px;display:<?= $brandLogo ? 'none' : 'flex' ?>;align-items:center;justify-content:center;background:var(--primary);color:#fff;font-size:20px">
            <i class="bi bi-shield-fill-check"></i>
          </div>
          <div>
            <div id="brandPreviewName" style="font-weight:700;font-size:15px;color:var(--text)"><?= Security::h($brandName) ?></div>
            <div style="font-size:11px;color:var(--text-muted)">Live preview</div>
          </div>
          <span id="brandAccentSwatch" style="margin-left:auto;width:28px;height:28px;border-radius:6px;border:1px solid var(--border);background:<?= Security::h($brandAccent) ?>"></span>
        </div>

        <form method="post" action="/admin/settings/branding/save">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

          <div class="form-group">
            <label class="form-label" for="org_name">Display Name</label>
            <input type="text" id="org_name" name="org_name" class="form-control"
                   value="<?= Security::h($settings['org_name']['value'] ?? '') ?>" placeholder="<?= Security::h(Branding::DEFAULT_NAME) ?>" maxlength="120">
            <span class="form-text">Replaces the app name in the sidebar, browser tab title and report headers.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="brand_accent">Accent Colour</label>
            <div style="display:flex;align-items:center;gap:10px">
              <input type="color" id="brand_accent" name="brand_accent" class="form-control"
                     value="<?= Security::h($brandAccent) ?>" style="max-width:64px;height:38px;padding:4px">
              <input type="text" id="brand_accent_text" class="form-control"
                     value="<?= Security::h($brandAccent) ?>" placeholder="#16a34a" style="max-width:140px;font-family:monospace">
            </div>
            <span class="form-text">Overrides the primary accent (buttons, links, highlights) across the app.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="logo_url">Logo URL</label>
            <input type="text" id="logo_url" name="logo_url" class="form-control"
                   placeholder="https://example.com/logo.png"
                   value="<?= (str_starts_with($brandLogo, 'http')) ? Security::h($brandLogo) : '' ?>">
            <span class="form-text">Paste an image link (<code>http(s)://</code>) or a <code>data:image/...</code> URI. Saving this overrides the uploaded logo.</span>
          </div>

          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Branding</button>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:18px 0">

        <?php if ($brandLogo): ?>
        <div style="margin-bottom:16px;">
          <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:8px;">Current Logo</div>
          <img src="<?= Security::h($brandLogo) ?>"
               alt="Company Logo" data-logo-fallback
               style="max-height:80px;max-width:240px;border:1px solid var(--border);border-radius:6px;padding:8px;background:var(--card-bg);">
          <div class="brand-logo-fallback" style="display:none;font-size:0.78rem;color:var(--text-muted)">Logo source could not be loaded.</div>
          <?php if ($logoName): ?>
          <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;"><?= Security::h($logoName) ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <form method="post" action="/admin/settings/upload-logo" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <div class="form-group">
            <label class="form-label" for="logo_file">Upload Logo (stored offline as a data: URI)</label>
            <input type="file" id="logo_file" name="logo_file" class="form-control"
                   accept=".jpg,.jpeg,.png,.gif,.webp">
            <span class="form-text">Accepted formats: JPG, PNG, GIF, WEBP &nbsp;·&nbsp; Max size: 2 MB. Stored in the database so it works offline.</span>
          </div>
          <button type="submit" class="btn btn-secondary"><i class="bi bi-upload"></i> Upload Logo</button>
        </form>

        <?php if ($brandLogo): ?>
        <form method="post" action="/admin/settings/remove-logo" style="margin-top:12px;">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <button type="submit" class="btn btn-danger btn-sm"
                  data-confirm-click="Remove the current logo?">
            <i class="bi bi-trash"></i> Remove Logo
          </button>
        </form>
        <?php endif; ?>

        <!-- Branding — Field Reference -->
        <div style="margin-top:16px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:6px;padding:10px;font-size:0.78rem;">
          <div style="font-weight:600;color:var(--text);margin-bottom:4px;"><i class="bi bi-info-circle" style="color:var(--primary)"></i> Branding — Field Reference</div>
          <table style="width:100%;border-collapse:collapse;font-size:0.75rem;">
            <thead><tr><th scope="col" style="text-align:left;padding:3px 6px;color:var(--text-muted);">Field</th><th scope="col" style="text-align:left;padding:3px 6px;color:var(--text-muted);">Type</th><th scope="col" style="text-align:left;padding:3px 6px;color:var(--text-muted);">Stored as</th></tr></thead>
            <tbody>
              <tr><td style="padding:2px 6px;font-family:monospace;">org_name</td><td style="padding:2px 6px;">text</td><td style="padding:2px 6px;font-family:monospace;">settings.org_name</td></tr>
              <tr><td style="padding:2px 6px;font-family:monospace;">brand_accent</td><td style="padding:2px 6px;">color (#RRGGBB hex)</td><td style="padding:2px 6px;font-family:monospace;">settings.brand_accent</td></tr>
              <tr><td style="padding:2px 6px;font-family:monospace;">logo_url</td><td style="padding:2px 6px;">text (http(s):// or data:image/...)</td><td style="padding:2px 6px;font-family:monospace;">settings.company_logo_data</td></tr>
              <tr><td style="padding:2px 6px;font-family:monospace;">logo_file</td><td style="padding:2px 6px;">file (image/jpeg, png, gif, webp; ≤2 MB)</td><td style="padding:2px 6px;font-family:monospace;">settings.company_logo_data (data: URI)</td></tr>
              <tr><td style="padding:2px 6px;font-family:monospace;">csrf_token</td><td style="padding:2px 6px;">string (CSRF token)</td><td style="padding:2px 6px;">—</td></tr>
            </tbody>
          </table>
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
