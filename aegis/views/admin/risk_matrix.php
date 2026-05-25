<?php
$pageTitle    = 'Risk Matrix Config';
$activeModule = 'admin_risk_matrix';
$breadcrumbs  = [['Admin','/admin'],['Risk Matrix',null]];
$cfg = $matrix;
$rowLabels = implode(', ', json_decode($cfg['row_labels'], true));
$colLabels = implode(', ', json_decode($cfg['col_labels'], true));
$thresholds = json_decode($cfg['thresholds'], true);
$colors     = json_decode($cfg['colors'], true);
ob_start();
?>

<?php if (!empty($_GET['saved'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Risk matrix updated.</div><?php endif; ?>

<div class="page-header">
  <h1 class="page-title">Risk Matrix Configuration</h1>
  <a href="/risk/matrix" class="btn btn-ghost"><i class="bi bi-eye"></i> Preview Matrix</a>
</div>

<div class="two-col-layout">
  <div class="card form-page">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-sliders"></i> Matrix Settings</h3></div>
    <div class="card-body">
      <form method="POST" action="/admin/risk-matrix/update">
        <?= Security::csrfField() ?>
        <input type="hidden" name="matrix_id" value="<?= $cfg['id'] ?>">

        <div class="form-group">
          <label class="form-label">Row Labels (Likelihood) — comma separated</label>
          <input type="text" name="row_labels" class="form-control" value="<?= Security::h($rowLabels) ?>">
          <div class="form-hint">From low to high. Number of labels = matrix rows.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Column Labels (Impact) — comma separated</label>
          <input type="text" name="col_labels" class="form-control" value="<?= Security::h($colLabels) ?>">
          <div class="form-hint">From low to high. Number of labels = matrix columns.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Risk Thresholds</label>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label text-sm" style="color:#22c55e">Low ≤</label>
              <input type="number" name="thresh_low" class="form-control" value="<?= $thresholds['low'] ?>" min="1" max="25">
            </div>
            <div class="form-group">
              <label class="form-label text-sm" style="color:#f59e0b">Medium ≤</label>
              <input type="number" name="thresh_medium" class="form-control" value="<?= $thresholds['medium'] ?>" min="1" max="25">
            </div>
            <div class="form-group">
              <label class="form-label text-sm" style="color:#f97316">High ≤</label>
              <input type="number" name="thresh_high" class="form-control" value="<?= $thresholds['high'] ?>" min="1" max="25">
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Risk Level Colors</label>
          <div class="form-row">
            <?php foreach (['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'] as $k=>$l): ?>
              <div class="form-group" style="text-align:center">
                <label class="form-label text-sm"><?= $l ?></label>
                <input type="color" name="color_<?= $k ?>" value="<?= Security::h($colors[$k] ?? '#000000') ?>" class="color-input">
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill"></i> Save Configuration</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Live preview -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-eye"></i> Current Matrix Preview</h3></div>
    <div class="card-body">
      <div class="matrix-preview" id="matrixPreview">
        <?php
        $rl = json_decode($cfg['row_labels'], true);
        $cl = json_decode($cfg['col_labels'], true);
        $r = count($rl); $c = count($cl);
        for ($row = $r; $row >= 1; $row--): ?>
          <div style="display:flex;gap:2px;margin-bottom:2px">
            <div style="width:60px;font-size:10px;display:flex;align-items:center;justify-content:flex-end;padding-right:6px;color:#64748b"><?= Security::h($rl[$row-1] ?? $row) ?></div>
            <?php for ($col = 1; $col <= $c; $col++):
              $score = $row * $col;
              $bg = $score > $thresholds['high'] ? $colors['critical'] : ($score > $thresholds['medium'] ? $colors['high'] : ($score > $thresholds['low'] ? $colors['medium'] : $colors['low']));
              $bgSafe = Security::h($bg);
            ?>
              <div style="flex:1;height:40px;border-radius:4px;background:<?= $bgSafe ?>30;border:1px solid <?= $bgSafe ?>50;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:<?= $bgSafe ?>">
                <?= $score ?>
              </div>
            <?php endfor; ?>
          </div>
        <?php endfor; ?>
        <div style="display:flex;gap:2px;margin-top:4px">
          <div style="width:60px"></div>
          <?php for ($col = 1; $col <= $c; $col++): ?>
            <div style="flex:1;text-align:center;font-size:10px;color:#64748b"><?= Security::h($cl[$col-1] ?? $col) ?></div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
