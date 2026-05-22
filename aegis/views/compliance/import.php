<?php
$pageTitle    = 'Import Standard';
$activeModule = 'import';
$breadcrumbs  = [['Compliance','/compliance'],['Import',null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Import Compliance Package</h1>
    <p class="page-subtitle">Add custom standards or compliance frameworks</p>
  </div>
  <a href="/compliance" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
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

<div class="two-col-layout">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-cloud-upload"></i> Upload Package</h3>
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
          <label class="form-label">Package File (JSON)</label>
          <div class="file-drop" id="fileDrop" onclick="document.getElementById('pkgFile').click()">
            <i class="bi bi-file-earmark-arrow-up" style="font-size:2rem;color:#4f46e5"></i>
            <p>Drag & drop or <strong>click to upload</strong></p>
            <p class="text-muted">JSON format, max 5MB</p>
          </div>
          <input type="file" id="pkgFile" name="package_file" accept=".json,application/json" style="display:none" onchange="showFileName(this)">
          <div id="fileName" style="margin-top:8px;color:#4f46e5;display:none">
            <i class="bi bi-file-earmark-check"></i> <span></span>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-cloud-upload"></i> Import Package</button>
      </form>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-code-square"></i> JSON Format</h3>
      </div>
      <div class="card-body">
        <p class="text-muted" style="margin-bottom:12px">Your JSON file should follow this structure:</p>
        <pre class="code-block">{
  "name": "My Custom Standard",
  "version": "1.0",
  "description": "Custom compliance framework",
  "objectives": [
    {
      "code": "CS-1.1",
      "title": "Control Title",
      "category": "Domain A",
      "description": "Control description"
    }
  ]
}</pre>
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
</div>

<script>
function showFileName(input) {
  if (input.files.length > 0) {
    const d = document.getElementById('fileName');
    d.style.display = 'block';
    d.querySelector('span').textContent = input.files[0].name;
    document.getElementById('fileDrop').style.borderColor = '#4f46e5';
  }
}
const drop = document.getElementById('fileDrop');
drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('drag-over'); });
drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
drop.addEventListener('drop', e => {
  e.preventDefault(); drop.classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  const input = document.getElementById('pkgFile');
  const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
  showFileName(input);
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
