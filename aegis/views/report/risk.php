<?php
$breadcrumbs = $breadcrumbs ?? [['Reports', null], ['Risk Report', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Register Report</h1>
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
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--primary),var(--secondary))"><i class="bi bi-list-check"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">Total Risks</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--danger),var(--danger))"><i class="bi bi-exclamation-octagon-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['critical'] ?></div><div class="stat-label">Critical</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--warning),var(--warning-dark))"><i class="bi bi-exclamation-triangle-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['high'] ?></div><div class="stat-label">High</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--success),var(--success-dark))"><i class="bi bi-check-circle-fill"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $stats['low'] + $stats['medium'] ?></div><div class="stat-label">Medium / Low</div></div>
  </div>
</div>

<!-- Risk register table -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><div class="card-header-left"><i class="bi bi-table" style="color:var(--primary)"></i><span class="card-title">Risk Register</span></div></div>
  <div class="card-body" style="padding:0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--bg-subtle)">
          <th scope="col" style="padding:10px 16px;text-align:left;font-weight:600;color:var(--text-muted)">Risk</th>
          <th scope="col" style="padding:10px 8px;text-align:left;font-weight:600;color:var(--text-muted)">Category</th>
          <th scope="col" style="padding:10px 8px;text-align:left;font-weight:600;color:var(--text-muted)">Owner</th>
          <th scope="col" style="padding:10px 8px;text-align:center;font-weight:600;color:var(--text-muted)">Score</th>
          <th scope="col" style="padding:10px 8px;text-align:center;font-weight:600;color:var(--text-muted)">Treatments</th>
          <th scope="col" style="padding:10px 16px;text-align:left;font-weight:600;color:var(--text-muted)">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($risks as $r):
          $sc = (int)$r['inherent_score'];
          $rc = $sc >= 20 ? 'var(--danger)' : ($sc >= 15 ? 'var(--warning)' : ($sc >= 8 ? 'var(--info)' : 'var(--success)'));
          $stColors=['open'=>'var(--danger)','in_treatment'=>'var(--warning)','accepted'=>'var(--secondary)','closed'=>'var(--text-muted)'];
          $stc = $stColors[$r['status']] ?? 'var(--text-muted)';
        ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:12px 16px">
            <div style="font-weight:500"><?= Security::h($r['title']) ?></div>
            <?php if ($r['description']): ?>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= Security::h(mb_substr($r['description'], 0, 80)) ?><?= mb_strlen($r['description']) > 80 ? '…' : '' ?></div>
            <?php endif; ?>
          </td>
          <td style="padding:12px 8px;color:var(--text-muted)"><?= Security::h($r['category_name'] ?? '—') ?></td>
          <td style="padding:12px 8px"><?= Security::h($r['owner_name'] ?? '—') ?></td>
          <td style="padding:12px 8px;text-align:center">
            <span class="status-chip" style="background:<?= $rc ?>20;color:<?= $rc ?>;font-weight:700"><?= $sc ?></span>
          </td>
          <td style="padding:12px 8px;text-align:center;color:var(--text-muted)"><?= $r['treatment_count'] ?></td>
          <td style="padding:12px 16px">
            <span class="status-chip" style="background:<?= $stc ?>20;color:<?= $stc ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Open treatment actions -->
<?php if ($openTreatments): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><div class="card-header-left"><i class="bi bi-tools" style="color:var(--warning)"></i><span class="card-title">Open Treatment Actions</span></div></div>
  <div class="card-body" style="padding:0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--bg-subtle)">
          <th scope="col" style="padding:10px 16px;text-align:left;font-weight:600;color:var(--text-muted)">Risk</th>
          <th scope="col" style="padding:10px 8px;text-align:left;font-weight:600;color:var(--text-muted)">Treatment</th>
          <th scope="col" style="padding:10px 8px;text-align:left;font-weight:600;color:var(--text-muted)">Owner</th>
          <th scope="col" style="padding:10px 8px;text-align:center;font-weight:600;color:var(--text-muted)">Status</th>
          <th scope="col" style="padding:10px 16px;text-align:right;font-weight:600;color:var(--text-muted)">Due</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($openTreatments as $t):
          $tColors=['planned'=>'var(--text-muted)','in_progress'=>'var(--warning)','completed'=>'var(--success)','cancelled'=>'var(--text-muted)'];
          $tc = $tColors[$t['status']] ?? 'var(--text-muted)';
          $overdue = $t['due_date'] && strtotime($t['due_date']) < time();
        ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:10px 16px;color:var(--text-muted)"><?= Security::h($t['risk_title']) ?></td>
          <td style="padding:10px 8px;font-weight:500"><?= Security::h($t['description'] ?? '—') ?></td>
          <td style="padding:10px 8px"><?= Security::h($t['owner_name'] ?? '—') ?></td>
          <td style="padding:10px 8px;text-align:center">
            <span class="status-chip" style="background:<?= $tc ?>20;color:<?= $tc ?>"><?= ucfirst(str_replace('_',' ',$t['status'])) ?></span>
          </td>
          <td style="padding:10px 16px;text-align:right;white-space:nowrap;color:<?= $overdue?'var(--danger)':'inherit' ?>">
            <?= $t['due_date'] ? date('M j, Y', strtotime($t['due_date'])) : '—' ?>
            <?= $overdue ? ' <i class="bi bi-exclamation-circle-fill" style="color:var(--danger)"></i>' : '' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- By category -->
<?php if ($byCategory): ?>
<div class="card">
  <div class="card-header"><div class="card-header-left"><i class="bi bi-pie-chart-fill" style="color:var(--primary)"></i><span class="card-title">Risks by Category</span></div></div>
  <div class="card-body" style="padding:0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <tbody>
        <?php foreach ($byCategory as $cat): ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:10px 16px;font-weight:500"><?= Security::h($cat['name']) ?></td>
          <td style="padding:10px 8px;width:60px;text-align:center;font-weight:700"><?= $cat['count'] ?></td>
          <td style="padding:10px 16px">
            <div style="height:6px;background:var(--border);border-radius:3px">
              <?php $maxCount = max(array_column($byCategory, 'count')); ?>
              <div style="height:100%;border-radius:3px;background:var(--primary);width:<?= $maxCount > 0 ? round($cat['count']/$maxCount*100) : 0 ?>%"></div>
            </div>
          </td>
          <td style="padding:10px 16px;text-align:right;color:var(--text-muted);width:120px">Avg score: <?= round($cat['avg_score'] ?? 0, 1) ?></td>
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
  .card{box-shadow:none!important;border:1px solid var(--neutral-border)!important;break-inside:avoid}
}
</style>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
