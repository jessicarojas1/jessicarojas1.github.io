<?php
$isEdit      = !empty($schedule);
$schedId     = $isEdit ? (int)$schedule['id'] : 0;
$pageTitle   = $isEdit ? 'Edit Scheduled Report' : 'New Scheduled Report';
$breadcrumbs = [
    ['Admin', '/admin'],
    ['Scheduled Reports', '/admin/scheduled-reports'],
    [$isEdit ? 'Edit' : 'New Schedule', null],
];
$formAction  = $isEdit
    ? '/admin/scheduled-reports/' . $schedId . '/update'
    : '/admin/scheduled-reports/create';

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Decode recipients for textarea
$recipientsRaw = '';
if ($isEdit && !empty($schedule['recipients'])) {
    $recs = is_array($schedule['recipients'])
        ? $schedule['recipients']
        : (json_decode($schedule['recipients'], true) ?? []);
    $recipientsRaw = implode("\n", $recs);
}

$v = function(string $key, mixed $fallback = '') use ($isEdit, $schedule): mixed {
    return $isEdit ? ($schedule[$key] ?? $fallback) : $fallback;
};

ob_start();
?>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $isEdit ? 'Edit Scheduled Report' : 'New Scheduled Report' ?></h1>
    <p class="page-subtitle">Configure automatic report delivery to recipients</p>
  </div>
  <div class="page-actions">
    <a href="/admin/scheduled-reports" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<form method="post" action="<?= Security::h($formAction) ?>" style="max-width:720px">
  <?= Security::csrfField() ?>

  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-file-earmark-bar-graph-fill" style="color:var(--primary)"></i>
        <span class="card-title">Report Details</span>
      </div>
    </div>
    <div class="card-body">

      <div class="form-group">
        <label class="form-label" for="sched_name">Schedule Name <span style="color:var(--danger)">*</span></label>
        <input type="text" id="sched_name" name="name" class="form-control"
               value="<?= Security::h($v('name')) ?>" required maxlength="255"
               placeholder="e.g. Monthly Executive Risk Report">
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1">
          <label class="form-label" for="sched_report_type">Report Type <span style="color:var(--danger)">*</span></label>
          <select id="sched_report_type" name="report_type" class="form-control" required>
            <?php
            $reportTypes = [
                'risk_register'      => 'Risk Register',
                'compliance_summary' => 'Compliance Summary',
                'audit_status'       => 'Audit Status',
                'executive_summary'  => 'Executive Summary',
            ];
            $curType = $v('report_type', 'risk_register');
            foreach ($reportTypes as $val => $label): ?>
              <option value="<?= Security::h($val) ?>" <?= $curType === $val ? 'selected' : '' ?>>
                <?= Security::h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="flex:1">
          <label class="form-label" for="sched_frequency">Frequency <span style="color:var(--danger)">*</span></label>
          <select id="sched_frequency" name="frequency" class="form-control" required data-change="onFrequencyChange" data-input-val="1">
            <?php
            $freqs  = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly'];
            $curFreq = $v('frequency', 'weekly');
            foreach ($freqs as $val => $label): ?>
              <option value="<?= Security::h($val) ?>" <?= $curFreq === $val ? 'selected' : '' ?>>
                <?= Security::h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Day of Week (weekly only) -->
      <div id="row_dow" class="form-group" style="display:<?= $curFreq === 'weekly' ? 'block' : 'none' ?>">
        <label class="form-label" for="sched_day_of_week">Day of Week</label>
        <select id="sched_day_of_week" name="day_of_week" class="form-control" style="max-width:220px">
          <?php
          $days    = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday',
                      'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];
          $curDow  = $v('day_of_week', 'monday');
          foreach ($days as $val => $label): ?>
            <option value="<?= Security::h($val) ?>" <?= $curDow === $val ? 'selected' : '' ?>>
              <?= Security::h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Day of Month (monthly/quarterly only) -->
      <div id="row_dom" class="form-group" style="display:<?= in_array($curFreq, ['monthly','quarterly']) ? 'block' : 'none' ?>">
        <label class="form-label" for="sched_day_of_month">Day of Month</label>
        <input type="number" id="sched_day_of_month" name="day_of_month" class="form-control"
               style="max-width:120px"
               value="<?= (int)$v('day_of_month', 1) ?>" min="1" max="28"
               placeholder="1–28">
        <span class="form-text">Must be between 1 and 28 (safe for all months).</span>
      </div>

      <div class="form-group">
        <label class="form-label" for="sched_send_time">Send Time <span style="color:var(--danger)">*</span></label>
        <input type="time" id="sched_send_time" name="send_time" class="form-control"
               style="max-width:160px"
               value="<?= Security::h($v('send_time', '08:00')) ?>" required>
        <span class="form-text">Time is in the server's configured timezone.</span>
      </div>

    </div>
  </div>

  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-people-fill" style="color:var(--success)"></i>
        <span class="card-title">Delivery</span>
      </div>
    </div>
    <div class="card-body">

      <div class="form-group">
        <label class="form-label" for="sched_recipients">Recipients <span style="color:var(--danger)">*</span></label>
        <textarea id="sched_recipients" name="recipients" class="form-control" rows="5" required
                  placeholder="one@example.com&#10;two@example.com&#10;three@example.com"><?= Security::h($recipientsRaw) ?></textarea>
        <span class="form-text">Enter one email address per line. All recipients will receive the report simultaneously.</span>
      </div>

      <div class="form-group">
        <label class="form-label">Format</label>
        <div style="display:flex;gap:20px;margin-top:4px">
          <?php
          $curFmt = $v('format', 'html');
          $fmts   = ['html' => 'HTML Email', 'csv' => 'CSV Attachment', 'both' => 'Both (HTML + CSV)'];
          foreach ($fmts as $val => $label): ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
              <input type="radio" name="format" value="<?= Security::h($val) ?>"
                     <?= $curFmt === $val ? 'checked' : '' ?>>
              <?= Security::h($label) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:10px;padding-top:14px;border-top:1px solid var(--border,#e4e4e7)">
        <label class="pill-toggle">
          <input type="checkbox" id="sched_is_active" name="is_active" value="1"
                 <?= $v('is_active', true) ? 'checked' : '' ?>>
          <span class="pill-track"></span>
        </label>
        <label for="sched_is_active" style="font-size:14px;font-weight:500;cursor:pointer;margin:0">Active</label>
        <span style="font-size:13px;color:var(--text-muted)">— paused schedules will not send</span>
      </div>

    </div>
    <div class="card-footer" style="padding:16px 20px;border-top:1px solid var(--border,#e4e4e7);display:flex;gap:10px">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-save"></i> <?= $isEdit ? 'Save Changes' : 'Create Schedule' ?>
      </button>
      <a href="/admin/scheduled-reports" class="btn btn-ghost">Cancel</a>
    </div>
  </div>

</form>

<style nonce="<?= Security::nonce() ?>">
.pill-toggle { position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0 }
.pill-toggle input[type="checkbox"] { opacity:0;width:0;height:0;position:absolute }
.pill-track { position:absolute;inset:0;border-radius:24px;background:#d1d5db;cursor:pointer;transition:background .2s }
.pill-toggle input:checked + .pill-track { background:var(--primary) }
.pill-track::before { content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .2s }
.pill-toggle input:checked + .pill-track::before { transform:translateX(20px) }
</style>

<script nonce="<?= Security::nonce() ?>">
function onFrequencyChange(freq) {
    document.getElementById('row_dow').style.display  = (freq === 'weekly') ? 'block' : 'none';
    document.getElementById('row_dom').style.display  = (freq === 'monthly' || freq === 'quarterly') ? 'block' : 'none';
}
// Init on load
onFrequencyChange(document.getElementById('sched_frequency').value);
</script>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
