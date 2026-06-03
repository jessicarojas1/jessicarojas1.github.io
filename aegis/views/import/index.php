<?php
$templates = [
  'risks'     => 'title,description,likelihood,impact,status,category,treatment_type',
  'vendors'   => 'name,category,website,description,risk_tier',
  'incidents' => 'title,description,severity',
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Bulk Import</h1>
    <p class="page-subtitle">Import risks, vendors, or incidents from CSV files.</p>
  </div>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="card" style="max-width:700px">
  <div class="card-body">
    <form method="POST" action="/import/upload" enctype="multipart/form-data">
      <?= Security::csrfField() ?>

      <div class="form-group">
        <label class="form-label">Entity Type <span class="required">*</span></label>
        <select name="import_type" id="importType" class="form-control" data-change="updateTemplate">
          <option value="">— Select —</option>
          <option value="risks">Risks</option>
          <option value="vendors">Vendors</option>
          <option value="incidents">Incidents</option>
        </select>
      </div>

      <div id="templateBox" class="alert-box" style="background:#f0f9ff;border-color:#bae6fd;display:none;margin-bottom:16px">
        <div style="font-size:13px;color:#0c4a6e">
          <strong>Required CSV headers:</strong><br>
          <code id="templateHeaders" style="font-size:12px"></code>
        </div>
        <div style="margin-top:8px">
          <button type="button" data-click="downloadTemplate" class="btn btn-sm btn-secondary">
            <i class="bi bi-download"></i> Download Template
          </button>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">CSV File <span class="required">*</span></label>
        <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
        <p class="form-hint">Max 10MB. First row must be the header row.</p>
      </div>

      <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload"></i> Import</button>
    </form>
  </div>
</div>

<div class="card" style="max-width:700px;margin-top:24px">
  <div class="card-header"><h3>Import Notes</h3></div>
  <div class="card-body" style="font-size:14px">
    <ul style="margin:0;padding-left:20px;line-height:2">
      <li><strong>Risks:</strong> <code>likelihood</code> and <code>impact</code> must be 1–5. <code>status</code>: open / accepted / mitigated / closed.</li>
      <li><strong>Vendors:</strong> <code>risk_tier</code>: critical / high / medium / low. <code>website</code> must be http/https.</li>
      <li><strong>Incidents:</strong> <code>severity</code>: critical / high / medium / low.</li>
      <li>All imports are atomic — if any row fails, none are saved.</li>
      <li>Duplicate detection is not performed; each row creates a new record.</li>
    </ul>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
const templates = <?= json_encode($templates) ?>;
function updateTemplate() {
  const type = document.getElementById('importType').value;
  const box  = document.getElementById('templateBox');
  if (type && templates[type]) {
    document.getElementById('templateHeaders').textContent = templates[type];
    box.style.display = 'block';
  } else {
    box.style.display = 'none';
  }
}
function downloadTemplate() {
  const type    = document.getElementById('importType').value;
  const headers = templates[type];
  if (!headers) return;
  const blob = new Blob([headers + '\n'], { type: 'text/csv' });
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = type + '_template.csv';
  a.click();
}
</script>
