<?php
$pageTitle    = 'Import Standard';
$activeModule = 'import';
$breadcrumbs  = [['Compliance','/compliance'],['Import',null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Import Compliance Package</h1>
    <p class="page-subtitle">Upload a standard via CSV, PDF, or JSON</p>
  </div>
  <div style="display:flex;gap:8px;align-items:center">
    <a href="/compliance/csv-template" class="btn btn-ghost"><i class="bi bi-download"></i> Download CSV Template</a>
    <a href="/compliance" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<?php if (!empty($_SESSION['import_errors'])): ?>
  <div class="alert-box error">
    <i class="bi bi-exclamation-circle-fill"></i>
    <ul style="margin:0;padding-left:16px">
      <?php foreach ($_SESSION['import_errors'] as $e): ?>
        <li><?= Security::h($e) ?></li>
      <?php endforeach; unset($_SESSION['import_errors']); ?>
    </ul>
  </div>
<?php endif; ?>

<!-- Tab switcher -->
<div class="tab-bar" style="margin-bottom:20px">
  <button class="tab-btn active" onclick="switchTab('csv', this)"><i class="bi bi-filetype-csv"></i> CSV</button>
  <button class="tab-btn"        onclick="switchTab('pdf', this)"><i class="bi bi-file-earmark-pdf-fill"></i> PDF</button>
  <button class="tab-btn"        onclick="switchTab('json', this)"><i class="bi bi-filetype-json"></i> JSON</button>
</div>

<div class="two-col-layout">

  <!-- ── CSV ── -->
  <div id="tab-csv" class="tab-panel">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-filetype-csv"></i> Upload CSV</h3>
      </div>
      <div class="card-body">
        <form method="POST" action="/compliance/import" enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="import_type" value="csv">
          <div class="form-group">
            <label class="form-label">Base Standard (optional)</label>
            <select name="standard_id" class="form-control">
              <option value="">— Custom Standard —</option>
              <?php foreach ($standards as $s): ?>
                <option value="<?= $s['id'] ?>"><?= Security::h($s['name']) ?> (<?= Security::h($s['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">CSV File</label>
            <div class="file-drop" id="fileDropCsv" onclick="document.getElementById('csvFile').click()">
              <i class="bi bi-filetype-csv" style="font-size:2rem;color:#059669"></i>
              <p>Drag & drop or <strong>click to upload</strong></p>
              <p class="text-muted">.csv format, max 20MB</p>
            </div>
            <input type="file" id="csvFile" name="package_file" accept=".csv,text/csv" style="display:none" onchange="showFile(this,'fileDropCsv','csvName','#059669')">
            <div id="csvName" style="margin-top:8px;color:#059669;display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          </div>
          <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-cloud-upload"></i> Import CSV</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── PDF ── -->
  <div id="tab-pdf" class="tab-panel" style="display:none">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-file-earmark-pdf-fill"></i> Upload PDF</h3>
      </div>
      <div class="card-body">
        <div class="alert-box info" style="margin-bottom:16px">
          <i class="bi bi-info-circle-fill"></i>
          The system will extract text from the PDF and auto-detect control codes and titles. Works best with text-based PDFs (not scanned images). You can edit or add controls after import.
        </div>
        <form method="POST" action="/compliance/import" enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="import_type" value="pdf">
          <div class="form-group">
            <label class="form-label">Base Standard (optional)</label>
            <select name="standard_id" class="form-control">
              <option value="">— Custom Standard —</option>
              <?php foreach ($standards as $s): ?>
                <option value="<?= $s['id'] ?>"><?= Security::h($s['name']) ?> (<?= Security::h($s['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">PDF File</label>
            <div class="file-drop" id="fileDropPdf" onclick="document.getElementById('pdfFile').click()">
              <i class="bi bi-file-earmark-pdf-fill" style="font-size:2rem;color:#dc2626"></i>
              <p>Drag & drop or <strong>click to upload</strong></p>
              <p class="text-muted">.pdf format, max 20MB — must have selectable text</p>
            </div>
            <input type="file" id="pdfFile" name="package_file" accept=".pdf,application/pdf" style="display:none" onchange="showFile(this,'fileDropPdf','pdfName','#dc2626')">
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
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-filetype-json"></i> Upload JSON</h3>
      </div>
      <div class="card-body">
        <form method="POST" action="/compliance/import" enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="import_type" value="json">
          <div class="form-group">
            <label class="form-label">Base Standard (optional)</label>
            <select name="standard_id" class="form-control">
              <option value="">— Custom Standard —</option>
              <?php foreach ($standards as $s): ?>
                <option value="<?= $s['id'] ?>"><?= Security::h($s['name']) ?> (<?= Security::h($s['code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">JSON File</label>
            <div class="file-drop" id="fileDropJson" onclick="document.getElementById('jsonFile').click()">
              <i class="bi bi-filetype-json" style="font-size:2rem;color:#4f46e5"></i>
              <p>Drag & drop or <strong>click to upload</strong></p>
              <p class="text-muted">.json format, max 20MB</p>
            </div>
            <input type="file" id="jsonFile" name="package_file" accept=".json,application/json" style="display:none" onchange="showFile(this,'fileDropJson','jsonName','#4f46e5')">
            <div id="jsonName" style="margin-top:8px;color:#4f46e5;display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          </div>
          <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-cloud-upload"></i> Import JSON</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Right column: info panels -->
  <div>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-table"></i> CSV Format</h3>
      </div>
      <div class="card-body">
        <p class="text-muted" style="margin-bottom:12px">Download the template and fill in your controls. Required columns:</p>
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
            ] as [$col,$req]): ?>
            <tr style="border-bottom:1px solid var(--border-light)">
              <td style="padding:4px 8px;font-family:monospace;font-size:11px"><?= $col ?></td>
              <td style="padding:4px 8px;color:<?= $req ? '#059669' : 'var(--text-muted)' ?>"><?= $req ?: '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <a href="/compliance/csv-template" class="btn btn-ghost btn-sm btn-full" style="margin-top:12px">
          <i class="bi bi-download"></i> Download Template
        </a>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-info-circle-fill"></i> Built-in Standards</h3>
      </div>
      <div class="card-body p0">
        <?php foreach ($standards as $s): ?>
          <div class="list-item">
            <div class="list-item-body">
              <div class="list-item-title"><?= Security::h($s['name']) ?></div>
              <div class="list-item-sub"><?= Security::h($s['code']) ?> · <?= Security::h($s['authority']) ?></div>
            </div>
            <span class="badge badge-green">Built-in</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div><!-- /two-col-layout -->

<script>
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

// Drag-and-drop for all three zones
['Csv','Pdf','Json'].forEach(function(type) {
  const drop = document.getElementById('fileDrop' + type);
  const input = document.getElementById(type.toLowerCase() + 'File');
  if (!drop || !input) return;
  drop.addEventListener('dragover', function(e) { e.preventDefault(); drop.classList.add('drag-over'); });
  drop.addEventListener('dragleave', function() { drop.classList.remove('drag-over'); });
  drop.addEventListener('drop', function(e) {
    e.preventDefault(); drop.classList.remove('drag-over');
    const dt = new DataTransfer();
    dt.items.add(e.dataTransfer.files[0]);
    input.files = dt.files;
    input.dispatchEvent(new Event('change'));
  });
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
