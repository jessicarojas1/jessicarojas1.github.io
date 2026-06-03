<?php
$trendDates  = array_column($trend, 'snapshot_date');
$trendGRC    = array_column($trend, 'grc_score');
$trendComp   = array_column($trend, 'compliance_pct');
$trendRisk   = array_column($trend, 'risk_health');
$isAdmin     = Auth::role() === 'admin';

$classColors = ['public'=>'#22c55e','internal'=>'#3b82f6','confidential'=>'#f59e0b','restricted'=>'#ef4444'];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Metrics &amp; Trends</h1>
    <p class="page-subtitle">90-day GRC posture history and scheduled report delivery.</p>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<!-- KPI row -->
<?php $s = $snapshot ?? []; ?>
<div class="stats-grid" style="margin-bottom:24px">
  <?php
  $kpis = [
    ['GRC Score',      number_format((float)($s['grc_score'] ?? 0),1) . '%',   'bi-shield-fill-check', '#1e3a5f'],
    ['Compliance',     number_format((float)($s['compliance_pct'] ?? 0),1) . '%', 'bi-shield-check', '#0284c7'],
    ['Risk Health',    number_format((float)($s['risk_health'] ?? 0),1) . '%',  'bi-exclamation-triangle-fill', '#d97706'],
    ['Policy Health',  number_format((float)($s['policy_health'] ?? 0),1) . '%','bi-file-earmark-text-fill', '#7c3aed'],
  ];
  foreach ($kpis as [$label, $val, $icon, $color]): ?>
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $color ?>20;color:<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
      <div class="stat-info">
        <div class="stat-value"><?= $val ?></div>
        <div class="stat-label"><?= $label ?></div>
      </div>
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
        $barColor = $pct >= 80 ? '#22c55e' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
      ?>
        <tr>
          <td class="fw-600"><?= Security::h($fw['name']) ?></td>
          <td style="color:#22c55e"><?= (int)$fw['compliant'] ?></td>
          <td style="color:#f59e0b"><?= (int)$fw['partial'] ?></td>
          <td style="color:#ef4444"><?= (int)$fw['non_compliant'] ?></td>
          <td class="text-muted"><?= (int)$fw['na'] ?></td>
          <td><?= (int)$fw['total_controls'] ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:6px;background:#e5e7eb;border-radius:3px">
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
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('scheduleModal').classList.add('open')">
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
                <button class="btn btn-sm btn-danger" onclick="return confirm('Delete this schedule?')">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Schedule modal -->
<div id="scheduleModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-card" style="max-width:520px">
    <div class="modal-header">
      <h3>New Report Schedule</h3>
      <button onclick="document.getElementById('scheduleModal').classList.remove('open')" class="btn-icon"><i class="bi bi-x-lg"></i></button>
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
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('scheduleModal').classList.remove('open')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<style>
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-card { background:#fff; border-radius:12px; width:100%; max-height:90vh; overflow-y:auto; }
.modal-header { padding:20px 24px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
.modal-body { padding:24px; }
.modal-footer { padding:16px 24px; border-top:1px solid #e5e7eb; display:flex; gap:8px; justify-content:flex-end; }
</style>
<?php endif; ?>

<?php if (!empty($trend)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" nonce="<?= Security::nonce() ?>"></script>
<script nonce="<?= Security::nonce() ?>">
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($trendDates) ?>,
    datasets: [
      { label: 'GRC Score',   data: <?= json_encode($trendGRC) ?>,  borderColor:'#1e3a5f', backgroundColor:'#1e3a5f20', tension:.3, fill:true },
      { label: 'Compliance',  data: <?= json_encode($trendComp) ?>, borderColor:'#0284c7', backgroundColor:'transparent', tension:.3 },
      { label: 'Risk Health', data: <?= json_encode($trendRisk) ?>, borderColor:'#d97706', backgroundColor:'transparent', tension:.3 },
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
