<?php ob_start(); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Compliance Status Report</h1>
    <p class="page-subtitle">Generated <?= date('M j, Y g:ia', strtotime($generatedAt)) ?> by <?= Security::h($generatedBy) ?> &mdash; <?= Security::h($orgName) ?></p>
  </div>
  <div class="page-actions">
    <button data-print class="btn btn-secondary"><i class="bi bi-printer"></i> Print</button>
    <a href="/report" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Reports</a>
  </div>
</div>

<!-- Summary stats -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--primary),var(--secondary))"><i class="bi bi-shield-check"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $overallPct ?>%</div><div class="stat-label">Overall Compliance</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="bi bi-check-circle-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $compliantCount ?></div><div class="stat-label">Compliant Controls</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c)"><i class="bi bi-x-circle-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $totalControls - $compliantCount ?></div><div class="stat-label">Gaps</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,#0284c7,#0369a1)"><i class="bi bi-collection-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= count($packages) ?></div><div class="stat-label">Active Packages</div></div>
  </div>
</div>

<!-- Per-package breakdown -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><div class="card-header-left"><i class="bi bi-bar-chart-fill" style="color:var(--primary)"></i><span class="card-title">Package Breakdown</span></div></div>
  <div class="card-body" style="padding:0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--bg-subtle)">
          <th style="padding:10px 16px;text-align:left;font-weight:600;color:var(--text-muted)">Package</th>
          <th style="padding:10px 8px;text-align:center;font-weight:600;color:#059669">Compliant</th>
          <th style="padding:10px 8px;text-align:center;font-weight:600;color:#d97706">Partial</th>
          <th style="padding:10px 8px;text-align:center;font-weight:600;color:#dc2626">Non-Compliant</th>
          <th style="padding:10px 8px;text-align:center;font-weight:600;color:var(--text-muted)">Not Started</th>
          <th style="padding:10px 16px;text-align:right;font-weight:600;color:var(--text-muted)">Score</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($packages as $pkg):
          $total = $pkg['compliant'] + $pkg['partial'] + $pkg['non_compliant'] + $pkg['not_started'];
          $pct   = $total > 0 ? round($pkg['compliant'] / $total * 100) : 0;
        ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:12px 16px;font-weight:500"><?= Security::h($pkg['name']) ?></td>
          <td style="padding:12px 8px;text-align:center;color:#059669"><?= $pkg['compliant'] ?></td>
          <td style="padding:12px 8px;text-align:center;color:#d97706"><?= $pkg['partial'] ?></td>
          <td style="padding:12px 8px;text-align:center;color:#dc2626"><?= $pkg['non_compliant'] ?></td>
          <td style="padding:12px 8px;text-align:center;color:var(--text-muted)"><?= $pkg['not_started'] ?></td>
          <td style="padding:12px 16px;text-align:right">
            <span style="font-weight:700;color:<?= $pct>=80?'#059669':($pct>=50?'#d97706':'#dc2626') ?>"><?= $pct ?>%</span>
            <div style="height:4px;background:var(--border);border-radius:2px;margin-top:4px;width:80px;margin-left:auto">
              <div style="height:100%;border-radius:2px;background:<?= $pct>=80?'#059669':($pct>=50?'#d97706':'#dc2626') ?>;width:<?= $pct ?>%"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Non-compliant items -->
<?php if ($nonCompliantItems): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><div class="card-header-left"><i class="bi bi-x-circle-fill" style="color:#dc2626"></i><span class="card-title">Non-Compliant Controls</span></div></div>
  <div class="card-body" style="padding:0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--bg-subtle)">
          <th style="padding:10px 16px;text-align:left;font-weight:600;color:var(--text-muted)">Code</th>
          <th style="padding:10px 8px;text-align:left;font-weight:600;color:var(--text-muted)">Control</th>
          <th style="padding:10px 8px;text-align:left;font-weight:600;color:var(--text-muted)">Package</th>
          <th style="padding:10px 8px;text-align:left;font-weight:600;color:var(--text-muted)">Assigned</th>
          <th style="padding:10px 16px;text-align:right;font-weight:600;color:var(--text-muted)">Due</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($nonCompliantItems as $item): ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:10px 16px;font-family:monospace;font-size:12px;white-space:nowrap"><?= Security::h($item['code']) ?></td>
          <td style="padding:10px 8px"><?= Security::h($item['title']) ?></td>
          <td style="padding:10px 8px;color:var(--text-muted)"><?= Security::h($item['package_name']) ?></td>
          <td style="padding:10px 8px"><?= Security::h($item['assigned_to_name'] ?? '—') ?></td>
          <td style="padding:10px 16px;text-align:right;white-space:nowrap"><?= $item['due_date'] ? date('M j, Y', strtotime($item['due_date'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Recent changes -->
<?php if ($recentChanges): ?>
<div class="card">
  <div class="card-header"><div class="card-header-left"><i class="bi bi-activity" style="color:var(--primary)"></i><span class="card-title">Recent Activity</span></div></div>
  <div class="card-body" style="padding:0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <tbody>
        <?php foreach ($recentChanges as $ch): ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:10px 16px;width:160px;white-space:nowrap;color:var(--text-muted)"><?= date('M j, Y g:ia', strtotime($ch['created_at'])) ?></td>
          <td style="padding:10px 8px;font-weight:500"><?= Security::h($ch['user_name'] ?? 'System') ?></td>
          <td style="padding:10px 16px;color:var(--text-muted)"><?= Security::h($ch['action']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<style>
@media print {
  .sidebar,.topbar,.bottom-nav,.page-actions,.alert-panel,.alert-overlay{display:none!important}
  .main-content{margin:0!important;padding:0!important}
  .page-content{padding:0!important}
  .card{box-shadow:none!important;border:1px solid #e4e4e7!important;break-inside:avoid}
}
</style>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
