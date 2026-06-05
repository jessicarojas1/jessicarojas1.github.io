<?php
if (!$matrix) { echo '<div class="card card-body text-muted">No risk matrix configuration found. A default will be created on next page load.</div>'; return; }
$pageTitle    = 'Risk Matrix Config';
$activeModule = 'admin_risk_matrix';
$breadcrumbs  = [['Admin','/admin'],['Risk Matrix',null]];
$cfg        = $matrix;
$rowLabels  = json_decode($cfg['row_labels'], true);
$colLabels  = json_decode($cfg['col_labels'], true);
$thresholds = json_decode($cfg['thresholds'], true);
$cells      = json_decode($cfg['cells'] ?? '{}', true) ?: [];
$rows       = (int)$cfg['rows'];
$cols       = (int)$cfg['cols'];

// Default cells if empty (fallback)
$defaultCells = [
    '5_1'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Avoid / Mitigate / Transfer','color'=>'#22c55e'],
    '5_2'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Avoid / Mitigate / Transfer','color'=>'#f59e0b'],
    '5_3'=>['title'=>'Mitigate','desc'=>'Mitigate','color'=>'#ef4444'],
    '5_4'=>['title'=>'Mitigate','desc'=>'Mitigate','color'=>'#ef4444'],
    '5_5'=>['title'=>'Mitigate','desc'=>'Mitigate','color'=>'#ef4444'],
    '4_1'=>['title'=>'Accept / Monitor / Transfer','desc'=>'Accept Monitor Transfer','color'=>'#22c55e'],
    '4_2'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Avoid / Mitigate / Transfer','color'=>'#f59e0b'],
    '4_3'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Accept Mitigate Transfer','color'=>'#ef4444'],
    '4_4'=>['title'=>'Mitigate','desc'=>'Mitigate','color'=>'#ef4444'],
    '4_5'=>['title'=>'Mitigate','desc'=>'Mitigate','color'=>'#ef4444'],
    '3_1'=>['title'=>'Accept / Monitor / Transfer','desc'=>'Accept / Monitor / Transfer','color'=>'#22c55e'],
    '3_2'=>['title'=>'Accept / Monitor / Transfer','desc'=>'Accept / Monitor / Transfer','color'=>'#22c55e'],
    '3_3'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Avoid / Mitigate / Transfer','color'=>'#f59e0b'],
    '3_4'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Avoid / Mitigate / Transfer','color'=>'#f59e0b'],
    '3_5'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Avoid / Mitigate / Transfer','color'=>'#f59e0b'],
    '2_1'=>['title'=>'Accept / Monitor / Transfer','desc'=>'Accept Monitor Transfer','color'=>'#22c55e'],
    '2_2'=>['title'=>'Accept / Monitor / Transfer','desc'=>'Accept / Monitor / Transfer','color'=>'#22c55e'],
    '2_3'=>['title'=>'Accept / Monitor / Transfer','desc'=>'Accept Monitor Transfer','color'=>'#22c55e'],
    '2_4'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Avoid / Mitigate / Transfer','color'=>'#f59e0b'],
    '2_5'=>['title'=>'Avoid / Mitigate / Transfer','desc'=>'Avoid Mitigate Transfer','color'=>'#f59e0b'],
    '1_1'=>['title'=>'Accept','desc'=>'Accept','color'=>'#22c55e'],
    '1_2'=>['title'=>'Accept','desc'=>'Accept','color'=>'#22c55e'],
    '1_3'=>['title'=>'Accept','desc'=>'Accept','color'=>'#22c55e'],
    '1_4'=>['title'=>'Accept','desc'=>'Accept','color'=>'#22c55e'],
    '1_5'=>['title'=>'Accept','desc'=>'Accept','color'=>'#22c55e'],
];
foreach ($defaultCells as $k => $d) {
    if (!isset($cells[$k])) { $cells[$k] = $d; }
}

ob_start();
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Risk matrix saved.</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Matrix Configuration</h1>
    <p class="page-subtitle">Configure per-cell treatment labels and colors for the <?= Security::h($cfg['rows']) ?>×<?= Security::h($cfg['cols']) ?> matrix</p>
  </div>
  <div class="page-actions">
    <a href="/risk/matrix" class="btn btn-ghost"><i class="bi bi-eye"></i> Preview Matrix</a>
    <button type="button" class="btn btn-primary" data-click="submitMatrixForm">
      <i class="bi bi-save-fill"></i> Save Matrix
    </button>
  </div>
</div>

<form method="POST" action="/admin/risk-matrix/update" id="matrixSaveForm">
  <?= Security::csrfField() ?>
  <input type="hidden" name="matrix_id" value="<?= $cfg['id'] ?>">
  <input type="hidden" name="cells_json" id="cellsJsonInput" value="<?= Security::h(json_encode($cells)) ?>">

  <!-- Matrix info bar -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-body" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1;min-width:180px">
        <label class="form-label">Matrix Name</label>
        <input type="text" name="name" class="form-control" value="<?= Security::h($cfg['name']) ?>">
      </div>
      <div class="form-group" style="margin:0;flex:2;min-width:240px">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-control" value="<?= Security::h($cfg['description'] ?? '') ?>">
      </div>
      <div class="form-group" style="margin:0;min-width:220px">
        <label class="form-label">Row Labels (Likelihood) — comma separated</label>
        <input type="text" name="row_labels" class="form-control" value="<?= Security::h(implode(', ', $rowLabels)) ?>">
      </div>
      <div class="form-group" style="margin:0;min-width:280px">
        <label class="form-label">Column Labels (Impact) — comma separated</label>
        <input type="text" name="col_labels" class="form-control" value="<?= Security::h(implode(', ', $colLabels)) ?>">
      </div>
    </div>
  </div>

  <!-- Per-cell matrix grid -->
  <div class="card">
    <div class="card-body" style="overflow-x:auto;padding:0">
      <table class="risk-matrix-admin-table">
        <thead>
          <tr>
            <th class="rm-corner"></th>
            <?php for ($c = 1; $c <= $cols; $c++): ?>
              <th class="rm-col-head">Impact (<?= Security::h($colLabels[$c-1] ?? $c) ?> [<?= $c ?>])</th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php for ($r = $rows; $r >= 1; $r--):
            $displayIdx = $r - 1;
            $rowLabel   = $rowLabels[$r-1] ?? "Level $r";
          ?>
          <tr>
            <td class="rm-row-head">Likelihood (<?= Security::h($rowLabel) ?> [<?= $displayIdx ?>])</td>
            <?php for ($c = 1; $c <= $cols; $c++):
              $key  = "{$r}_{$c}";
              $cell = $cells[$key] ?? ['title'=>'Accept','desc'=>'Accept','color'=>'#22c55e'];
              $color = htmlspecialchars($cell['color'], ENT_QUOTES, 'UTF-8');
            ?>
            <td class="rm-cell" id="cell-<?= $key ?>">
              <div class="rm-cell-title"><?= Security::h($cell['title']) ?></div>
              <div class="rm-cell-desc"><?= Security::h(mb_strimwidth($cell['desc'], 0, 22, '...')) ?></div>
              <div class="rm-cell-color-row">
                Color: <span class="rm-color-swatch" style="background:<?= $color ?>"></span>
              </div>
              <div class="rm-cell-actions">
                <button type="button" class="btn-icon rm-edit-btn" title="Edit cell"
                        data-click="openCellEdit"
                        data-args='[<?= htmlspecialchars(json_encode($key), ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($cell['title']), ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($cell['desc']), ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($cell['color']), ENT_QUOTES) ?>]'>
                  <i class="bi bi-gear-fill"></i>
                </button>
                <button type="button" class="btn-icon btn-icon-danger" title="Reset to default"
                        data-click="resetCell" data-arg="<?= $key ?>">
                  <i class="bi bi-trash3-fill"></i>
                </button>
              </div>
            </td>
            <?php endfor; ?>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<!-- Cell edit modal -->
<div class="modal-overlay" id="cellEditModal" style="display:none">
  <div class="modal" style="max-width:420px">
    <div class="modal-header">
      <h3>Edit Cell</h3>
      <button type="button" data-click="closeCellEdit"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editKey">
      <div class="form-group">
        <label class="form-label">Treatment / Title</label>
        <input type="text" id="editTitle" class="form-control" placeholder="e.g. Mitigate">
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <input type="text" id="editDesc" class="form-control" placeholder="Brief description">
      </div>
      <div class="form-group">
        <label class="form-label">Cell Color</label>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <?php foreach ([
            '#22c55e' => 'Accept (green)',
            '#f59e0b' => 'Monitor (yellow)',
            '#f97316' => 'Transfer (orange)',
            '#ef4444' => 'Mitigate (red)',
            'var(--secondary)' => 'Avoid (purple)',
          ] as $hex => $label): ?>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
            <input type="radio" name="editColorPick" value="<?= $hex ?>"
                   data-change="onEditColorPickChange">
            <span style="display:inline-block;width:16px;height:16px;border-radius:4px;background:<?= $hex ?>"></span>
            <?= $label ?>
          </label>
          <?php endforeach; ?>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
            <input type="radio" name="editColorPick" value="custom" data-change="updateColorPreview">
            Custom:
            <input type="color" id="editColor" value="#22c55e" data-input="onEditColorInput" style="width:40px;height:28px;border:none;cursor:pointer;border-radius:4px">
          </label>
        </div>
        <div id="colorPreview" style="margin-top:10px;padding:8px 12px;border-radius:6px;font-size:13px;font-weight:600;text-align:center;color:#fff"></div>
      </div>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:16px">
      <button type="button" class="btn btn-ghost" data-click="closeCellEdit">Cancel</button>
      <button type="button" class="btn btn-primary" data-click="saveCellEdit"><i class="bi bi-save-fill"></i> Save Cell</button>
    </div>
  </div>
</div>

<style>
.risk-matrix-admin-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.risk-matrix-admin-table th,
.risk-matrix-admin-table td {
  border: 1px solid var(--border);
  padding: 0;
}
.rm-corner { width: 160px; background: var(--surface-2); }
.rm-col-head {
  text-align: center;
  padding: 10px 8px;
  font-weight: 600;
  font-size: 12px;
  background: var(--surface-2);
  white-space: nowrap;
}
.rm-row-head {
  padding: 10px 12px;
  font-weight: 600;
  font-size: 12px;
  background: var(--surface-2);
  white-space: nowrap;
  width: 160px;
}
.rm-cell {
  padding: 10px 12px;
  vertical-align: middle;
  text-align: center;
  min-width: 150px;
}
.rm-cell-title { font-weight: 600; font-size: 12px; color: var(--text); margin-bottom: 2px; }
.rm-cell-desc  { font-size: 11px; color: var(--text-muted); margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rm-cell-color-row { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 11px; color: var(--text-muted); margin-bottom: 6px; }
.rm-color-swatch { display: inline-block; width: 22px; height: 14px; border-radius: 3px; border: 1px solid rgba(0,0,0,.15); }
.rm-cell-actions { display: flex; gap: 4px; justify-content: center; }
.btn-icon {
  background: transparent; border: 1px solid var(--border);
  border-radius: 5px; width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; cursor: pointer; color: var(--text-muted);
  transition: all .15s;
}
.btn-icon:hover { background: var(--surface-2); color: var(--text); }
.btn-icon-danger:hover { background: #fee2e2; color: #ef4444; border-color: #fca5a5; }
</style>

<script nonce="<?= Security::nonce() ?>">
// Close modal when clicking overlay background
document.addEventListener('click', function(e) {
  var modal = document.getElementById('cellEditModal');
  if (modal && e.target === modal) closeCellEdit();
});

function submitMatrixForm() { document.getElementById('matrixSaveForm').submit(); }

function onEditColorPickChange(e) {
  var el = e && e.target ? e.target : this;
  document.getElementById('editColor').value = el.value;
  updateColorPreview();
}

function onEditColorInput(e) {
  document.querySelector('[name=editColorPick][value=custom]').checked = true;
  updateColorPreview();
}

const cellData = <?= json_encode($cells) ?>;
const defaultCells = <?= json_encode($defaultCells) ?>;

function openCellEdit(key, title, desc, color) {
  document.getElementById('editKey').value   = key;
  document.getElementById('editTitle').value = title;
  document.getElementById('editDesc').value  = desc;
  document.getElementById('editColor').value = color;

  // Check matching preset radio
  const radios = document.querySelectorAll('[name=editColorPick]');
  radios.forEach(r => r.checked = false);
  const preset = document.querySelector(`[name=editColorPick][value="${color}"]`);
  if (preset) { preset.checked = true; }
  else { document.querySelector('[name=editColorPick][value=custom]').checked = true; }

  updateColorPreview();
  document.getElementById('cellEditModal').style.display = 'flex';
}

function closeCellEdit() {
  document.getElementById('cellEditModal').style.display = 'none';
}

function updateColorPreview() {
  const color = document.getElementById('editColor').value;
  const el = document.getElementById('colorPreview');
  el.style.background = color;
  el.textContent = color;
}

function saveCellEdit() {
  const key   = document.getElementById('editKey').value;
  const title = document.getElementById('editTitle').value.trim();
  const desc  = document.getElementById('editDesc').value.trim();
  const color = document.getElementById('editColor').value;

  cellData[key] = { title, desc, color };
  refreshCell(key);
  document.getElementById('cellsJsonInput').value = JSON.stringify(cellData);
  closeCellEdit();
}

function resetCell(key) {
  if (!confirm('Reset this cell to default values?')) return;
  const def = defaultCells[key] || { title: 'Accept', desc: 'Accept', color: '#22c55e' };
  cellData[key] = { ...def };
  refreshCell(key);
  document.getElementById('cellsJsonInput').value = JSON.stringify(cellData);
}

function refreshCell(key) {
  const cell = cellData[key];
  const td = document.getElementById('cell-' + key);
  if (!td) return;
  td.querySelector('.rm-cell-title').textContent = cell.title;
  td.querySelector('.rm-cell-desc').textContent  = cell.desc.length > 22 ? cell.desc.slice(0, 22) + '...' : cell.desc;
  td.querySelector('.rm-color-swatch').style.background = cell.color;
  // Update data-args for gear button
  const gearBtn = td.querySelector('.rm-edit-btn');
  if (gearBtn) gearBtn.dataset.args = JSON.stringify([key, cell.title, cell.desc, cell.color]);
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCellEdit(); });
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
