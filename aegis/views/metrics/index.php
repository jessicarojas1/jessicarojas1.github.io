<?php
$pageTitle    = 'Metrics';
$activeModule = 'metrics';
$breadcrumbs  = [['Metrics', null]];
ob_start();
?>

<div class="page-header">
  <h1 class="page-title">GRC Metrics</h1>
  <div class="page-actions">
    <a href="/export" class="btn btn-ghost"><i class="bi bi-download"></i> Export Data</a>
  </div>
</div>

<!-- KPI Row -->
<div class="metrics-kpi-row">

  <div class="metrics-kpi">
    <div class="mkpi-icon" style="background:#eef2ff;color:#4f46e5"><i class="bi bi-shield-check"></i></div>
    <div class="mkpi-body">
      <div class="mkpi-value"><?= $kpi['compliance_pct'] ?>%</div>
      <div class="mkpi-label">Overall Compliance</div>
    </div>
    <svg class="mkpi-ring" viewBox="0 0 36 36">
      <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#e2e8f0" stroke-width="3"/>
      <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#4f46e5" stroke-width="3" stroke-dasharray="<?= $kpi['compliance_pct'] ?>, 100" stroke-linecap="round"/>
    </svg>
  </div>

  <div class="metrics-kpi">
    <div class="mkpi-icon" style="background:#fee2e2;color:#dc2626"><i class="bi bi-exclamation-triangle-fill"></i></div>
    <div class="mkpi-body">
      <div class="mkpi-value"><?= $kpi['open_risks'] ?></div>
      <div class="mkpi-label">Open Risks</div>
      <div class="mkpi-sub"><?= $kpi['critical_risks'] ?> critical</div>
    </div>
  </div>

  <div class="metrics-kpi">
    <div class="mkpi-icon" style="background:#dbeafe;color:#0284c7"><i class="bi bi-clipboard2-check-fill"></i></div>
    <div class="mkpi-body">
      <div class="mkpi-value"><?= $kpi['audit_completion'] ?>%</div>
      <div class="mkpi-label">Audit Completion</div>
      <div class="mkpi-sub"><?= $kpi['completed_audits'] ?> of <?= $kpi['total_audits'] ?></div>
    </div>
  </div>

  <div class="metrics-kpi">
    <div class="mkpi-icon" style="background:#d1fae5;color:#059669"><i class="bi bi-file-earmark-text-fill"></i></div>
    <div class="mkpi-body">
      <div class="mkpi-value"><?= $kpi['published_policies'] ?></div>
      <div class="mkpi-label">Published Policies</div>
      <div class="mkpi-sub"><?= $kpi['total_policies'] ?> total</div>
    </div>
  </div>

</div>

<!-- Charts Row 1 -->
<div class="metrics-grid">

  <!-- Compliance by Package (stacked bar) -->
  <div class="card metrics-card-wide">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-bar-chart-steps"></i> Compliance by Package</h3>
      <a href="/compliance" class="btn btn-ghost btn-sm">View All</a>
    </div>
    <div class="card-body"><canvas id="pkgCompChart" height="260"></canvas></div>
  </div>

  <!-- Control Status (doughnut) -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-pie-chart-fill"></i> Control Status</h3></div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center"><canvas id="ctrlStatusChart" height="260"></canvas></div>
  </div>

</div>

<!-- Charts Row 2 -->
<div class="metrics-grid">

  <!-- Risk by category (horizontal bar) -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-layers-fill"></i> Risk by Category</h3>
      <a href="/risk" class="btn btn-ghost btn-sm">Register</a>
    </div>
    <div class="card-body"><canvas id="riskCatChart" height="280"></canvas></div>
  </div>

  <!-- Risk trend (line) -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-graph-up-arrow"></i> Risk Intake Trend (12 mo)</h3></div>
    <div class="card-body"><canvas id="riskTrendChart" height="280"></canvas></div>
  </div>

  <!-- Audit score trend (line) -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-award-fill"></i> Audit Score Trend (12 mo)</h3></div>
    <div class="card-body"><canvas id="auditTrendChart" height="280"></canvas></div>
  </div>

</div>

<!-- Tables Row -->
<div class="two-col-layout" style="margin-top:20px">

  <!-- Top open risks -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-fire"></i> Top Open Risks</h3></div>
    <div class="card-body p0">
      <table class="table">
        <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Score</th><th>Residual</th></tr></thead>
        <tbody>
          <?php if ($topRisks): foreach ($topRisks as $r): ?>
            <?php $lvl = $r['inherent_score'] > 14 ? 'Critical' : ($r['inherent_score'] > 9 ? 'High' : ($r['inherent_score'] > 4 ? 'Medium' : 'Low')); ?>
            <tr>
              <td><span class="mono"><?= Security::h($r['risk_id'] ?? '—') ?></span></td>
              <td><?= Security::h($r['title']) ?></td>
              <td>
                <?php if ($r['category']): ?>
                  <span class="tag" style="background:<?= $r['color'] ?>20;color:<?= $r['color'] ?>"><?= Security::h($r['category']) ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><span class="risk-badge risk-<?= strtolower($lvl) ?>"><?= $r['inherent_score'] ?></span></td>
              <td class="text-muted"><?= $r['residual_score'] ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="empty-row">No open risks</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Audit stats by type -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-clipboard2-data-fill"></i> Audit Performance by Type</h3></div>
    <div class="card-body p0">
      <table class="table">
        <thead><tr><th>Type</th><th>Total</th><th>Completed</th><th>Avg Score</th></tr></thead>
        <tbody>
          <?php if ($auditStats): foreach ($auditStats as $a): ?>
            <tr>
              <td><?= ucfirst(str_replace('_',' ', Security::h($a['audit_type']))) ?></td>
              <td><?= $a['total'] ?></td>
              <td>
                <?= $a['completed'] ?>
                <small class="text-muted">(<?= $a['total'] > 0 ? round($a['completed']/$a['total']*100) : 0 ?>%)</small>
              </td>
              <td><?= $a['avg_score'] ? $a['avg_score'] . '%' : '—' ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="4" class="empty-row">No audits yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
const pkgData      = <?= json_encode($complianceByPackage) ?>;
const ctrlData     = <?= json_encode($controlStatus) ?>;
const riskCatData  = <?= json_encode($riskByCategory) ?>;
const riskTrend    = <?= json_encode($riskTrend) ?>;
const auditTrend   = <?= json_encode($auditTrend) ?>;

const STATUS_COLORS = {
  compliant:      '#22c55e',
  non_compliant:  '#ef4444',
  partial:        '#f59e0b',
  not_started:    '#94a3b8',
  not_applicable: '#cbd5e1',
};

document.addEventListener('DOMContentLoaded', () => {

  // Compliance by package — stacked bar
  if (pkgData.length) {
    new Chart(document.getElementById('pkgCompChart'), {
      type: 'bar',
      data: {
        labels: pkgData.map(p => p.name.length > 22 ? p.name.slice(0,22)+'…' : p.name),
        datasets: [
          { label:'Compliant',     data: pkgData.map(p=>+p.compliant),     backgroundColor:'#22c55e', borderRadius:4 },
          { label:'Partial',       data: pkgData.map(p=>+p.partial),       backgroundColor:'#f59e0b', borderRadius:4 },
          { label:'Non-compliant', data: pkgData.map(p=>+p.non_compliant), backgroundColor:'#ef4444', borderRadius:4 },
          { label:'Not started',   data: pkgData.map(p=>+p.not_started),   backgroundColor:'#e2e8f0', borderRadius:4 },
        ]
      },
      options: { responsive:true, maintainAspectRatio:false,
        scales: { x:{ stacked:true, grid:{display:false} }, y:{ stacked:true, beginAtZero:true } },
        plugins: { legend:{ position:'top', labels:{boxWidth:12} } }
      }
    });
  }

  // Control status doughnut
  if (ctrlData.length) {
    const labels = ctrlData.map(c => (c.status||'not_started').replace('_',' '));
    const colors = ctrlData.map(c => STATUS_COLORS[c.status] || '#94a3b8');
    new Chart(document.getElementById('ctrlStatusChart'), {
      type: 'doughnut',
      data: { labels, datasets: [{ data: ctrlData.map(c=>+c.count), backgroundColor: colors, borderWidth:0, hoverOffset:8 }] },
      options: { responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{ legend:{ position:'bottom', labels:{boxWidth:12} } } }
    });
  }

  // Risk by category — horizontal bar
  if (riskCatData.length) {
    new Chart(document.getElementById('riskCatChart'), {
      type: 'bar',
      data: {
        labels: riskCatData.map(r => r.name || 'Uncategorized'),
        datasets: [
          { label:'Critical', data: riskCatData.map(r=>+r.critical), backgroundColor:'#ef4444', borderRadius:4 },
          { label:'High',     data: riskCatData.map(r=>+r.high),     backgroundColor:'#f97316', borderRadius:4 },
          { label:'Medium',   data: riskCatData.map(r=>+r.medium),   backgroundColor:'#f59e0b', borderRadius:4 },
          { label:'Low',      data: riskCatData.map(r=>+r.low),      backgroundColor:'#22c55e', borderRadius:4 },
        ]
      },
      options: {
        indexAxis:'y', responsive:true, maintainAspectRatio:false,
        scales: { x:{ stacked:true, beginAtZero:true, grid:{display:false} }, y:{ stacked:true } },
        plugins: { legend:{ position:'top', labels:{boxWidth:12} } }
      }
    });
  }

  // Risk intake trend — line
  if (riskTrend.length) {
    new Chart(document.getElementById('riskTrendChart'), {
      type: 'line',
      data: {
        labels: riskTrend.map(r => r.month),
        datasets: [
          { label:'Total Risks', data: riskTrend.map(r=>+r.total), borderColor:'#4f46e5', backgroundColor:'#4f46e520', fill:true, tension:.35, pointRadius:4 },
          { label:'Critical',    data: riskTrend.map(r=>+r.critical), borderColor:'#ef4444', backgroundColor:'transparent', tension:.35, pointRadius:4 },
        ]
      },
      options: { responsive:true, maintainAspectRatio:false,
        scales: { x:{ grid:{display:false} }, y:{ beginAtZero:true } },
        plugins: { legend:{ position:'top', labels:{boxWidth:12} } }
      }
    });
  }

  // Audit score trend — line
  const atCtx = document.getElementById('auditTrendChart');
  if (auditTrend.length) {
    new Chart(atCtx, {
      type: 'line',
      data: {
        labels: auditTrend.map(a => a.month),
        datasets: [{
          label: 'Avg Score (%)',
          data: auditTrend.map(a=>+a.avg_score),
          borderColor:'#0284c7', backgroundColor:'#0284c720', fill:true, tension:.35, pointRadius:4,
        }]
      },
      options: { responsive:true, maintainAspectRatio:false,
        scales: { x:{ grid:{display:false} }, y:{ beginAtZero:true, max:100 } },
        plugins: { legend:{ display:false } }
      }
    });
  } else {
    atCtx.closest('.card-body').innerHTML = '<div class="empty-state-sm"><i class="bi bi-bar-chart"></i><p>Complete audits to see score trends</p></div>';
  }

});
</script>

<style>
.metrics-kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
@media(max-width:900px) { .metrics-kpi-row { grid-template-columns:repeat(2,1fr); } }

.metrics-kpi {
  background:var(--card-bg); border:1px solid var(--border); border-radius:var(--radius);
  padding:20px; display:flex; align-items:center; gap:16px; box-shadow:var(--shadow); position:relative;
}
.mkpi-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; }
.mkpi-body { flex:1; }
.mkpi-value { font-size:28px; font-weight:800; line-height:1; }
.mkpi-label { font-size:12px; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; margin-top:4px; }
.mkpi-sub   { font-size:11px; color:var(--text-muted); }
.mkpi-ring  { width:44px; height:44px; flex-shrink:0; }

.metrics-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:20px; }
.metrics-card-wide { grid-column:span 2; }
@media(max-width:1100px) { .metrics-grid { grid-template-columns:1fr 1fr; } .metrics-card-wide { grid-column:span 2; } }
@media(max-width:700px)  { .metrics-grid { grid-template-columns:1fr; } .metrics-card-wide { grid-column:span 1; } }
</style>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
