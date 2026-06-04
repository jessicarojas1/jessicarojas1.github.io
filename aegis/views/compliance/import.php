<?php
$pageTitle    = 'Import Standard';
$activeModule = 'import';
$breadcrumbs  = [['Compliance','/compliance'],['Import',null]];
ob_start();

$packages = Database::fetchAll("SELECT id, name FROM compliance_packages WHERE is_active=TRUE ORDER BY name");
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Import Compliance Package</h1>
    <p class="page-subtitle">Upload a file or add controls one at a time</p>
  </div>
  <a href="/compliance" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($_SESSION['import_errors'])): ?>
  <div class="alert-box error">
    <i class="bi bi-exclamation-circle-fill"></i>
    <ul style="margin:0;padding-left:16px">
      <?php foreach ($_SESSION['import_errors'] as $e): ?><li><?= Security::h($e) ?></li><?php endforeach;
      unset($_SESSION['import_errors']); ?>
    </ul>
  </div>
<?php endif; ?>

<!-- Tab switcher -->
<div class="tab-bar" style="margin-bottom:20px">
  <button class="tab-btn active" data-tab="csv"><i class="bi bi-filetype-csv"></i> CSV</button>
  <button class="tab-btn"        data-tab="excel"><i class="bi bi-file-earmark-excel-fill"></i> Excel</button>
  <button class="tab-btn"        data-tab="pdf"><i class="bi bi-file-earmark-pdf-fill"></i> PDF</button>
  <button class="tab-btn"        data-tab="json"><i class="bi bi-filetype-json"></i> JSON</button>
  <button class="tab-btn"        data-tab="manual"><i class="bi bi-plus-circle-fill"></i> Single Control</button>
</div>

<div class="two-col-layout">

  <!-- ── CSV ── -->
  <div id="tab-csv" class="tab-panel">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-filetype-csv"></i> Upload CSV</h3></div>
      <div class="card-body">
        <form method="POST" action="/compliance/import" enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="import_type" value="csv">
          <div class="form-group">
            <label class="form-label">CSV File</label>
            <label class="file-drop" id="fileDropCsv" for="csvFile">
              <i class="bi bi-filetype-csv" style="font-size:2rem;color:#059669"></i>
              <p>Drag & drop or <strong>click to upload</strong></p>
              <p class="text-muted">.csv format, max 20MB</p>
            </label>
            <input type="file" id="csvFile" name="package_file" accept=".csv,text/csv" style="display:none">
            <div id="csvName" style="margin-top:8px;color:#059669;display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          </div>
          <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-cloud-upload"></i> Import CSV</button>
        </form>
        <div style="margin-top:10px">
          <a href="/compliance/csv-template" class="btn btn-ghost btn-sm"><i class="bi bi-download"></i> Download CSV Template</a>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Excel ── -->
  <div id="tab-excel" class="tab-panel" style="display:none">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-file-earmark-excel-fill"></i> Upload Excel (.xlsx)</h3></div>
      <div class="card-body">
        <form method="POST" action="/compliance/import" enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="import_type" value="excel">
          <div class="form-group">
            <label class="form-label">Excel File (.xlsx)</label>
            <label class="file-drop" id="fileDropExcel" for="excelFile">
              <i class="bi bi-file-earmark-excel-fill" style="font-size:2rem;color:#217346"></i>
              <p>Drag & drop or <strong>click to upload</strong></p>
              <p class="text-muted">.xlsx format, max 20MB</p>
            </label>
            <input type="file" id="excelFile" name="package_file"
                   accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                   style="display:none">
            <div id="excelName" style="margin-top:8px;color:#217346;display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          </div>
          <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-cloud-upload"></i> Import Excel</button>
        </form>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <a href="/compliance/excel-template" class="btn btn-ghost btn-sm"><i class="bi bi-download"></i> Download Excel Template</a>
          <a href="/compliance/csv-template"   class="btn btn-ghost btn-sm"><i class="bi bi-download"></i> Download CSV Template</a>
        </div>
      </div>
    </div>
  </div>

  <!-- ── PDF ── -->
  <div id="tab-pdf" class="tab-panel" style="display:none">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-file-earmark-pdf-fill"></i> Upload PDF</h3></div>
      <div class="card-body">
        <div class="alert-box info" style="margin-bottom:16px">
          <i class="bi bi-info-circle-fill"></i>
          Extracts text from the PDF and auto-detects control codes. Works best with text-based PDFs (not scanned images). You can edit controls after import.
        </div>
        <form method="POST" action="/compliance/import" enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="import_type" value="pdf">
          <div class="form-group">
            <label class="form-label">PDF File</label>
            <label class="file-drop" id="fileDropPdf" for="pdfFile">
              <i class="bi bi-file-earmark-pdf-fill" style="font-size:2rem;color:#dc2626"></i>
              <p>Drag & drop or <strong>click to upload</strong></p>
              <p class="text-muted">.pdf, max 20MB — must have selectable text</p>
            </label>
            <input type="file" id="pdfFile" name="package_file" accept=".pdf,application/pdf"
                   style="display:none">
            <div id="pdfName" style="margin-top:8px;color:#dc2626;display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          </div>
          <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-cloud-upload"></i> Import PDF</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── JSON ── -->
  <div id="tab-json" class="tab-panel" style="display:none">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-filetype-json"></i> Upload JSON</h3></div>
      <div class="card-body">
        <form method="POST" action="/compliance/import" enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="import_type" value="json">
          <div class="form-group">
            <label class="form-label">JSON File</label>
            <label class="file-drop" id="fileDropJson" for="jsonFile">
              <i class="bi bi-filetype-json" style="font-size:2rem;color:#4f46e5"></i>
              <p>Drag & drop or <strong>click to upload</strong></p>
              <p class="text-muted">.json format, max 20MB</p>
            </label>
            <input type="file" id="jsonFile" name="package_file" accept=".json,application/json"
                   style="display:none">
            <div id="jsonName" style="margin-top:8px;color:#4f46e5;display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          </div>
          <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-cloud-upload"></i> Import JSON</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Single Control ── -->
  <div id="tab-manual" class="tab-panel" style="display:none">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-plus-circle-fill"></i> Add Single Control</h3></div>
      <div class="card-body">
        <?php if (!$packages): ?>
          <div class="alert-box info">
            <i class="bi bi-info-circle-fill"></i> You need a compliance package first.
            <a href="/compliance/create" class="btn btn-ghost btn-sm" style="margin-left:8px"><i class="bi bi-plus-lg"></i> Create Package</a>
          </div>
        <?php else: ?>
        <form method="POST" action="/compliance/add-single-control">
          <?= Security::csrfField() ?>
          <div class="form-group">
            <label class="form-label">Package <span style="color:#dc2626">*</span></label>
            <select name="package_id" class="form-control" required>
              <option value="">— Select package —</option>
              <?php foreach ($packages as $p): ?><option value="<?= $p['id'] ?>"><?= Security::h($p['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
              <label class="form-label">Domain Code <span style="color:#dc2626">*</span></label>
              <input type="text" name="domain_code" class="form-control" required placeholder="e.g. AC">
            </div>
            <div class="form-group">
              <label class="form-label">Domain Title <span style="color:#dc2626">*</span></label>
              <input type="text" name="domain_title" class="form-control" required placeholder="e.g. Access Control">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group">
              <label class="form-label">Control Code <span style="color:#dc2626">*</span></label>
              <input type="text" name="control_code" class="form-control" required placeholder="e.g. AC.1">
            </div>
            <div class="form-group">
              <label class="form-label">Control Title <span style="color:#dc2626">*</span></label>
              <input type="text" name="control_title" class="form-control" required placeholder="e.g. User Access Review">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Description <span class="text-muted">(optional)</span></label>
            <textarea name="control_description" class="form-control" rows="3" placeholder="What does this control require?"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Additional Information <span class="text-muted">(optional)</span></label>
            <textarea name="control_additional_information" class="form-control" rows="3" placeholder="Guidance, references, implementation notes…"></textarea>
          </div>
          <p class="text-muted" style="font-size:12px;margin-bottom:12px">If the domain code already exists in the package, the control will be added to it. Otherwise a new domain is created automatically.</p>
          <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-plus-lg"></i> Add Control</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: templates + column guide -->
  <div>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-download"></i> Templates</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
        <a href="/compliance/excel-template" class="btn btn-ghost btn-full" style="justify-content:flex-start;gap:10px">
          <i class="bi bi-file-earmark-excel-fill" style="color:#217346;font-size:20px"></i>
          <div style="text-align:left">
            <div style="font-weight:600;font-size:13px">Excel Template (.xlsx)</div>
            <div class="text-muted" style="font-size:11px">Fill in Excel, save, upload</div>
          </div>
        </a>
        <a href="/compliance/csv-template" class="btn btn-ghost btn-full" style="justify-content:flex-start;gap:10px">
          <i class="bi bi-filetype-csv" style="color:#059669;font-size:20px"></i>
          <div style="text-align:left">
            <div style="font-weight:600;font-size:13px">CSV Template (.csv)</div>
            <div class="text-muted" style="font-size:11px">Open in Excel or any text editor</div>
          </div>
        </a>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-table"></i> Required Columns</h3></div>
      <div class="card-body">
        <p class="text-muted" style="margin-bottom:10px;font-size:12px">Same column names for CSV and Excel (row 1 = headers):</p>
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead><tr style="border-bottom:1px solid var(--border)">
            <th style="text-align:left;padding:4px 8px;color:var(--text-muted)">Column</th>
            <th style="text-align:left;padding:4px 8px;color:var(--text-muted)">Required</th>
          </tr></thead>
          <tbody>
          <?php foreach ([
            ['package_name','✓'],['package_version',''],['package_description',''],
            ['domain_code','✓'],['domain_title','✓'],
            ['control_code','✓'],['control_title','✓'],['control_description',''],
            ['control_additional_information',''],
          ] as [$col,$req]): ?>
            <tr style="border-bottom:1px solid var(--border-light)">
              <td style="padding:4px 8px;font-family:monospace;font-size:11px"><?= $col ?></td>
              <td style="padding:4px 8px;color:<?= $req ? '#059669' : 'var(--text-muted)' ?>"><?= $req ?: '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</div><!-- /two-col-layout -->

<script nonce="<?= Security::nonce() ?>">
function switchTab(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).style.display = '';
  btn.classList.add('active');
}
function showFile(input, dropId, nameId, color) {
  if (!input.files.length) return;
  const drop = document.getElementById(dropId);
  const nameEl = document.getElementById(nameId);
  nameEl.style.display = 'block';
  nameEl.style.color = color;
  nameEl.querySelector('span').textContent = input.files[0].name;
  drop.style.borderColor = color;
}
['Csv','Excel','Pdf','Json'].forEach(function(type) {
  const drop  = document.getElementById('fileDrop' + type);
  const input = document.getElementById(type.toLowerCase() + 'File');
  if (!drop || !input) return;
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drag-over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
  drop.addEventListener('drop', function(e) {
    e.preventDefault(); drop.classList.remove('drag-over');
    const dt = new DataTransfer();
    dt.items.add(e.dataTransfer.files[0]);
    input.files = dt.files;
    input.dispatchEvent(new Event('change'));
  });
});
if (window.location.hash === '#manual') {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
  document.getElementById('tab-manual').style.display = '';
  document.querySelectorAll('.tab-btn')[4].classList.add('active');
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
