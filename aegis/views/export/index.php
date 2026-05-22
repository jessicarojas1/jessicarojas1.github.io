<?php
$pageTitle    = 'Export';
$activeModule = 'export';
$breadcrumbs  = [['Export', null]];
ob_start();
?>

<div class="page-header">
  <h1 class="page-title">Export Data</h1>
  <p class="page-subtitle" style="color:var(--text-muted);margin-top:4px">Download GRC data as CSV or XLSX for reporting and analysis</p>
</div>

<div class="export-grid">

  <?php
  $modules = [
    ['risks',     'Risk Register',          'bi-exclamation-triangle-fill', '#dc2626', '#fee2e2',
     'All risks with scores, categories, owners, treatment details, and review dates.'],
    ['standards', 'Standards & Compliance', 'bi-shield-check',              '#4f46e5', '#eef2ff',
     'Standards summary with objective counts and compliance status breakdown per package.'],
    ['audits',    'Audit Report',           'bi-clipboard2-check-fill',     '#0284c7', '#dbeafe',
     'All audits with type, score, findings count, auditor, and completion dates.'],
    ['policies',  'Policy Register',        'bi-file-earmark-text-fill',    '#059669', '#d1fae5',
     'All policies with status, owners, review frequency, and mapped control count.'],
    ['controls',  'Control Implementations','bi-check2-square',             '#7c3aed', '#ede9fe',
     'Every level-2 control with implementation status, notes, evidence, and assignee.'],
    ['evidence',  'Evidence Log',           'bi-folder-check',              '#d97706', '#fef3c7',
     'Controls that have evidence recorded — notes, evidence text, and reviewer.'],
  ];
  foreach ($modules as [$key, $label, $icon, $color, $bg, $desc]): ?>

  <div class="export-card">
    <div class="export-card-icon" style="background:<?= $bg ?>;color:<?= $color ?>">
      <i class="bi <?= $icon ?>"></i>
    </div>
    <div class="export-card-body">
      <h3 class="export-card-title"><?= $label ?></h3>
      <p class="export-card-desc"><?= $desc ?></p>
    </div>
    <div class="export-card-actions">
      <form method="POST" action="/export/download" class="export-form">
        <?= Security::csrfField() ?>
        <input type="hidden" name="module" value="<?= $key ?>">
        <button type="submit" name="format" value="csv" class="btn btn-ghost btn-sm">
          <i class="bi bi-filetype-csv"></i> CSV
        </button>
        <button type="submit" name="format" value="xlsx" class="btn btn-primary btn-sm">
          <i class="bi bi-file-earmark-spreadsheet"></i> XLSX
        </button>
      </form>
    </div>
  </div>

  <?php endforeach; ?>

</div>

<!-- Bulk export -->
<div class="card" style="margin-top:20px">
  <div class="card-header"><h3 class="card-title"><i class="bi bi-archive-fill"></i> Full GRC Export</h3></div>
  <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div>
      <div class="fw-600">Export All Modules</div>
      <div class="text-muted text-sm">Downloads a ZIP containing separate XLSX files for every module above.</div>
    </div>
    <form method="POST" action="/export/download-all">
      <?= Security::csrfField() ?>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-download"></i> Download Full Export (XLSX)
      </button>
    </form>
  </div>
</div>

<style>
.export-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; }
.export-card {
  background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 20px; display: flex; gap: 16px; align-items: flex-start;
  box-shadow: var(--shadow); transition: box-shadow var(--transition);
}
.export-card:hover { box-shadow: var(--shadow-md); }
.export-card-icon {
  width: 48px; height: 48px; border-radius: 12px; display: flex;
  align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;
}
.export-card-body { flex: 1; }
.export-card-title { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
.export-card-desc  { font-size: 12px; color: var(--text-muted); line-height: 1.5; margin: 0 0 12px; }
.export-card-actions .export-form { display: flex; gap: 8px; }
</style>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
