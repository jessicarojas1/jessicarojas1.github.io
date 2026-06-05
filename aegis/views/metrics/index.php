<?php
$trendDates  = array_column($trend, 'snapshot_date');
$trendGRC    = array_column($trend, 'grc_score');
$trendComp   = array_column($trend, 'compliance_pct');
$trendRisk   = array_column($trend, 'risk_health');
$isAdmin     = Auth::role() === 'admin';

$L = $live ?? [];
$kpiGrc        = number_format((float)($L['grc_score']      ?? 0), 1);
$kpiComp       = number_format((float)($L['compliance_pct'] ?? 0), 1);
$kpiRisk       = number_format((float)($L['risk_health']    ?? 0), 1);
$kpiPolicy     = number_format((float)($L['policy_health']  ?? 0), 1);
$kpiOpenRisks  = (int)($L['open_risks']     ?? 0);
$kpiOpenInc    = (int)($L['open_incidents'] ?? 0);

function metricColor(float $pct): string {
    return $pct >= 80 ? '#059669' : ($pct >= 60 ? '#d97706' : '#dc2626');
}
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Metrics &amp; Trends</h1>
    <p class="page-subtitle">Live GRC posture and 90-day historical trends</p>
  </div>
  <div class="page-actions">
    <?php if ($isAdmin): ?>
      <button class="btn btn-secondary btn-sm" data-show-modal="scheduleModal">
        <i class="bi bi-calendar-plus"></i> Add Schedule
      </button>
    <?php endif; ?>
    <a href="/report/board" class="btn btn-primary btn-sm"><i class="bi bi-briefcase-fill"></i> Board Pack</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<!-- KPI Row -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px">
  <?php
  $kpis = [
    ['GRC Score',      $kpiGrc    . '%', 'bi-shield-fill-check',      metricColor((float)$kpiGrc),    null,         (float)$kpiGrc],
    ['Compliance',     $kpiComp   . '%', 'bi-bar-chart-fill',          metricColor((float)$kpiComp),   null,         (float)$kpiComp],
    ['Risk Health',    $kpiRisk   . '%', 'bi-heart-pulse-fill',        metricColor((float)$kpiRisk),   null,         (float)$kpiRisk],
    ['Policy Health',  $kpiPolicy . '%', 'bi-file-earmark-check-fill', metricColor((float)$kpiPolicy), null,         (float)$kpiPolicy],
    ['Open Risks',     $kpiOpenRisks,    'bi-exclamation-triangle-fill','#f97316',                     '/risk',      null],
    ['Open Incidents', $kpiOpenInc,      'bi-fire',                    '#ef4444',                      '/incidents', null],
  ];
  foreach ($kpis as [$label, $val, $icon, $color, $href, $pct]):
  ?>
  <div class="card" style="padding:18px;text-align:center;<?= $href ? 'cursor:pointer' : '' ?>"
       <?= $href ? 'data-href="' . Security::h($href) . '"' : '' ?>>
    <div style="width:40px;height:40px;border-radius:10px;background:<?= $color ?>18;color:<?= $color ?>;
         display:flex;align-items:center;justify-content:center;font-size:18px;margin:0 auto 10px">
      <i class="bi <?= $icon ?>"></i>
    </div>
    <div style="font-size:26px;font-weight:800;color:<?= $color ?>;line-height:1;margin-bottom:4px"><?= $val ?></div>
    <?php if ($pct !== null): ?>
    <div style="height:3px;background:var(--bg-secondary);border-radius:2px;margin-bottom:6px;overflow:hidden">
      <div style="height:100%;width:<?= min(100,(int)$pct) ?>%;background:<?= $color ?>;border-radius:2px"></div>
    </div>
    <?php endif; ?>
    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em"><?= $label ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Main 2-column layout: Trend chart + Health scorecard -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:20px;margin-bottom:22px;align-items:start">

  <!-- Trend chart -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <div>
        <span class="card-title">GRC Performance Trend</span>
        <div style="font-size:12px;color:var(--text-muted);margin-top:1px">90-day snapshot history</div>
      </div>
      <div style="display:flex;gap:14px;font-size:11px;font-weight:600;color:var(--text-muted)">
        <span><span style="display:inline-block;width:12px;height:3px;background:#6366f1;border-radius:2px;margin-right:4px;vertical-align:middle"></span>GRC</span>
        <span><span style="display:inline-block;width:12px;height:3px;background:#0284c7;border-radius:2px;margin-right:4px;vertical-align:middle"></span>Compliance</span>
        <span><span style="display:inline-block;width:12px;height:3px;background:#d97706;border-radius:2px;margin-right:4px;vertical-align:middle"></span>Risk</span>
      </div>
    </div>
    <div class="card-body" style="padding:20px">
      <?php if (!empty($trend)): ?>
        <canvas id="trendChart" height="180"></canvas>
      <?php else: ?>
        <div style="text-align:center;padding:48px 20px;color:var(--text-muted)">
          <i class="bi bi-graph-up" style="font-size:36px;display:block;margin-bottom:10px;opacity:.4"></i>
          <p style="margin:0;font-size:14px">Trend data will appear once daily snapshots are recorded.</p>
          <p style="margin:6px 0 0;font-size:12px;color:var(--text-light)">GRC scores are captured automatically each day.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Right column: Health scorecard + action items -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Health Scorecard -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="bi bi-activity" style="color:var(--primary)"></i> Health Scorecard</span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:14px;padding:20px">
        <?php foreach ([
          ['GRC Score',     $kpiGrc,    'bi-shield-fill-check'],
          ['Compliance',    $kpiComp,   'bi-bar-chart-fill'],
          ['Risk Health',   $kpiRisk,   'bi-heart-pulse-fill'],
          ['Policy Health', $kpiPolicy, 'bi-file-earmark-check-fill'],
        ] as [$lbl, $p, $ic]):
          $c = metricColor((float)$p);
        ?>
        <div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <span style="font-size:12px;font-weight:600;color:var(--text-muted)"><i class="bi <?= $ic ?>"></i> <?= $lbl ?></span>
            <span style="font-size:13px;font-weight:700;color:<?= $c ?>"><?= $p ?>%</span>
          </div>
          <div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden">
            <div style="height:100%;width:<?= min(100,(int)$p) ?>%;background:<?= $c ?>;border-radius:3px;transition:width .5s"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Attention Required -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="bi bi-bell-fill" style="color:#d97706"></i> Attention Required</span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:8px;padding:14px">
        <a href="/risk" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:<?= $kpiOpenRisks > 0 ? 'rgba(249,115,22,.08)' : 'var(--bg-secondary)' ?>;border-radius:8px;text-decoration:none;color:var(--text);border:1px solid <?= $kpiOpenRisks > 0 ? '#f9731633' : 'var(--border)' ?>">
          <i class="bi bi-exclamation-triangle-fill" style="color:#f97316;font-size:16px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600"><?= $kpiOpenRisks ?> Open Risk<?= $kpiOpenRisks !== 1 ? 's' : '' ?></div>
            <div style="font-size:11px;color:var(--text-muted)">View risk register</div>
          </div>
          <i class="bi bi-arrow-right-short" style="color:var(--text-muted);font-size:18px"></i>
        </a>
        <a href="/incidents" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:<?= $kpiOpenInc > 0 ? 'rgba(239,68,68,.08)' : 'var(--bg-secondary)' ?>;border-radius:8px;text-decoration:none;color:var(--text);border:1px solid <?= $kpiOpenInc > 0 ? '#ef444433' : 'var(--border)' ?>">
          <i class="bi bi-fire" style="color:#ef4444;font-size:16px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600"><?= $kpiOpenInc ?> Open Incident<?= $kpiOpenInc !== 1 ? 's' : '' ?></div>
            <div style="font-size:11px;color:var(--text-muted)">View incident queue</div>
          </div>
          <i class="bi bi-arrow-right-short" style="color:var(--text-muted);font-size:18px"></i>
        </a>
        <a href="/compliance/gap-analysis" style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--bg-secondary);border-radius:8px;text-decoration:none;color:var(--text);border:1px solid var(--border)">
          <i class="bi bi-clipboard2-x-fill" style="color:#7c3aed;font-size:16px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600">Gap Analysis</div>
            <div style="font-size:11px;color:var(--text-muted)">View control gaps</div>
          </div>
          <i class="bi bi-arrow-right-short" style="color:var(--text-muted);font-size:18px"></i>
        </a>
      </div>
    </div>

  </div>
</div>

<!-- Compliance by Framework — styled progress bars -->
<div class="card" style="margin-bottom:22px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="bi bi-bar-chart-steps" style="color:var(--primary)"></i> Compliance by Framework</span>
    <a href="/compliance" class="btn btn-ghost btn-sm">View All <i class="bi bi-arrow-right"></i></a>
  </div>
  <?php if (!empty($frameworks)): ?>
  <div style="padding:0">
    <!-- Legend row -->
    <div style="display:grid;grid-template-columns:minmax(140px,200px) 1fr 56px 52px 52px 52px 52px;align-items:center;gap:10px;padding:8px 20px;border-bottom:2px solid var(--border);font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em">
      <div>Framework</div>
      <div>Progress</div>
      <div style="text-align:right">Score</div>
      <div style="text-align:center;color:#22c55e">Done</div>
      <div style="text-align:center;color:#f59e0b">Partial</div>
      <div style="text-align:center;color:#ef4444">Gap</div>
      <div style="text-align:center">Total</div>
    </div>
    <?php foreach ($frameworks as $fw):
      $pct = $fw['total_controls'] > 0 ? round($fw['compliant'] / $fw['total_controls'] * 100) : 0;
      $barColor = $pct >= 80 ? '#22c55e' : ($pct >= 50 ? '#f59e0b' : '#ef4444');
    ?>
    <div style="display:grid;grid-template-columns:minmax(140px,200px) 1fr 56px 52px 52px 52px 52px;align-items:center;gap:10px;padding:12px 20px;border-bottom:1px solid var(--border)">
      <div style="font-size:13px;font-weight:600;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= Security::h($fw['name']) ?>"><?= Security::h($fw['name']) ?></div>
      <div style="background:var(--bg-secondary);border-radius:4px;height:8px;overflow:hidden">
        <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:4px;transition:width .4s"></div>
      </div>
      <div style="font-weight:700;color:<?= $barColor ?>;font-size:13px;text-align:right"><?= $pct ?>%</div>
      <div style="text-align:center;color:#22c55e;font-size:13px;font-weight:600"><?= (int)$fw['compliant'] ?></div>
      <div style="text-align:center;color:#f59e0b;font-size:13px;font-weight:600"><?= (int)$fw['partial'] ?></div>
      <div style="text-align:center;color:#ef4444;font-size:13px;font-weight:600"><?= (int)$fw['non_compliant'] ?></div>
      <div style="text-align:center;font-size:13px;color:var(--text-muted)"><?= (int)$fw['total_controls'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
    <i class="bi bi-clipboard2-x" style="font-size:32px;display:block;margin-bottom:10px;opacity:.4"></i>
    <p style="margin:0">No frameworks configured. <a href="/compliance/import">Import a framework</a> to get started.</p>
  </div>
  <?php endif; ?>
</div>

<!-- Scheduled reports (admin only) -->
<?php if ($isAdmin): ?>
<div class="card" style="margin-bottom:22px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="bi bi-calendar-week" style="color:var(--primary)"></i> Scheduled Report Delivery</span>
    <button class="btn btn-primary btn-sm" data-show-modal="scheduleModal">
      <i class="bi bi-plus-lg"></i> Add Schedule
    </button>
  </div>
  <?php if (empty($reportSchedules)): ?>
    <div class="card-body" style="text-align:center;padding:32px;color:var(--text-muted)">
      <i class="bi bi-calendar-x" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>
      <p style="margin:0 0 12px">No scheduled reports configured yet.</p>
      <button class="btn btn-secondary btn-sm" data-show-modal="scheduleModal"><i class="bi bi-plus-lg"></i> Add Schedule</button>
    </div>
  <?php else: ?>
    <div class="card-body" style="padding:0">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Type</th><th>Frequency</th><th>Recipients</th><th>Last Sent</th><th>Status</th><th></th></tr></thead>
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
    </div>
  <?php endif; ?>
</div>

<!-- Schedule modal (uses main modal system: data-show-modal / showModal) -->
<div id="scheduleModal" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="bi bi-calendar-plus"></i> New Report Schedule</h3>
      <button class="btn-icon" data-close-modal="scheduleModal"><i class="bi bi-x-lg"></i></button>
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
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" checked>
            <span style="font-size:13px">Enable this schedule immediately</span>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Save Schedule</button>
        <button type="button" class="btn btn-ghost" data-close-modal="scheduleModal">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($trend)): ?>
<script src="/public/vendor/chart.js/chart.umd.js" integrity="sha384-tgbB5AKnszdcfwcZtTfuhR3Ko1XZdlDfsLtkxiiAZiVkkXCkFmp+FQFh+V/UTo54" crossorigin="anonymous" nonce="<?= Security::nonce() ?>"></script>
<script nonce="<?= Security::nonce() ?>">
(function() {
  'use strict';
  // Resolve dark-mode aware colors at runtime
  var style = getComputedStyle(document.documentElement);
  var textMuted = style.getPropertyValue('--text-muted').trim() || '#64748b';
  var border    = style.getPropertyValue('--border').trim() || '#e2e8f0';
  var bgSecond  = style.getPropertyValue('--bg-secondary').trim() || '#f1f5f9';

  var ctx = document.getElementById('trendChart');
  if (!ctx) return;
  new Chart(ctx.getContext('2d'), {
    type: 'line',
    data: {
      labels: <?= json_encode($trendDates) ?>,
      datasets: [
        {
          label: 'GRC Score',
          data: <?= json_encode($trendGRC) ?>,
          borderColor: '#6366f1',
          backgroundColor: 'rgba(99,102,241,.08)',
          tension: 0.35,
          fill: true,
          pointBackgroundColor: '#6366f1',
          pointRadius: 3,
          pointHoverRadius: 5,
          borderWidth: 2.5
        },
        {
          label: 'Compliance',
          data: <?= json_encode($trendComp) ?>,
          borderColor: '#0284c7',
          backgroundColor: 'transparent',
          tension: 0.35,
          pointBackgroundColor: '#0284c7',
          pointRadius: 3,
          pointHoverRadius: 5,
          borderWidth: 2
        },
        {
          label: 'Risk Health',
          data: <?= json_encode($trendRisk) ?>,
          borderColor: '#d97706',
          backgroundColor: 'transparent',
          tension: 0.35,
          pointBackgroundColor: '#d97706',
          pointRadius: 3,
          pointHoverRadius: 5,
          borderWidth: 2
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(15,23,42,.9)',
          titleColor: '#f1f5f9',
          bodyColor: '#94a3b8',
          borderColor: '#334155',
          borderWidth: 1,
          padding: 10,
          callbacks: {
            label: function(c) { return ' ' + c.dataset.label + ': ' + c.parsed.y + '%'; }
          }
        }
      },
      scales: {
        x: {
          grid: { color: border, drawBorder: false },
          ticks: { color: textMuted, font: { size: 11 }, maxTicksLimit: 8 }
        },
        y: {
          min: 0, max: 100,
          grid: { color: border, drawBorder: false },
          ticks: {
            color: textMuted,
            font: { size: 11 },
            callback: function(v) { return v + '%'; }
          }
        }
      }
    }
  });
})();

// Make KPI cards with data-href clickable
document.querySelectorAll('[data-href]').forEach(function(el) {
  el.addEventListener('click', function() { location.href = el.dataset.href; });
});
</script>
<?php else: ?>
<script nonce="<?= Security::nonce() ?>">
document.querySelectorAll('[data-href]').forEach(function(el) {
  el.addEventListener('click', function() { location.href = el.dataset.href; });
});
</script>
<?php endif; ?>

<?php
$content      = ob_get_clean();
$pageTitle    = 'Metrics & Trends';
$activeModule = 'metrics';
$breadcrumbs  = [['Metrics & Trends', null]];
require AEGIS_ROOT . '/views/layout.php';
?>
