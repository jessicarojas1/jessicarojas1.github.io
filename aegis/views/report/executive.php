<?php
$breadcrumbs = $breadcrumbs ?? [['Reports', null], ['Executive', null]];
ob_start();
$scoreColor = $grcScore >= 80 ? 'var(--success)' : ($grcScore >= 60 ? 'var(--warning)' : 'var(--danger)');
$scoreLabel = $grcScore >= 80 ? 'Good' : ($grcScore >= 60 ? 'Needs Attention' : 'At Risk');
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Executive Summary</h1>
    <p class="page-subtitle">Generated <?= date('M j, Y g:ia', strtotime($generatedAt)) ?> by <?= Security::h($generatedBy) ?> &mdash; <?= Security::h($orgName) ?></p>
  </div>
  <div class="page-actions">
    <button data-print class="btn btn-secondary"><i class="bi bi-printer"></i> Print</button>
    <a href="/report" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Reports</a>
  </div>
</div>

<!-- GRC Health Score -->
<div class="card" style="margin-bottom:24px">
  <div class="card-body" style="display:flex;align-items:center;gap:32px;flex-wrap:wrap;padding:32px">
    <div style="text-align:center">
      <div style="width:120px;height:120px;border-radius:50%;background:conic-gradient(<?= $scoreColor ?> <?= $grcScore * 3.6 ?>deg, var(--border) 0deg);display:flex;align-items:center;justify-content:center;margin:0 auto 8px">
        <div style="width:90px;height:90px;border-radius:50%;background:var(--bg-card);display:flex;flex-direction:column;align-items:center;justify-content:center">
          <span style="font-size:28px;font-weight:800;color:<?= $scoreColor ?>"><?= $grcScore ?></span>
          <span style="font-size:10px;color:var(--text-muted);font-weight:500">/ 100</span>
        </div>
      </div>
      <div style="font-weight:600;color:<?= $scoreColor ?>"><?= $scoreLabel ?></div>
      <div style="font-size:12px;color:var(--text-muted)">GRC Health Score</div>
    </div>
    <div style="flex:1;min-width:200px">
      <?php $metrics=[
        ['Compliance',  $compliancePct,  'var(--primary)', '40% weight'],
        ['Risk Health', $riskHealth,     'var(--danger)',  '30% weight'],
        ['Policy',      $policyHealth,   'var(--info)',    '20% weight'],
        ['Audit',       $auditHealth,    'var(--success)', '10% weight'],
      ]; ?>
      <?php foreach ($metrics as [$label,$pct,$color,$weight]): ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
          <span style="font-weight:500"><?= $label ?></span>
          <span style="color:var(--text-muted)"><?= $weight ?> &middot; <strong style="color:<?= $color ?>"><?= $pct ?>%</strong></span>
        </div>
        <div style="height:6px;background:var(--border);border-radius:3px">
          <div style="height:100%;border-radius:3px;background:<?= $color ?>;width:<?= $pct ?>%;transition:width 0.6s ease"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="text-align:center;padding:16px 24px;background:<?= $openIncidents > 0 ? 'var(--danger-subtle)' : 'var(--bg-subtle)' ?>;border-radius:10px;border:1px solid <?= $openIncidents > 0 ? 'var(--danger-subtle)' : 'var(--border)' ?>">
        <div style="font-size:28px;font-weight:800;color:<?= $openIncidents > 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= $openIncidents ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Open Incidents</div>
      </div>
      <div style="text-align:center;padding:16px 24px;background:<?= $upcomingAudits > 0 ? 'var(--warning-subtle)' : 'var(--bg-subtle)' ?>;border-radius:10px;border:1px solid <?= $upcomingAudits > 0 ? 'var(--warning-subtle)' : 'var(--border)' ?>">
        <div style="font-size:28px;font-weight:800;color:<?= $upcomingAudits > 0 ? 'var(--warning)' : 'var(--success)' ?>"><?= $upcomingAudits ?></div>
        <div style="font-size:12px;color:var(--text-muted)">Audits Due (30d)</div>
      </div>
    </div>
  </div>
</div>

<div class="two-col-layout">
  <!-- Top risks -->
  <div class="card">
    <div class="card-header"><div class="card-header-left"><i class="bi bi-exclamation-triangle-fill" style="color:var(--danger)"></i><span class="card-title">Top Risks</span></div></div>
    <div class="card-body" style="padding:0">
      <?php if ($topRisks): ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <?php foreach ($topRisks as $risk):
          $sc = $risk['inherent_score'];
          $rc = $sc >= 20 ? 'var(--danger)' : ($sc >= 15 ? 'var(--warning)' : ($sc >= 8 ? 'var(--info)' : 'var(--success)'));
        ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:12px 16px">
            <div style="font-weight:500;margin-bottom:2px"><?= Security::h($risk['title']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= Security::h($risk['category_name'] ?? 'Uncategorized') ?></div>
          </td>
          <td style="padding:12px 16px;text-align:right;white-space:nowrap">
            <span class="status-chip" style="background:<?= $rc ?>20;color:<?= $rc ?>;font-weight:700"><?= $sc ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php else: ?>
        <p style="padding:20px;text-align:center;color:var(--text-muted)">No open risks.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Policies due for review -->
  <div class="card">
    <div class="card-header"><div class="card-header-left"><i class="bi bi-clock-fill" style="color:var(--warning)"></i><span class="card-title">Policies Due for Review</span></div></div>
    <div class="card-body" style="padding:0">
      <?php if ($reviewsDue): ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <?php foreach ($reviewsDue as $pol):
          $daysLeft = (int)round((strtotime($pol['next_review_date']) - time()) / 86400);
          $rc = $daysLeft < 0 ? 'var(--danger)' : ($daysLeft <= 7 ? 'var(--warning)' : 'var(--success)');
        ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:12px 16px">
            <div style="font-weight:500;margin-bottom:2px"><?= Security::h($pol['title']) ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= Security::h($pol['owner_name'] ?? 'Unassigned') ?></div>
          </td>
          <td style="padding:12px 16px;text-align:right;white-space:nowrap">
            <span style="font-size:12px;color:<?= $rc ?>;font-weight:600">
              <?= $daysLeft < 0 ? abs($daysLeft).'d overdue' : ($daysLeft === 0 ? 'Today' : 'in '.$daysLeft.'d') ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php else: ?>
        <p style="padding:20px;text-align:center;color:var(--text-muted)">No reviews due in the next 30 days.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
@media print {
  .sidebar,.topbar,.bottom-nav,.page-actions,.alert-panel,.alert-overlay{display:none!important}
  .main-content{margin:0!important;padding:0!important}
  .page-content{padding:0!important}
  .card{box-shadow:none!important;border:1px solid #e4e4e7!important;break-inside:avoid}
}
</style>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
