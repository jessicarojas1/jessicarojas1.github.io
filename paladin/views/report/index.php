<?php
$pageTitle    = 'Reports';
$activeModule = 'reports';
$breadcrumbs  = [['Reports', null]];
ob_start();

$statusLabels = [];
$statusCounts = [];
foreach ($byStatus as $row) {
    $statusLabels[] = View::docTypeLabel((string)$row['status']);
    $statusCounts[] = (int)$row['c'];
}
?>
<div class="page-header">
  <div><h1 class="page-title">Reports &amp; Insights</h1><p class="page-subtitle">Compliance posture across documents, approvals &amp; acknowledgements</p></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-files"></i></div><div><div class="stat-value"><?= (int)$stats['documents'] ?></div><div class="stat-label">Total Documents</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-patch-check-fill"></i></div><div><div class="stat-value"><?= (int)$stats['published'] ?></div><div class="stat-label">Published</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-clock-history"></i></div><div><div class="stat-value"><?= (int)$stats['overdue'] ?></div><div class="stat-label">Overdue Reviews</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(249,115,22,.12);color:var(--orange)"><i class="bi bi-calendar-x-fill"></i></div><div><div class="stat-value"><?= (int)$stats['expiring'] ?></div><div class="stat-label">Expiring (30d)</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-check2-square"></i></div><div><div class="stat-value"><?= (int)$stats['pending'] ?></div><div class="stat-label">Pending Approvals</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(2,132,199,.12);color:var(--info)"><i class="bi bi-list-task"></i></div><div><div class="stat-value"><?= (int)$stats['open_tasks'] ?></div><div class="stat-label">Open Tasks</div></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.2fr;gap:20px;margin-top:22px;align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-pie-chart-fill"></i> Documents by Status</span></div></div>
    <div class="card-body">
      <?php if ($statusCounts): ?>
        <canvas id="docStatusChart" height="240" aria-label="Documents grouped by status" role="img"></canvas>
      <?php else: ?>
        <div class="empty-state-sm" style="padding:18px">No documents to chart yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-folder2-open"></i> Available Reports</span></div></div>
    <div class="card-body">
      <div class="lib-grid">
        <a href="/reports/compliance" class="lib-card" style="text-decoration:none">
          <div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-graph-up-arrow"></i></div>
          <div class="card-title">Compliance Metrics</div>
          <div class="form-hint">Document control, review compliance, approval throughput and process health at a glance.</div>
        </a>
        <a href="/reports/expiring" class="lib-card" style="text-decoration:none">
          <div class="stat-icon" style="background:rgba(249,115,22,.12);color:var(--orange)"><i class="bi bi-calendar-x-fill"></i></div>
          <div class="card-title">Expiring &amp; Overdue Documents</div>
          <div class="form-hint">Published documents past or nearing their review or expiration date (90-day horizon).</div>
        </a>
        <a href="/reports/approval-backlog" class="lib-card" style="text-decoration:none">
          <div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-hourglass-split"></i></div>
          <div class="card-title">Approval Backlog</div>
          <div class="form-hint">Pending approval requests with ageing, current step and due dates.</div>
        </a>
        <a href="/reports/acknowledgements" class="lib-card" style="text-decoration:none">
          <div class="stat-icon" style="background:rgba(2,132,199,.12);color:var(--info)"><i class="bi bi-patch-check-fill"></i></div>
          <div class="card-title">Acknowledgement Coverage</div>
          <div class="form-hint">Read-receipt completeness for documents requiring acknowledgement.</div>
        </a>
        <a href="/reports/page-properties" class="lib-card" style="text-decoration:none">
          <div class="stat-icon" style="background:rgba(124,58,237,.12);color:#7c3aed"><i class="bi bi-table"></i></div>
          <div class="card-title">Page Properties</div>
          <div class="form-hint">Aggregate page-properties tables across every page sharing a label.</div>
        </a>
      </div>
    </div>
  </div>
</div>

<?php if ($statusCounts): ?>
<script nonce="<?= Security::nonce() ?>">
(function () {
  var el = document.getElementById('docStatusChart');
  if (!el || !window.Chart) return;
  new Chart(el, {
    type: 'doughnut',
    data: {
      labels: <?= json_encode($statusLabels, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      datasets: [{
        data: <?= json_encode($statusCounts, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
        backgroundColor: ['#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#0284c7', '#f97316', '#64748b'],
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
      cutout: '62%'
    }
  });
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
