<?php
$pageTitle    = 'Compliance Metrics';
$activeModule = 'reports';
$breadcrumbs  = [['Reports', '/reports'], ['Compliance Metrics', null]];
ob_start();

/** Render a horizontal bar list from rows of [label, count]. */
$barList = function (array $rows, string $labelKey, string $countKey, array $colors = []): string {
    $max = 1;
    foreach ($rows as $r) { $max = max($max, (int)$r[$countKey]); }
    $palette = ['var(--primary)','var(--success)','var(--warning)','var(--info)','var(--danger)','#8b5cf6','#0ea5e9','#14b8a6','#f97316','#64748b'];
    $html = '<div style="display:flex;flex-direction:column;gap:9px">';
    $i = 0;
    foreach ($rows as $r) {
        $c = (int)$r[$countKey];
        $pct = max(2, (int)round($c / $max * 100));
        $color = $colors[$r[$labelKey]] ?? $palette[$i % count($palette)];
        $html .= '<div style="display:flex;align-items:center;gap:10px;font-size:.85rem">'
              . '<div style="width:120px;text-align:right;color:var(--text-muted);text-transform:capitalize">' . Security::h(str_replace('_',' ',(string)$r[$labelKey])) . '</div>'
              . '<div style="flex:1;background:var(--bg-secondary);border-radius:5px;overflow:hidden"><div style="width:' . $pct . '%;background:' . $color . ';height:18px;border-radius:5px"></div></div>'
              . '<div style="width:40px;font-weight:600">' . $c . '</div></div>';
        $i++;
    }
    return $html . '</div>';
};
$statusColors = ['draft'=>'var(--text-light)','in_review'=>'var(--warning)','approved'=>'var(--info)','published'=>'var(--success)','rejected'=>'var(--danger)','archived'=>'var(--text-muted)','obsolete'=>'var(--text-muted)','pending'=>'var(--warning)'];
$reviewRows = [['k'=>'On track','c'=>(int)$review['ontrack']],['k'=>'Due ≤30d','c'=>(int)$review['soon']],['k'=>'Overdue','c'=>(int)$review['overdue']]];
$reviewColors = ['On track'=>'var(--success)','Due ≤30d'=>'var(--warning)','Overdue'=>'var(--danger)'];
$ackPct = (int)$ackStats['required'] > 0 ? (int)round((int)$ackStats['acknowledged'] / (int)$ackStats['required'] * 100) : 0;
?>
<div class="page-header">
  <div><h1 class="page-title"><i class="bi bi-graph-up-arrow"></i> Compliance Metrics</h1><p class="page-subtitle">Document control, approvals and process health at a glance.</p></div>
  <div class="page-actions"><a href="/reports" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> All reports</a></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-clock-history"></i></div><div><div class="stat-value"><?= (int)$review['overdue'] ?></div><div class="stat-label">Overdue reviews</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-value"><?= $avgDecisionDays && $avgDecisionDays['d'] !== null ? Security::h($avgDecisionDays['d']) : '—' ?></div><div class="stat-label">Avg approval days (90d)</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-exclamation-triangle"></i></div><div><div class="stat-value"><?= (int)$backlog['stale'] ?></div><div class="stat-label">Approvals pending &gt;7d</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-patch-check"></i></div><div><div class="stat-value"><?= $ackPct ?>%</div><div class="stat-label">Ack-required docs covered</div></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px">
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-bar-chart"></i> Documents by status</span></div></div><div class="card-body"><?= $docByStatus ? $barList($docByStatus, 'status', 'c', $statusColors) : '<div class="empty-state-sm">No documents.</div>' ?></div></div>
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-calendar-check"></i> Review compliance</span></div></div><div class="card-body"><?= $barList($reviewRows, 'k', 'c', $reviewColors) ?><p class="form-hint" style="margin-top:10px">Published documents with a review date.</p></div></div>
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-files"></i> Documents by type</span></div></div><div class="card-body"><?= $docByType ? $barList($docByType, 'doc_type', 'c') : '<div class="empty-state-sm">No documents.</div>' ?></div></div>
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-diagram-3"></i> Processes by status</span></div></div><div class="card-body"><?= $procByStatus ? $barList($procByStatus, 'status', 'c', $statusColors) : '<div class="empty-state-sm">No processes.</div>' ?></div></div>
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-graph-up"></i> Approval throughput (8 weeks)</span></div></div><div class="card-body"><?= $throughput ? $barList($throughput, 'wk', 'c') : '<div class="empty-state-sm">No decided approvals in the last 8 weeks.</div>' ?></div></div>
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-hourglass"></i> Pending approval backlog by age</span></div></div><div class="card-body"><?= $barList([['k'=>'≤3 days','c'=>(int)$backlog['fresh']],['k'=>'3–7 days','c'=>(int)$backlog['aging']],['k'=>'>7 days','c'=>(int)$backlog['stale']]], 'k', 'c', ['≤3 days'=>'var(--success)','3–7 days'=>'var(--warning)','>7 days'=>'var(--danger)']) ?></div></div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
