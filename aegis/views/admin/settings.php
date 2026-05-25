<?php
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
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

<div class="two-col-layout" style="max-width:960px">
  <div style="flex:2;display:flex;flex-direction:column;gap:20px">

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

  </div>

  <div style="display:flex;flex-direction:column;gap:20px">

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-stopwatch" style="color:#d97706"></i><span class="card-title">Incident SLA Policy</span></div></div>
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
      <div class="card-header"><div class="card-header-left"><i class="bi bi-list-ul" style="color:#64748b"></i><span class="card-title">All Settings</span></div></div>
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
