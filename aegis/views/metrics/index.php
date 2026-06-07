<?php
$breadcrumbs = $breadcrumbs ?? [['Metrics', null]];
$trendDates  = array_column($trend, 'snapshot_date');
$trendGRC    = array_column($trend, 'grc_score');
$trendComp   = array_column($trend, 'compliance_pct');
$trendRisk   = array_column($trend, 'risk_health');
$isAdmin     = Auth::role() === 'admin';

// Use live-computed values (always fresh; snapshot augments history only)
$L = $live ?? [];
$kpiGrc        = number_format((float)($L['grc_score']      ?? 0), 1);
$kpiComp       = number_format((float)($L['compliance_pct'] ?? 0), 1);
$kpiRisk       = number_format((float)($L['risk_health']    ?? 0), 1);
$kpiPolicy     = number_format((float)($L['policy_health']  ?? 0), 1);
$kpiOpenRisks  = (int)($L['open_risks']     ?? 0);
$kpiOpenInc    = (int)($L['open_incidents'] ?? 0);

function metricColor(float $pct): string {
    return $pct >= 80 ? 'var(--success)' : ($pct >= 60 ? 'var(--warning)' : 'var(--danger)');
}
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Metrics &amp; Trends</h1>
    <p class="page-subtitle">Live GRC posture and 90-day historical trends.</p>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<!-- KPI row — live values always shown -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px">
  <?php
  $kpis = [
    ['GRC Score',      $kpiGrc    . '%', 'bi-shield-fill-check',       metricColor((float)$kpiGrc),    null],
    ['Compliance',     $kpiComp   . '%', 'bi-bar-chart-fill',           metricColor((float)$kpiComp),   null],
    ['Risk Health',    $kpiRisk   . '%', 'bi-heart-pulse-fill',         metricColor((float)$kpiRisk),   null],
    ['Policy Health',  $kpiPolicy . '%', 'bi-file-earmark-check-fill',  metricColor((float)$kpiPolicy), null],
    ['Open Risks',     $kpiOpenRisks,    'bi-exclamation-triangle-fill','var(--orange)',                 '/risk'],
    ['Open Incidents', $kpiOpenInc,      'bi-fire',                     'var(--danger)',                 '/incidents'],
  ];
  foreach ($kpis as [$label, $val, $icon, $color, $href]):
    $pctVal = is_string($val) && str_ends_with($val, '%') ? (float)$val : null;
  ?>
  <div class="card" style="padding:20px;text-align:center;<?= $href ? 'cursor:pointer' : '' ?>"
       <?= $href ? 'data-href="' . Security::h($href) . '"' : '' ?>>
    <div style="width:44px;height:44px;border-radius:12px;background:<?= $color ?>18;color:<?= $color ?>;
         display:flex;align-items:center;justify-content:center;font-size:20px;margin:0 auto 12px">
      <i class="bi <?= $icon ?>"></i>
    </div>
    <div style="font-size:28px;font-weight:800;color:<?= $color ?>;line-height:1;margin-bottom:6px"><?= $val ?></div>
    <?php if ($pctVal !== null): ?>
    <div style="height:4px;background:var(--bg-secondary);border-radius:2px;margin-bottom:8px;overflow:hidden">
      <div style="height:100%;width:<?= min(100,(int)$pctVal) ?>%;background:<?= $color ?>;border-radius:2px;transition:width .5s"></div>
    </div>
    <?php endif; ?>
    <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Trend chart -->
<?php if (!empty($trend)): ?>
<div class="card" style="margin-bottom:24px">
  <div class="card-header"><h3>90-Day GRC Score Trend</h3></div>
  <div class="card-body">
    <canvas id="trendChart" height="100"></canvas>
  </div>
</div>
<?php endif; ?>

<!-- Compliance by framework -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header"><h3>Compliance by Framework</h3></div>
  <table class="data-table">
    <thead><tr><th>Framework</th><th>Compliant</th><th>Partial</th><th>Non-Compliant</th><th>N/A</th><th>Total</th><th>%</th></tr></thead>
    <tbody>
      <?php foreach ($frameworks as $fw):
        $pct = $fw['total_controls'] > 0 ? round($fw['compliant'] / $fw['total_controls'] * 100) : 0;
        $barColor = $pct >= 80 ? 'var(--primary-light)' : ($pct >= 50 ? 'var(--warning)' : 'var(--danger)');
      ?>
        <tr>
          <td class="fw-600"><?= Security::h($fw['name']) ?></td>
          <td style="color:var(--success)"><?= (int)$fw['compliant'] ?></td>
          <td style="color:var(--warning)"><?= (int)$fw['partial'] ?></td>
          <td style="color:var(--danger)"><?= (int)$fw['non_compliant'] ?></td>
          <td class="text-muted"><?= (int)$fw['na'] ?></td>
          <td><?= (int)$fw['total_controls'] ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:6px;background:var(--bg-secondary);border-radius:3px">
                <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:3px"></div>
              </div>
              <span style="min-width:36px;font-weight:600;color:<?= $barColor ?>"><?= $pct ?>%</span>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Scheduled reports (admin only) -->
<?php if ($isAdmin): ?>
<div class="card" style="margin-bottom:24px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <h3>Scheduled Report Delivery</h3>
    <button class="btn btn-primary btn-sm" data-show-modal="scheduleModal">
      <i class="bi bi-plus-lg"></i> Add Schedule
    </button>
  </div>
  <?php if (empty($reportSchedules)): ?>
    <div class="card-body text-muted">No scheduled reports configured. Click "Add Schedule" to set up automated report delivery.</div>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Name</th><th>Type</th><th>Frequency</th><th>Recipients</th><th>Last Sent</th><th>Active</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($reportSchedules as $rs): ?>
          <tr>
            <td class="fw-600"><?= Security::h($rs['name']) ?></td>
            <td><?= Security::h(ucfirst($rs['report_type'])) ?></td>
            <td><?= Security::h(ucfirst($rs['frequency'])) ?></td>
            <td class="text-sm text-muted"><?= count(json_decode($rs['recipients'], true) ?? []) ?> recipient(s)</td>
            <td class="text-sm text-muted"><?= $rs['last_sent_at'] ? date('M j, Y', strtotime($rs['last_sent_at'])) : 'Never' ?></td>
            <td><?= $rs['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Paused</span>' ?></td>
            <td>
              <form method="POST" action="/metrics/schedule/<?= (int)$rs['id'] ?>/delete" style="display:inline">
                <?= Security::csrfField() ?>
                <button class="btn btn-sm btn-danger" data-confirm-click="Delete this schedule?">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Schedule modal -->
<div id="scheduleModal" class="um-overlay">
  <div class="um-dialog" style="max-width:520px">
    <div class="modal-header">
      <h3>New Report Schedule</h3>
      <button data-close-modal="scheduleModal" class="btn-icon"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/metrics/schedule/save">
      <?= Security::csrfField() ?>
      <div class="modal-body" style="display:flex;flex-direction:column;gap:16px">
        <div class="form-group">
          <label class="form-label">Schedule Name <span class="required">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="Weekly Executive Summary" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Report Type</label>
            <select name="report_type" class="form-control">
              <option value="executive">Executive Summary</option>
              <option value="compliance">Compliance</option>
              <option value="risk">Risk Register</option>
              <option value="audit">Audit Status</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Frequency</label>
            <select name="frequency" class="form-control">
              <option value="daily">Daily</option>
              <option value="weekly" selected>Weekly</option>
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Recipients (one email per line) <span class="required">*</span></label>
          <textarea name="recipients" class="form-control" rows="4" placeholder="ciso@company.com&#10;board@company.com" required></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Active</label>
          <label class="toggle-switch">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" checked>
            <span class="toggle-slider"></span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Save Schedule</button>
        <button type="button" class="btn btn-secondary" data-close-modal="scheduleModal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php if (!empty($trend)): ?>
<script src="/public/vendor/chart.js/chart.umd.js" integrity="sha384-tgbB5AKnszdcfwcZtTfuhR3Ko1XZdlDfsLtkxiiAZiVkkXCkFmp+FQFh+V/UTo54" crossorigin="anonymous" nonce="<?= Security::nonce() ?>"></script>
<script nonce="<?= Security::nonce() ?>">
document.querySelectorAll('[data-href]').forEach(function(el) {
  el.addEventListener('click', function() { location.href = el.dataset.href; });
});
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($trendDates, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    datasets: [
      { label: 'GRC Score',   data: <?= json_encode($trendGRC, JSON_HEX_TAG | JSON_HEX_AMP) ?>,  borderColor:'#1e3a5f', backgroundColor:'#1e3a5f20', tension:.3, fill:true },
      { label: 'Compliance',  data: <?= json_encode($trendComp, JSON_HEX_TAG | JSON_HEX_AMP) ?>, borderColor:'var(--info)', backgroundColor:'transparent', tension:.3 },
      { label: 'Risk Health', data: <?= json_encode($trendRisk, JSON_HEX_TAG | JSON_HEX_AMP) ?>, borderColor:'var(--warning)', backgroundColor:'transparent', tension:.3 },
    ]
  },
  options: {
    responsive:true,
    plugins:{ legend:{ position:'top' } },
    scales:{ y:{ min:0, max:100, ticks:{ callback: v => v + '%' } } }
  }
});
</script>
<?php endif; ?>
