<?php
// $boardData array is passed by ReportController::board()
// Keys: grc_score, trend, frameworks, open_risks, top_risks, incidents_30d, policies_expiring

$grcScore         = (int)($boardData['grc_score']        ?? 0);
$trend            = $boardData['trend']                  ?? [];
$frameworks       = $boardData['frameworks']             ?? [];
$openRisks        = $boardData['open_risks']             ?? ['critical'=>0,'high'=>0,'medium'=>0,'low'=>0];
$topRisks         = $boardData['top_risks']              ?? [];
$incidents30d     = (int)($boardData['incidents_30d']    ?? 0);
$policiesExpiring = (int)($boardData['policies_expiring'] ?? 0);

// Compute overall compliance % from frameworks
$totalCompliance = count($frameworks) > 0
    ? round(array_sum(array_column($frameworks, 'compliant_pct')) / count($frameworks))
    : 0;

// GRC score color
if ($grcScore >= 80)       { $grcColor = '#059669'; $grcLabel = 'Good'; }
elseif ($grcScore >= 60)   { $grcColor = '#d97706'; $grcLabel = 'Fair'; }
else                        { $grcColor = '#dc2626'; $grcLabel = 'At Risk'; }

$openCritical = (int)($openRisks['critical'] ?? 0);

// Trend chart data
$trendLabels     = array_map(fn($s) => date('M d', strtotime($s['snapshot_date'] ?? $s['date'] ?? '')), $trend);
$trendGrc        = array_column($trend, 'grc_score');
$trendCompliance = array_column($trend, 'compliance_pct');

// Risk donut data
$riskCounts  = [$openRisks['critical']??0, $openRisks['high']??0, $openRisks['medium']??0, $openRisks['low']??0];
$riskColors  = ['#ef4444','#f97316','#f59e0b','#22c55e'];
$riskLabels  = ['Critical','High','Medium','Low'];

ob_start();
?>

<style>
/* Print styles */
@media print {
  .sidebar, .topbar, .sidebar-toggle, .alert-bell, .btn-logout,
  .page-actions, .filter-bar { display: none !important; }
  .main-content { margin: 0 !important; }
  .page-content { padding: 0 !important; }
  .card { break-inside: avoid; box-shadow: none; border: 1px solid #e5e7eb; }
  .board-print-header { display: block !important; }
  body { font-size: 12px; }
}

.board-print-header { display: none; }
.board-kpi-grid { display: grid; grid-template-columns: repeat(5,1fr); gap: 16px; margin-bottom: 24px; }
.board-kpi { padding: 20px; border-radius: 12px; background: #fff; box-shadow: var(--shadow); text-align: center; }
.board-kpi .kpi-num { display: block; font-size: 36px; font-weight: 800; line-height: 1; margin: 8px 0 4px; }
.board-kpi .kpi-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); }
.board-chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
.board-table-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 900px) {
  .board-kpi-grid { grid-template-columns: repeat(2,1fr); }
  .board-chart-grid, .board-table-grid { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
  .board-kpi-grid { grid-template-columns: 1fr; }
}
</style>

<!-- Print header (hidden on screen) -->
<div class="board-print-header" style="margin-bottom:24px;">
  <h2 style="margin:0;">AEGIS GRC — Executive Board Dashboard</h2>
  <p style="color:#64748b;margin:4px 0 0;">Generated: <?= date('F j, Y \a\t g:i A') ?></p>
  <hr>
</div>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-briefcase-fill" style="margin-right:8px;color:var(--primary);"></i> Executive Board Dashboard</h1>
    <p class="page-subtitle">Enterprise GRC posture at a glance &mdash; <?= date('F j, Y') ?></p>
  </div>
  <div class="page-actions">
    <button class="btn btn-ghost" onclick="window.print()">
      <i class="bi bi-printer"></i> Print / Export
    </button>
  </div>
</div>

<!-- ── KPI Cards ─────────────────────────────────────────────────────────── -->
<div class="board-kpi-grid">

  <!-- GRC Score -->
  <div class="board-kpi" style="border-top:4px solid <?= $grcColor ?>;">
    <i class="bi bi-speedometer2" style="font-size:24px;color:<?= $grcColor ?>;"></i>
    <span class="kpi-num" style="color:<?= $grcColor ?>;"><?= $grcScore ?></span>
    <div class="kpi-label">GRC Score</div>
    <span style="font-size:11px;font-weight:600;background:<?= $grcColor ?>18;color:<?= $grcColor ?>;padding:2px 10px;border-radius:99px;"><?= $grcLabel ?></span>
  </div>

  <!-- Compliance % -->
  <div class="board-kpi" style="border-top:4px solid #6366f1;">
    <i class="bi bi-check2-circle" style="font-size:24px;color:#6366f1;"></i>
    <span class="kpi-num" style="color:#6366f1;"><?= $totalCompliance ?>%</span>
    <div class="kpi-label">Compliance</div>
  </div>

  <!-- Open Critical Risks -->
  <div class="board-kpi" style="border-top:4px solid #ef4444;">
    <i class="bi bi-exclamation-octagon-fill" style="font-size:24px;color:#ef4444;"></i>
    <span class="kpi-num" style="color:#ef4444;"><?= $openCritical ?></span>
    <div class="kpi-label">Critical Risks</div>
  </div>

  <!-- Incidents (30d) -->
  <div class="board-kpi" style="border-top:4px solid #f97316;">
    <i class="bi bi-shield-exclamation" style="font-size:24px;color:#f97316;"></i>
    <span class="kpi-num" style="color:#f97316;"><?= $incidents30d ?></span>
    <div class="kpi-label">Incidents (30d)</div>
  </div>

  <!-- Policies Expiring -->
  <div class="board-kpi" style="border-top:4px solid #d97706;">
    <i class="bi bi-file-earmark-x-fill" style="font-size:24px;color:#d97706;"></i>
    <span class="kpi-num" style="color:#d97706;"><?= $policiesExpiring ?></span>
    <div class="kpi-label">Policies Expiring</div>
  </div>

</div>

<!-- ── Charts ────────────────────────────────────────────────────────────── -->
<div class="board-chart-grid">

  <!-- 90-day GRC / Compliance trend -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-graph-up-arrow"></i> 90-Day Trend</h3>
    </div>
    <div class="card-body">
      <canvas id="trendChart" height="200"></canvas>
    </div>
  </div>

  <!-- Risk distribution donut -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-pie-chart-fill"></i> Open Risk Distribution</h3>
    </div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center;gap:32px;flex-wrap:wrap;">
      <canvas id="riskDonut" width="180" height="180" style="max-width:180px;flex-shrink:0;"></canvas>
      <div style="font-size:13px;">
        <?php foreach ($riskLabels as $i => $label): ?>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?= $riskColors[$i] ?>;flex-shrink:0;"></span>
            <span><?= $label ?></span>
            <strong style="margin-left:auto;padding-left:24px;"><?= (int)$riskCounts[$i] ?></strong>
          </div>
        <?php endforeach; ?>
        <div style="border-top:1px solid var(--border);padding-top:8px;margin-top:4px;font-weight:600;">
          Total open: <?= array_sum($riskCounts) ?>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ── Tables ────────────────────────────────────────────────────────────── -->
<div class="board-table-grid">

  <!-- Top 5 Risks -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-trophy-fill" style="color:#f97316;"></i> Top 5 Risks by Score</h3>
      <a href="/risk" class="btn btn-ghost btn-sm">View All</a>
    </div>
    <div class="card-body p0">
      <table class="table" style="font-size:13px;">
        <thead>
          <tr>
            <th>#</th>
            <th>Risk</th>
            <th style="text-align:center;">Score</th>
            <th>Status</th>
            <th>Owner</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($topRisks): foreach ($topRisks as $i => $r):
            $rScore = (int)$r['score'];
            if ($rScore >= 20)     { $rClr = '#ef4444'; }
            elseif ($rScore >= 15) { $rClr = '#f97316'; }
            elseif ($rScore >= 6)  { $rClr = '#f59e0b'; }
            else                   { $rClr = '#22c55e'; }
          ?>
            <tr>
              <td style="color:var(--text-muted);font-weight:700;"><?= $i + 1 ?></td>
              <td>
                <a href="/risk/<?= (int)$r['id'] ?>" class="table-link fw-500">
                  <?= Security::h($r['title']) ?>
                </a>
              </td>
              <td style="text-align:center;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:<?= $rClr ?>1a;color:<?= $rClr ?>;font-weight:800;font-size:13px;">
                  <?= $rScore ?>
                </span>
              </td>
              <td><span class="badge badge-<?= Security::h($r['status']) ?>"><?= ucfirst(Security::h($r['status'])) ?></span></td>
              <td style="font-size:12px;"><?= Security::h($r['owner'] ?? $r['owner_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" style="text-align:center;color:var(--text-light);padding:24px;">No open risks.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Framework Compliance -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-bar-chart-steps"></i> Framework Compliance</h3>
      <a href="/compliance" class="btn btn-ghost btn-sm">View All</a>
    </div>
    <div class="card-body">
      <?php if ($frameworks): foreach ($frameworks as $fw):
        $pct = min(100, max(0, (int)round($fw['compliant_pct'] ?? 0)));
        if ($pct >= 80)      { $fwClr = '#059669'; }
        elseif ($pct >= 60)  { $fwClr = '#d97706'; }
        else                  { $fwClr = '#dc2626'; }
      ?>
        <div style="margin-bottom:18px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <span style="font-size:13px;font-weight:600;"><?= Security::h($fw['name']) ?></span>
            <span style="font-size:13px;font-weight:700;color:<?= $fwClr ?>;"><?= $pct ?>%</span>
          </div>
          <div style="height:10px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $fwClr ?>;border-radius:99px;transition:width .4s ease;"></div>
          </div>
        </div>
      <?php endforeach; else: ?>
        <p style="color:var(--text-muted);font-size:13px;text-align:center;padding:16px 0;">No compliance packages configured.</p>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
(function () {
  'use strict';

  // ── 90-day trend line chart ──────────────────────────────────────────────
  var trendCtx = document.getElementById('trendChart');
  if (trendCtx && typeof Chart !== 'undefined') {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: <?= json_encode(array_values($trendLabels), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          {
            label: 'GRC Score',
            data: <?= json_encode(array_values($trendGrc)) ?>,
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99,102,241,.08)',
            borderWidth: 2,
            pointRadius: 3,
            fill: true,
            tension: 0.35,
          },
          {
            label: 'Compliance %',
            data: <?= json_encode(array_values($trendCompliance)) ?>,
            borderColor: '#059669',
            backgroundColor: 'rgba(5,150,105,.06)',
            borderWidth: 2,
            pointRadius: 3,
            fill: true,
            tension: 0.35,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: {
            beginAtZero: false,
            min: 0, max: 100,
            ticks: { callback: function(v) { return v + '%'; }, font: { size: 11 } },
            grid: { color: 'rgba(0,0,0,.04)' }
          },
          x: { ticks: { font: { size: 10 }, maxRotation: 45 }, grid: { display: false } }
        },
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 12 }, boxWidth: 12, padding: 16 } },
          tooltip: {
            callbacks: {
              label: function(ctx) { return ctx.dataset.label + ': ' + ctx.parsed.y + '%'; }
            }
          }
        }
      }
    });
  }

  // ── Risk distribution donut ──────────────────────────────────────────────
  var donutCtx = document.getElementById('riskDonut');
  if (donutCtx && typeof Chart !== 'undefined') {
    new Chart(donutCtx, {
      type: 'doughnut',
      data: {
        labels: <?= json_encode($riskLabels) ?>,
        datasets: [{
          data: <?= json_encode(array_values($riskCounts)) ?>,
          backgroundColor: <?= json_encode($riskColors) ?>,
          borderWidth: 2,
          borderColor: '#fff',
          hoverOffset: 6
        }]
      },
      options: {
        responsive: false,
        cutout: '65%',
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(ctx) {
                var total = ctx.dataset.data.reduce(function(a,b){return a+b;}, 0);
                var pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
              }
            }
          }
        }
      }
    });
  }
})();
</script>

<?php
$content      = ob_get_clean();
$pageTitle    = 'Executive Board Dashboard';
$activeModule = 'report';
$breadcrumbs  = [['Reports', '/report'], ['Board Dashboard', null]];
require AEGIS_ROOT . '/views/layout.php';
?>
