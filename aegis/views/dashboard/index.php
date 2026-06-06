<?php
$pageTitle    = 'Dashboard';
$activeModule = 'dashboard';
$breadcrumbs  = [['Dashboard', null]];

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= Security::h(explode(' ', Auth::user()['name'])[0]) ?></p>
  </div>
  <div class="page-actions">
    <a href="/risk/create" class="btn btn-danger"><i class="bi bi-plus-lg"></i> Log Risk</a>
    <a href="/audit/create" class="btn btn-primary"><i class="bi bi-clipboard2-plus"></i> New Audit</a>
  </div>
</div>

<!-- KPI Cards -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--primary),var(--secondary))">
      <i class="bi bi-shield-check"></i>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= $stats['compliance_pct'] ?>%</div>
      <div class="stat-label">Compliance Score</div>
      <div class="stat-sub"><?= $stats['compliant'] ?> of <?= $stats['controls'] ?> controls</div>
    </div>
    <div class="stat-progress">
      <div class="progress-bar" style="width:<?= $stats['compliance_pct'] ?>%;background:linear-gradient(90deg,var(--primary),var(--secondary))"></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--danger),var(--danger-dark,#991b1b))">
      <i class="bi bi-exclamation-triangle-fill"></i>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= $stats['open_risks'] ?></div>
      <div class="stat-label">Open Risks</div>
      <?php foreach ($riskDistribution as $rd):
        [$bg, $fg] = match($rd['level']) {
          'Critical' => ['var(--danger-subtle)', 'var(--danger)'],
          'High'     => ['var(--warning-subtle)', 'var(--orange)'],
          'Medium'   => ['var(--warning-subtle)', 'var(--warning)'],
          default    => ['var(--success-subtle)', 'var(--success)'],
        };
      ?>
        <span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>;border:none;font-size:11px"><?= $rd['count'] ?> <?= Security::h($rd['level']) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--success),var(--success-dark,#047857))">
      <i class="bi bi-file-earmark-text-fill"></i>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= $stats['policies'] ?></div>
      <div class="stat-label">Policies</div>
      <div class="stat-sub"><?= $stats['reviews_due'] ?> review<?= $stats['reviews_due'] != 1 ? 's' : '' ?> due soon</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--info),var(--info-dark,#0369a1))">
      <i class="bi bi-clipboard2-check-fill"></i>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= $stats['packages'] ?></div>
      <div class="stat-label">Compliance Packages</div>
      <div class="stat-sub"><?= $stats['audits_due'] ?> audit<?= $stats['audits_due'] != 1 ? 's' : '' ?> due in 30 days</div>
    </div>
  </div>
</div>

<!-- Due Items Widget -->
<?php
$totalDue = array_sum(array_map('count', $dueBuckets));
$bucketMeta = [
  'expired' => ['label'=>'Expired',       'color'=>'var(--text-muted)', 'bg'=>'var(--bg-secondary)', 'icon'=>'bi-x-circle-fill'],
  'overdue' => ['label'=>'Overdue',        'color'=>'var(--danger)',     'bg'=>'var(--danger-subtle)', 'icon'=>'bi-exclamation-octagon-fill'],
  'due7'    => ['label'=>'Due in 7 Days',  'color'=>'var(--warning)',    'bg'=>'var(--warning-subtle)', 'icon'=>'bi-exclamation-triangle-fill'],
  'due30'   => ['label'=>'Due in 30 Days', 'color'=>'var(--info)',       'bg'=>'var(--info-subtle)', 'icon'=>'bi-clock-fill'],
];
?>
<?php if ($totalDue > 0): ?>
<div class="card due-items-card" style="margin-bottom:20px">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-alarm-fill" style="color:var(--danger)"></i> Action Required</h3>
    <div class="due-filter-bar">
      <button class="due-tab active" data-bucket="all">All <span class="due-count-pill"><?= $totalDue ?></span></button>
      <?php foreach ($bucketMeta as $key => $meta): ?>
        <?php $cnt = count($dueBuckets[$key]); if (!$cnt) continue; ?>
        <button class="due-tab" data-bucket="<?= $key ?>" style="--tab-color:<?= $meta['color'] ?>">
          <?= $meta['label'] ?>
          <span class="due-count-pill" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>"><?= $cnt ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card-body p0">
    <?php foreach ($bucketMeta as $key => $meta): ?>
      <?php if (!count($dueBuckets[$key])) continue; ?>
      <div class="due-section" data-bucket="<?= $key ?>">
        <div class="due-section-header" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>">
          <i class="bi <?= $meta['icon'] ?>"></i>
          <span><?= $meta['label'] ?></span>
          <span class="due-section-count"><?= count($dueBuckets[$key]) ?> item<?= count($dueBuckets[$key]) != 1 ? 's' : '' ?></span>
        </div>
        <?php foreach ($dueBuckets[$key] as $item): ?>
          <?php
            $daysVal  = (new DateTimeImmutable($item['due_date']))->diff(new DateTimeImmutable('today'));
            $daysDiff = (int)$daysVal->format('%r%a');
            $dateLabel = $daysDiff < 0
              ? abs($daysDiff) . 'd overdue'
              : ($daysDiff === 0 ? 'Due today' : 'In ' . $daysDiff . 'd');
          ?>
          <div class="due-item" data-bucket="<?= $key ?>">
            <div class="due-item-type" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>">
              <?= Security::h($item['item_type']) ?>
            </div>
            <div class="due-item-body">
              <a href="<?= Security::h($item['url']) ?>" class="due-item-name"><?= Security::h($item['name']) ?></a>
              <?php if ($item['owner']): ?>
                <span class="due-item-owner"><i class="bi bi-person"></i> <?= Security::h($item['owner']) ?></span>
              <?php endif; ?>
            </div>
            <div class="due-item-date" style="color:<?= $meta['color'] ?>">
              <div class="due-date-label"><?= $dateLabel ?></div>
              <div class="due-date-actual"><?= date('M j, Y', strtotime($item['due_date'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Charts + Activity Row -->
<div class="dashboard-grid">
  <!-- Compliance by package chart -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-bar-chart-fill"></i> Compliance by Package</h3>
      <a href="/compliance" class="btn btn-ghost btn-sm">View All</a>
    </div>
    <div class="card-body">
      <canvas id="complianceChart" height="220"></canvas>
    </div>
  </div>

  <!-- Risk distribution donut -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-pie-chart-fill"></i> Risk Distribution</h3>
      <a href="/risk/matrix" class="btn btn-ghost btn-sm">Risk Matrix</a>
    </div>
    <div class="card-body" style="display:flex;align-items:center;justify-content:center">
      <canvas id="riskChart" height="220"></canvas>
    </div>
  </div>

  <!-- Upcoming Audits -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-calendar-check-fill"></i> Upcoming Audits</h3>
      <a href="/audit/create" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> New</a>
    </div>
    <div class="card-body p0">
      <?php if ($upcomingAudits): foreach ($upcomingAudits as $audit): ?>
        <div class="list-item">
          <div class="list-item-icon">
            <span class="status-dot <?= statusClass($audit['status']) ?>"></span>
          </div>
          <div class="list-item-body">
            <div class="list-item-title"><a href="/audit/<?= $audit['id'] ?>"><?= Security::h($audit['name']) ?></a></div>
            <div class="list-item-sub">
              <?= $audit['package_name'] ? Security::h($audit['package_name']) : 'No package' ?>
              · <?= $audit['scheduled_date'] ? date('M j, Y', strtotime($audit['scheduled_date'])) : 'No date' ?>
            </div>
          </div>
          <span class="badge badge-<?= $audit['status'] ?>"><?= ucfirst(str_replace('_',' ',$audit['status'])) ?></span>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state-sm"><i class="bi bi-calendar"></i><p>No upcoming audits</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Policy Reviews Due -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-file-earmark-check-fill"></i> Policy Reviews Due</h3>
      <a href="/policy/create" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> New</a>
    </div>
    <div class="card-body p0">
      <?php if ($policyReviews): foreach ($policyReviews as $policy): ?>
        <?php $overdue = $policy['next_review_date'] && strtotime($policy['next_review_date']) < time(); ?>
        <div class="list-item">
          <div class="list-item-icon">
            <i class="bi bi-file-earmark-text" style="color:<?= $overdue ? 'var(--danger)' : 'var(--primary)' ?>"></i>
          </div>
          <div class="list-item-body">
            <div class="list-item-title"><a href="/policy/<?= $policy['id'] ?>"><?= Security::h($policy['title']) ?></a></div>
            <div class="list-item-sub">Owner: <?= Security::h($policy['owner_name'] ?? 'Unassigned') ?></div>
          </div>
          <?php if ($policy['next_review_date']): ?>
            <span class="badge <?= $overdue ? 'badge-danger' : 'badge-warning' ?>"><?= $overdue ? 'Overdue' : date('M j', strtotime($policy['next_review_date'])) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state-sm"><i class="bi bi-file-earmark"></i><p>No reviews due</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Risks -->
  <div class="card col-span-2">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-exclamation-triangle-fill"></i> Recent Risks</h3>
      <div class="flex gap-2">
        <a href="/risk/matrix" class="btn btn-ghost btn-sm">Matrix View</a>
        <a href="/risk/create" class="btn btn-danger btn-sm"><i class="bi bi-plus"></i> Log Risk</a>
      </div>
    </div>
    <div class="card-body p0">
      <table class="table">
        <thead>
          <tr><th>Risk ID</th><th>Title</th><th>Category</th><th>Score</th><th>Level</th><th>Status</th><th>Owner</th></tr>
        </thead>
        <tbody>
          <?php if ($recentRisks): foreach ($recentRisks as $risk): ?>
            <?php $level = riskLevel($risk['inherent_score']); ?>
            <tr>
              <td><span class="mono"><?= Security::h($risk['risk_id'] ?? 'N/A') ?></span></td>
              <td><a href="/risk/<?= $risk['id'] ?>" class="table-link"><?= Security::h($risk['title']) ?></a></td>
              <td>
                <?php if ($risk['category_name']): ?>
                  <span class="tag" style="background:<?= Security::h($risk['category_color'] ?? '#666') ?>20;border-color:<?= Security::h($risk['category_color'] ?? '#666') ?>40;color:<?= Security::h($risk['category_color'] ?? '#666') ?>"><?= Security::h($risk['category_name']) ?></span>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td><strong><?= $risk['inherent_score'] ?></strong></td>
              <td><span class="risk-badge risk-<?= strtolower($level) ?>"><?= $level ?></span></td>
              <td><span class="badge badge-<?= $risk['status'] ?>"><?= ucfirst($risk['status']) ?></span></td>
              <td><?= Security::h($risk['owner_name'] ?? 'Unassigned') ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center text-muted">No risks logged yet. <a href="/risk/create">Log your first risk</a>.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script nonce="<?= Security::nonce() ?>">
// Due items tab filter
document.querySelectorAll('.due-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.due-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const bucket = btn.dataset.bucket;
    document.querySelectorAll('.due-section').forEach(s => {
      s.style.display = (bucket === 'all' || s.dataset.bucket === bucket) ? '' : 'none';
    });
  });
});

const complianceData = <?= json_encode($complianceByPackage, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const riskData = <?= json_encode($riskDistribution, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

document.addEventListener('DOMContentLoaded', () => {
  // Resolve theme-aware colors from CSS vars at runtime
  const style = getComputedStyle(document.documentElement);
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const clrText     = isDark ? 'rgba(248,250,252,0.65)' : 'rgba(30,41,59,0.65)';
  const clrGrid     = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
  const clrBg       = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)';
  const clrGreen    = style.getPropertyValue('--primary-light').trim() || '#22c55e';
  const clrYellow   = style.getPropertyValue('--warning').trim() || '#f59e0b';
  const clrRed      = style.getPropertyValue('--danger').trim() || '#ef4444';

  // Apply global Chart.js defaults for dark mode
  Chart.defaults.color = clrText;
  Chart.defaults.borderColor = clrGrid;

  // ── Compliance horizontal bar chart ──────────────────────────
  if (complianceData.length > 0) {
    const ctx = document.getElementById('complianceChart').getContext('2d');

    // Color each bar by compliance % (green ≥80, yellow ≥50, red <50)
    const pcts   = complianceData.map(p => Math.round((p.compliant / Math.max(p.total, 1)) * 100));
    const barColors = pcts.map(p => p >= 80 ? clrGreen : p >= 50 ? clrYellow : clrRed);

    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: complianceData.map(p => p.name.length > 22 ? p.name.substring(0, 22) + '…' : p.name),
        datasets: [
          {
            label: 'Compliance %',
            data: pcts,
            backgroundColor: barColors,
            borderRadius: 5,
            borderSkipped: false,
            maxBarThickness: 28,
          },
          {
            label: 'Remaining',
            data: pcts.map(p => 100 - p),
            backgroundColor: clrBg,
            borderRadius: 5,
            borderSkipped: false,
            maxBarThickness: 28,
          }
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (item) => {
                const d = complianceData[item.dataIndex];
                return item.datasetIndex === 0
                  ? ` ${item.raw}% compliant (${d.compliant} / ${d.total} controls)`
                  : ` ${item.raw}% remaining`;
              }
            }
          }
        },
        scales: {
          x: {
            stacked: true,
            min: 0, max: 100,
            ticks: { callback: v => v + '%', maxTicksLimit: 6, color: clrText },
            grid: { color: clrGrid },
          },
          y: {
            stacked: true,
            ticks: { color: clrText, font: { size: 11 } },
            grid: { display: false },
          }
        }
      }
    });
  }

  // ── Risk donut chart ──────────────────────────────────────────
  if (riskData.length > 0) {
    const riskColors = {
      Critical: style.getPropertyValue('--danger').trim() || '#ef4444',
      High: style.getPropertyValue('--orange').trim() || '#f97316',
      Medium: style.getPropertyValue('--warning').trim() || '#f59e0b',
      Low: style.getPropertyValue('--primary-light').trim() || '#22c55e'
    };
    const ctx2 = document.getElementById('riskChart').getContext('2d');
    new Chart(ctx2, {
      type: 'doughnut',
      data: {
        labels: riskData.map(r => r.level),
        datasets: [{
          data: riskData.map(r => r.count),
          backgroundColor: riskData.map(r => riskColors[r.level] || style.getPropertyValue('--text-muted').trim() || '#94a3b8'),
          borderWidth: 2,
          borderColor: isDark ? style.getPropertyValue('--bg').trim() || '#1e293b' : style.getPropertyValue('--card-bg').trim() || '#ffffff',
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: { padding: 16, boxWidth: 12, color: clrText }
          },
          tooltip: {
            callbacks: {
              label: (item) => ` ${item.label}: ${item.raw} risk${item.raw !== 1 ? 's' : ''}`
            }
          }
        }
      }
    });
  }
});
</script>

<?php
function statusClass(string $s): string {
  return match($s) { 'completed'=>'green','in_progress'=>'blue','planned'=>'gray','overdue'=>'red',default=>'gray' };
}
function riskLevel(int $score): string {
  return $score > 14 ? 'Critical' : ($score > 9 ? 'High' : ($score > 4 ? 'Medium' : 'Low'));
}
function timeAgo(string $dt): string {
  $diff = time() - strtotime($dt);
  if ($diff < 60) return 'just now';
  if ($diff < 3600) return floor($diff/60) . 'm ago';
  if ($diff < 86400) return floor($diff/3600) . 'h ago';
  return floor($diff/86400) . 'd ago';
}
?>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
