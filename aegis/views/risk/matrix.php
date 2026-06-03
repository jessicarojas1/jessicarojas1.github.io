<?php
$pageTitle    = 'Risk Matrix';
$activeModule = 'risk_matrix';
$breadcrumbs  = [['Risk Register','/risk'],['Matrix',null]];
$cfg          = $matrixConfig;
$rowLabels    = json_decode($cfg['row_labels'], true);
$colLabels    = json_decode($cfg['col_labels'], true);
$thresholds   = json_decode($cfg['thresholds'], true);
$colors       = json_decode($cfg['colors'], true);
$cells        = json_decode($cfg['cells'] ?? '{}', true) ?: [];
$rows         = (int)$cfg['rows'];
$cols         = (int)$cfg['cols'];

function getCellData(int $r, int $c, array $cells, array $thresholds, array $colors): array {
  $key = "{$r}_{$c}";
  if (!empty($cells[$key])) { return $cells[$key]; }
  // Fallback to threshold-based color
  $score = $r * $c;
  $color = $score > $thresholds['high'] ? $colors['critical']
         : ($score > $thresholds['medium'] ? $colors['high']
         : ($score > $thresholds['low']    ? $colors['medium'] : $colors['low']));
  return ['title' => '', 'desc' => '', 'color' => $color];
}

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Matrix</h1>
    <p class="page-subtitle">Visual heatmap of organizational risk exposure</p>
  </div>
  <div class="page-actions">
    <a href="/admin/risk-matrix" class="btn btn-ghost"><i class="bi bi-sliders"></i> Configure</a>
    <a href="/risk/create" class="btn btn-danger"><i class="bi bi-plus-lg"></i> Log Risk</a>
  </div>
</div>

<!-- Legend -->
<div class="matrix-legend card">
  <?php foreach (['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'] as $key=>$label): ?>
    <div class="legend-item">
      <div class="legend-swatch" style="background:<?= $colors[$key] ?>"></div>
      <span><?= $label ?></span>
    </div>
  <?php endforeach; ?>
  <div class="legend-sep">|</div>
  <span class="text-muted text-sm"><?= count($risks) ?> active risks plotted</span>
</div>

<div class="matrix-layout">
  <!-- Matrix grid -->
  <div class="card matrix-card">
    <div class="card-body">
      <div class="risk-matrix-wrap">
        <!-- Y-axis label -->
        <div class="matrix-y-label"><?= Security::h($cfg['row_label']) ?> →</div>

        <div class="matrix-inner">
          <!-- Column headers -->
          <div class="matrix-header-row">
            <div class="matrix-corner"></div>
            <?php for ($c = 1; $c <= $cols; $c++): ?>
              <div class="matrix-col-header">
                <?= Security::h($colLabels[$c-1] ?? $c) ?>
                <span class="matrix-idx-badge">[<?= $c ?>]</span>
              </div>
            <?php endfor; ?>
          </div>

          <!-- Matrix rows (high likelihood at top) -->
          <?php for ($r = $rows; $r >= 1; $r--):
            $displayIdx = $r - 1;
            $rowLabel   = $rowLabels[$r-1] ?? "Level $r";
          ?>
            <div class="matrix-row">
              <div class="matrix-row-header">
                <div><?= Security::h($rowLabel) ?></div>
                <div class="matrix-idx-badge">[<?= $displayIdx ?>]</div>
              </div>
              <?php for ($c = 1; $c <= $cols; $c++):
                $cd        = getCellData($r, $c, $cells, $thresholds, $colors);
                $cellColor = htmlspecialchars($cd['color'], ENT_QUOTES, 'UTF-8');
                $cellRisks = array_filter($risks, fn($risk) => (int)$risk['likelihood'] === $r && (int)$risk['impact'] === $c);
              ?>
                <div class="matrix-cell" style="background:<?= $cellColor ?>20;border:1px solid <?= $cellColor ?>40"
                     data-r="<?= $r ?>" data-c="<?= $c ?>" data-click="showCellRisks" data-args='[<?= $r ?>,<?= $c ?>]'>
                  <?php if (!empty($cd['title'])): ?>
                    <div class="cell-treatment" style="color:<?= $cellColor ?>"><?= Security::h($cd['title']) ?></div>
                  <?php endif; ?>
                  <?php if ($cellRisks): ?>
                    <div class="cell-risks">
                      <?php foreach (array_slice($cellRisks, 0, 3) as $cr): ?>
                        <div class="cell-risk-dot" title="<?= Security::h($cr['title']) ?>" style="background:<?= Security::h($cr['category_color'] ?? $cellColor) ?>"></div>
                      <?php endforeach; ?>
                      <?php if (count($cellRisks) > 3): ?>
                        <div class="cell-risk-more">+<?= count($cellRisks)-3 ?></div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endfor; ?>
            </div>
          <?php endfor; ?>
        </div>

        <!-- X-axis label -->
        <div class="matrix-x-label">← <?= Security::h($cfg['col_label']) ?></div>
      </div>
    </div>
  </div>

  <!-- Risk list by level -->
  <div class="matrix-sidebar">
    <?php
    $leveledRisks = [
      'critical' => array_filter($risks, fn($r) => $r['inherent_score'] > 14),
      'high'     => array_filter($risks, fn($r) => $r['inherent_score'] > 9 && $r['inherent_score'] <= 14),
      'medium'   => array_filter($risks, fn($r) => $r['inherent_score'] > 4 && $r['inherent_score'] <= 9),
      'low'      => array_filter($risks, fn($r) => $r['inherent_score'] <= 4),
    ];
    foreach ($leveledRisks as $level => $levelRisks): if ($levelRisks): ?>
      <div class="card matrix-risk-group">
        <div class="card-header">
          <span class="risk-badge risk-<?= $level ?>"><?= ucfirst($level) ?></span>
          <span class="badge"><?= count($levelRisks) ?></span>
        </div>
        <div class="card-body p0">
          <?php foreach ($levelRisks as $risk): ?>
            <a href="/risk/<?= $risk['id'] ?>" class="matrix-risk-item">
              <div class="matrix-risk-score" style="background:<?= $colors[$level] ?>20;color:<?= $colors[$level] ?>"><?= $risk['inherent_score'] ?></div>
              <div class="matrix-risk-info">
                <div class="matrix-risk-title"><?= Security::h($risk['title']) ?></div>
                <div class="matrix-risk-id mono"><?= Security::h($risk['risk_id'] ?? '') ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; endforeach; ?>

    <?php if (!$risks): ?>
      <div class="empty-state card"><div class="empty-icon"><i class="bi bi-shield-check"></i></div><h3>No active risks</h3><a href="/risk/create" class="btn btn-danger btn-sm">Log a Risk</a></div>
    <?php endif; ?>
  </div>
</div>

<!-- Cell detail modal -->
<div class="modal-overlay" id="cellModal" style="display:none">
  <div class="modal">
    <div class="modal-header"><h3 id="cellModalTitle">Risks</h3><button data-click="closeCellModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body" id="cellModalBody"></div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
const allRisks = <?= json_encode(array_values($risks)) ?>;
const colors   = <?= json_encode($colors) ?>;

function riskLevel(score) {
  return score > 14 ? 'critical' : score > 9 ? 'high' : score > 4 ? 'medium' : 'low';
}

function showCellRisks(r, c) {
  const cell  = allRisks.filter(risk => parseInt(risk.likelihood) === r && parseInt(risk.impact) === c);
  const score = r * c;
  const level = riskLevel(score);
  document.getElementById('cellModalTitle').textContent = `L${r} × I${c} = ${score} (${level.charAt(0).toUpperCase()+level.slice(1)})`;

  if (!cell.length) {
    document.getElementById('cellModalBody').innerHTML = '<p class="text-muted">No risks in this cell.</p>';
  } else {
    document.getElementById('cellModalBody').innerHTML = cell.map(risk =>
      `<a href="/risk/${risk.id}" class="matrix-risk-item" style="display:flex;align-items:center;gap:12px;padding:12px;border-bottom:1px solid #e2e8f0;text-decoration:none;color:inherit">
        <div style="min-width:40px;height:40px;border-radius:8px;background:${colors[level]}20;color:${colors[level]};display:flex;align-items:center;justify-content:center;font-weight:700">${risk.inherent_score}</div>
        <div>
          <div style="font-weight:600">${risk.title}</div>
          <div style="font-size:12px;color:#64748b">${risk.risk_id || ''} · ${risk.category_name || 'Uncategorized'}</div>
        </div>
      </a>`
    ).join('');
  }
  document.getElementById('cellModal').style.display = 'flex';
}

function closeCellModal() { document.getElementById('cellModal').style.display = 'none'; }
document.getElementById('cellModal').addEventListener('click', function(e) {
  if (e.target === this) closeCellModal();
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
