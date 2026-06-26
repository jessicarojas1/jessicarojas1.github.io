<?php
// Risk Register Report view
// Variables provided by ReportController::riskDetail():
//   $risks (array), $pageTitle, plus $_GET['status'] and $_GET['level'] for filter context

$pageTitle    = $pageTitle ?? 'Risk Detail Report';
$activeModule = 'report';
$breadcrumbs  = [['Reports', '/report'], ['Risk Detail', null]];
$risks        = $risks        ?? [];
$filterStatus = Security::sanitizeInput($_GET['status'] ?? '');
$filterLevel  = Security::sanitizeInput($_GET['level']  ?? '');
$reportDate   = date('F j, Y');
$orgName      = Database::fetchOne("SELECT value FROM settings WHERE key='org_name'")['value'] ?? 'Organisation';

// Summary counts
$countTotal    = count($risks);
$countCritical = 0; $countHigh = 0; $countMedium = 0; $countLow = 0;
foreach ($risks as $r) {
    $sc = (int)($r['inherent_score'] ?? 0);
    if ($sc > 14)        $countCritical++;
    elseif ($sc >= 10)   $countHigh++;
    elseif ($sc >= 6)    $countMedium++;
    else                 $countLow++;
}

$nonce = Security::nonce();

// Filter description for header
$filterDesc = '';
if ($filterLevel)  $filterDesc .= ' · Level: ' . ucfirst($filterLevel);
if ($filterStatus) $filterDesc .= ' · Status: ' . ucfirst(str_replace('_', ' ', $filterStatus));
?>
<style nonce="<?= $nonce ?>">
/* ── Risk Detail Report Styles ───────────────────────────────────────────── */
.rd-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 24px;
}
.rd-header-left h2 {
  font-size: 22px;
  font-weight: 800;
  color: var(--text);
  margin: 0 0 4px;
}
.rd-header-left p {
  font-size: 13px;
  color: var(--text-muted);
  margin: 0;
}
.rd-print-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--primary);
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 9px 18px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
}
.rd-print-btn:hover { background: var(--primary); }

.rd-stat-row {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
  margin-bottom: 24px;
}
@media (max-width: 900px) { .rd-stat-row { grid-template-columns: repeat(3,1fr); } }
@media (max-width: 560px) { .rd-stat-row { grid-template-columns: repeat(2,1fr); } }

.rd-stat {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 16px 14px;
  text-align: center;
}
.rd-stat .val { font-size: 28px; font-weight: 800; line-height: 1; margin: 6px 0 5px; display: block; }
.rd-stat .lbl { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); }

.rd-table-wrap {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 24px;
}
.rd-table-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  background: var(--primary);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: .03em;
  text-transform: uppercase;
}
.rd-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}
.rd-table thead th {
  padding: 9px 10px;
  text-align: left;
  font-weight: 700;
  color: var(--text-muted);
  background: var(--bg-secondary);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.rd-table thead th.center { text-align: center; }
.rd-table tbody tr { border-bottom: 1px solid var(--border-light); }
.rd-table tbody tr:hover { background: var(--bg-secondary); }
.rd-table tbody td { padding: 9px 10px; vertical-align: top; }
.rd-table tbody td.center { text-align: center; }

.score-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 26px;
  border-radius: 6px;
  font-weight: 800;
  font-size: 12px;
  padding: 0 5px;
}
.level-pill {
  display: inline-block;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 99px;
  letter-spacing: .04em;
  text-transform: uppercase;
}
.status-chip2 {
  display: inline-block;
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 99px;
}

.rd-footer {
  display: none;
  text-align: center;
  font-size: 11px;
  color: var(--text-light);
  border-top: 1px solid var(--border);
  padding-top: 12px;
  margin-top: 32px;
}

/* ── Print ───────────────────────────────────────────────────────────────── */
@media print {
  .sidebar, .topbar, .bottom-nav, .page-actions,
  .alert-panel, .alert-overlay, .sidebar-toggle,
  .btn-logout, .alert-bell, .rd-print-btn,
  .page-header { display: none !important; }
  .main-content { margin: 0 !important; padding: 0 !important; }
  .page-content { padding: 0 !important; }
  body          { font-size: 10px; background: #fff; }
  .rd-table-wrap { page-break-inside: avoid; box-shadow: none; border: 1px solid #d4d4d8; }
  .rd-table      { font-size: 10px; }
  .rd-stat-row   { grid-template-columns: repeat(5,1fr); gap: 8px; }
  .rd-stat       { padding: 10px; }
  .rd-stat .val  { font-size: 20px; }
  a              { color: inherit !important; text-decoration: none !important; }
  .rd-footer     { display: block !important; }
}
</style>

<!-- Screen page header -->
<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-table" style="margin-right:8px;color:var(--primary);"></i><?= Security::h($pageTitle) ?></h1>
    <p class="page-subtitle"><?= Security::h($orgName) ?> &mdash; <?= Security::h($reportDate) ?><?= Security::h($filterDesc) ?></p>
  </div>
  <div class="page-actions">
    <a href="/report/board-pack" class="btn btn-ghost btn-sm"><i class="bi bi-briefcase"></i> Board Pack</a>
    <button class="rd-print-btn" data-print><i class="bi bi-printer"></i> Print / Export PDF</button>
  </div>
</div>

<!-- Print-only doc header -->
<div style="display:none;" class="rd-print-only">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid var(--primary);">
    <i class="bi bi-shield-fill-check" style="font-size:28px;color:var(--primary);"></i>
    <div>
      <div style="font-size:18px;font-weight:800;color:var(--text);">AEGIS GRC &mdash; Risk Register Report</div>
      <div style="font-size:12px;color:var(--text-muted);"><?= Security::h($orgName) ?> &middot; <?= Security::h($reportDate) ?><?= Security::h($filterDesc) ?></div>
    </div>
    <div style="margin-left:auto;border:2px solid var(--primary);color:var(--primary);font-size:10px;font-weight:800;letter-spacing:.15em;padding:3px 10px;border-radius:4px;">CONFIDENTIAL</div>
  </div>
</div>

<?php if ($filterLevel || $filterStatus): ?>
<div style="background:rgba(55,65,81,.08);border:1px solid var(--border);border-radius:8px;padding:10px 16px;margin-bottom:20px;font-size:13px;color:var(--text-muted);">
  <i class="bi bi-funnel-fill"></i> <strong>Filter applied:</strong>
  <?php if ($filterLevel):  ?> Level: <strong><?= Security::h(ucfirst($filterLevel)) ?></strong><?php endif; ?>
  <?php if ($filterStatus): ?> &nbsp;Status: <strong><?= Security::h(ucfirst(str_replace('_',' ',$filterStatus))) ?></strong><?php endif; ?>
  &nbsp;<a href="/report/risk-detail" style="color:var(--secondary);font-weight:600;">Clear filters</a>
</div>
<?php endif; ?>

<!-- ── Summary Stats ───────────────────────────────────────────────────────── -->
<div class="rd-stat-row">
  <div class="rd-stat" style="border-top:4px solid var(--primary);">
    <span class="val" style="color:var(--primary);"><?= $countTotal ?></span>
    <div class="lbl">Total Risks</div>
  </div>
  <div class="rd-stat" style="border-top:4px solid var(--danger);">
    <span class="val" style="color:var(--danger);"><?= $countCritical ?></span>
    <div class="lbl">Critical</div>
  </div>
  <div class="rd-stat" style="border-top:4px solid var(--orange);">
    <span class="val" style="color:var(--orange);"><?= $countHigh ?></span>
    <div class="lbl">High</div>
  </div>
  <div class="rd-stat" style="border-top:4px solid var(--warning);">
    <span class="val" style="color:var(--warning);"><?= $countMedium ?></span>
    <div class="lbl">Medium</div>
  </div>
  <div class="rd-stat" style="border-top:4px solid var(--success);">
    <span class="val" style="color:var(--success);"><?= $countLow ?></span>
    <div class="lbl">Low</div>
  </div>
</div>

<!-- ── Filter Controls (screen only) ─────────────────────────────────────── -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;align-items:center;" class="no-print">
  <span style="font-size:13px;font-weight:600;color:var(--text-muted);">Filter:</span>
  <a href="/report/risk-detail"
     style="font-size:12px;padding:5px 14px;border-radius:99px;background:<?= (!$filterLevel && !$filterStatus) ? 'var(--primary)' : 'var(--bg-subtle)' ?>;color:<?= (!$filterLevel && !$filterStatus) ? 'var(--card-bg)' : 'var(--text-muted)' ?>;text-decoration:none;font-weight:600;">All</a>
  <a href="/report/risk-detail?level=critical"
     style="font-size:12px;padding:5px 14px;border-radius:99px;background:<?= $filterLevel === 'critical' ? 'var(--danger)' : 'var(--danger-subtle)' ?>;color:<?= $filterLevel === 'critical' ? 'var(--card-bg)' : 'var(--danger)' ?>;text-decoration:none;font-weight:600;">Critical</a>
  <a href="/report/risk-detail?level=high"
     style="font-size:12px;padding:5px 14px;border-radius:99px;background:<?= $filterLevel === 'high' ? 'var(--orange)' : 'var(--warning-subtle)' ?>;color:<?= $filterLevel === 'high' ? 'var(--card-bg)' : 'var(--orange)' ?>;text-decoration:none;font-weight:600;">High</a>
  <a href="/report/risk-detail?status=open"
     style="font-size:12px;padding:5px 14px;border-radius:99px;background:<?= $filterStatus === 'open' ? 'var(--info)' : 'var(--info-subtle)' ?>;color:<?= $filterStatus === 'open' ? 'var(--card-bg)' : 'var(--info)' ?>;text-decoration:none;font-weight:600;">Open</a>
  <a href="/report/risk-detail?status=in_treatment"
     style="font-size:12px;padding:5px 14px;border-radius:99px;background:<?= $filterStatus === 'in_treatment' ? 'var(--warning)' : 'var(--warning-subtle)' ?>;color:<?= $filterStatus === 'in_treatment' ? 'var(--card-bg)' : 'var(--warning)' ?>;text-decoration:none;font-weight:600;">In Treatment</a>
  <a href="/report/risk-detail?status=accepted"
     style="font-size:12px;padding:5px 14px;border-radius:99px;background:<?= $filterStatus === 'accepted' ? 'var(--secondary)' : 'rgba(55,65,81,.05)' ?>;color:<?= $filterStatus === 'accepted' ? 'var(--card-bg)' : 'var(--secondary)' ?>;text-decoration:none;font-weight:600;">Accepted</a>
</div>

<!-- ── Risk Register Table ────────────────────────────────────────────────── -->
<div class="rd-table-wrap">
  <div class="rd-table-header"><i class="bi bi-table"></i> Risk Register <?= $filterDesc ? '&mdash;' . Security::h($filterDesc) : '' ?></div>
  <?php if ($risks): ?>
  <div style="overflow-x:auto;">
  <table class="rd-table">
    <thead>
      <tr>
        <th scope="col">Risk ID</th>
        <th scope="col">Title</th>
        <th scope="col">Category</th>
        <th scope="col" class="center">L</th>
        <th scope="col" class="center">I</th>
        <th scope="col" class="center">Score</th>
        <th scope="col" class="center">Level</th>
        <th scope="col" class="center">Residual</th>
        <th scope="col">Status</th>
        <th scope="col">Strategy</th>
        <th scope="col">Owner</th>
        <th scope="col">Review Date</th>
        <th scope="col" class="center">Open Treatments</th>
        <th scope="col" class="center">Linked Controls</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($risks as $r):
        $sc      = (int)($r['inherent_score'] ?? 0);
        $res     = (int)($r['residual_score'] ?? 0);
        $lik     = (int)($r['likelihood']     ?? 0);
        $imp     = (int)($r['impact']         ?? 0);

        if ($sc > 14)       { $sc_bg = 'var(--danger-subtle)';  $sc_cl = 'var(--danger)';  $lvl = 'CRITICAL'; $lvl_bg = 'var(--danger-subtle)';  $lvl_cl = 'var(--danger)';  }
        elseif ($sc >= 10)  { $sc_bg = 'var(--warning-subtle)'; $sc_cl = 'var(--orange)';  $lvl = 'HIGH';     $lvl_bg = 'var(--warning-subtle)'; $lvl_cl = 'var(--orange)';  }
        elseif ($sc >= 6)   { $sc_bg = 'var(--warning-subtle)'; $sc_cl = 'var(--warning)'; $lvl = 'MEDIUM';   $lvl_bg = 'var(--warning-subtle)'; $lvl_cl = 'var(--warning)'; }
        else                { $sc_bg = 'var(--success-subtle)'; $sc_cl = 'var(--success)'; $lvl = 'LOW';      $lvl_bg = 'var(--success-subtle)'; $lvl_cl = 'var(--success)'; }

        if ($res > 14)      { $res_bg = 'var(--danger-subtle)';  $res_cl = 'var(--danger)';  }
        elseif ($res >= 10) { $res_bg = 'var(--warning-subtle)'; $res_cl = 'var(--orange)';  }
        elseif ($res >= 6)  { $res_bg = 'var(--warning-subtle)'; $res_cl = 'var(--warning)'; }
        else                { $res_bg = 'var(--success-subtle)'; $res_cl = 'var(--success)'; }

        $statusColors = ['open' => ['var(--info-subtle)','var(--info)'], 'in_treatment' => ['var(--warning-subtle)','var(--warning)'],
                         'accepted' => ['rgba(55,65,81,.05)','var(--text-muted)'], 'closed' => ['var(--surface-alt)','var(--text-muted)'],
                         'transferred' => ['var(--success-subtle)','var(--success)']];
        $stColor = $statusColors[$r['status'] ?? ''] ?? ['#f9fafb','#52525b'];

        $strategy  = Security::h($r['treatment_strategy'] ?? $r['strategy'] ?? '—');
        $reviewDt  = $r['review_date'] ? date('j M Y', strtotime($r['review_date'])) : '—';
        $isOverdue = $r['review_date'] && strtotime($r['review_date']) < time();
        $openTx    = (int)($r['open_treatments'] ?? 0);
        $ctrlCnt   = (int)($r['control_count']   ?? 0);
      ?>
      <tr>
        <td style="font-weight:700;color:var(--primary);white-space:nowrap;font-size:11px;"><?= Security::h($r['risk_id'] ?? '#' . $r['id']) ?></td>
        <td style="max-width:200px;">
          <div style="font-weight:600;color:var(--text);"><?= Security::h($r['title']) ?></div>
          <?php if (!empty($r['description'])): ?>
          <div style="font-size:10px;color:var(--text-muted);margin-top:2px;"><?= Security::h(mb_substr($r['description'], 0, 70)) ?><?= mb_strlen($r['description'] ?? '') > 70 ? '…' : '' ?></div>
          <?php endif; ?>
        </td>
        <td style="color:var(--text-muted);white-space:nowrap;"><?= Security::h($r['category_name'] ?? '—') ?></td>
        <td class="center" style="color:var(--text-muted);font-weight:600;"><?= $lik ?: '—' ?></td>
        <td class="center" style="color:var(--text-muted);font-weight:600;"><?= $imp ?: '—' ?></td>
        <td class="center">
          <span class="score-badge" style="background:<?= $sc_bg ?>;color:<?= $sc_cl ?>;"><?= $sc ?></span>
        </td>
        <td class="center">
          <span class="level-pill" style="background:<?= $lvl_bg ?>;color:<?= $lvl_cl ?>;"><?= $lvl ?></span>
        </td>
        <td class="center">
          <?php if ($res > 0): ?>
          <span class="score-badge" style="background:<?= $res_bg ?>;color:<?= $res_cl ?>;"><?= $res ?></span>
          <?php else: ?><span style="color:var(--border);">—</span><?php endif; ?>
        </td>
        <td>
          <span class="status-chip2" style="background:<?= $stColor[0] ?>;color:<?= $stColor[1] ?>;"><?= ucfirst(str_replace('_', ' ', $r['status'] ?? '')) ?></span>
        </td>
        <td>
          <?php if ($strategy && $strategy !== '—'): ?>
          <span style="background:rgba(55,65,81,.08);color:var(--secondary);font-size:10px;font-weight:600;padding:2px 7px;border-radius:99px;display:inline-block;"><?= $strategy ?></span>
          <?php else: ?><span style="color:var(--border);">—</span><?php endif; ?>
        </td>
        <td style="white-space:nowrap;"><?= Security::h($r['owner_name'] ?? '—') ?></td>
        <td style="white-space:nowrap;color:<?= $isOverdue ? 'var(--danger)' : 'var(--text-muted)' ?>;">
          <?= $reviewDt ?>
          <?php if ($isOverdue): ?>&nbsp;<i class="bi bi-exclamation-circle-fill" style="color:var(--danger);"></i><?php endif; ?>
        </td>
        <td class="center">
          <?php if ($openTx > 0): ?>
          <span style="display:inline-block;background:var(--warning-subtle);color:var(--warning);font-weight:700;min-width:22px;padding:2px 6px;border-radius:6px;font-size:11px;"><?= $openTx ?></span>
          <?php else: ?><span style="color:var(--border);">0</span><?php endif; ?>
        </td>
        <td class="center">
          <?php if ($ctrlCnt > 0): ?>
          <span style="display:inline-block;background:var(--info-subtle);color:var(--info);font-weight:700;min-width:22px;padding:2px 6px;border-radius:6px;font-size:11px;"><?= $ctrlCnt ?></span>
          <?php else: ?><span style="color:var(--border);">0</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:48px 24px;color:var(--text-muted);">
    <i class="bi bi-check-circle-fill" style="font-size:32px;color:var(--success);display:block;margin-bottom:10px;"></i>
    No risks match the current filter criteria.
  </div>
  <?php endif; ?>
</div>

<!-- ── Page Footer ─────────────────────────────────────────────────────────── -->
<div class="rd-footer">
  Generated by AEGIS GRC &middot; <?= Security::h($reportDate) ?> &middot; CONFIDENTIAL
</div>

<style nonce="<?= $nonce ?>">
@media print {
  .no-print { display: none !important; }
  .rd-print-only { display: block !important; }
}
</style>
