<?php
$pageTitle    = $risk['title'];
$activeModule = 'risk';
$breadcrumbs  = [['Risk Register','/risk'],[$risk['risk_id'] ?? 'Risk',null]];
ob_start();
?>

<?php if (!empty($_GET['saved'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Risk updated.</div><?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($risk['title']) ?></h1>
    <p class="page-subtitle"><span class="mono"><?= Security::h($risk['risk_id'] ?? '') ?></span> · <?= Security::h($risk['category_name'] ?? 'Uncategorized') ?> · <?= Security::h($risk['owner_name'] ?? 'Unassigned') ?></p>
  </div>
  <div class="page-actions">
    <?php
    $level = $risk['inherent_score'] > 14 ? 'Critical' : ($risk['inherent_score'] > 9 ? 'High' : ($risk['inherent_score'] > 4 ? 'Medium' : 'Low'));
    $levelColors = ['Critical'=>'#ef4444','High'=>'#f97316','Medium'=>'#f59e0b','Low'=>'#22c55e'];
    $lc = $levelColors[$level];
    ?>
    <span class="risk-badge-lg risk-<?= strtolower($level) ?>"><?= $level ?> Risk</span>
    <a href="/risk" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<div class="risk-layout">
  <!-- Edit form -->
  <div class="risk-main">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-sliders"></i> Risk Assessment</h3>
        <span class="badge badge-<?= $risk['status'] ?>"><?= ucfirst($risk['status']) ?></span>
      </div>
      <div class="card-body">
        <form method="POST" action="/risk/<?= $risk['id'] ?>/update">
          <?= Security::csrfField() ?>

          <?php if ($risk['description']): ?>
            <div class="risk-description-box">
              <p><?= Security::h($risk['description']) ?></p>
            </div>
          <?php endif; ?>

          <div class="risk-scoring-grid">
            <div class="scoring-col inherent">
              <h4>Inherent Risk</h4>
              <div class="form-group">
                <label class="form-label">Likelihood</label>
                <input type="range" name="likelihood" min="1" max="5" value="<?= $risk['likelihood'] ?>" oninput="updateScores()" id="likelihood" class="risk-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="lVal"><?= $risk['likelihood'] ?></div>
              </div>
              <div class="form-group">
                <label class="form-label">Impact</label>
                <input type="range" name="impact" min="1" max="5" value="<?= $risk['impact'] ?>" oninput="updateScores()" id="impact" class="risk-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="iVal"><?= $risk['impact'] ?></div>
              </div>
              <div class="score-chip" id="inherentChip">
                <div id="inherentScore"><?= $risk['inherent_score'] ?></div>
                <div id="inherentLevel"><?= $level ?></div>
              </div>
            </div>

            <div class="scoring-arrow"><i class="bi bi-arrow-right"></i><span>Treatment</span></div>

            <div class="scoring-col residual">
              <h4>Residual Risk</h4>
              <div class="form-group">
                <label class="form-label">Residual Likelihood</label>
                <input type="range" name="residual_likelihood" min="1" max="5" value="<?= $risk['residual_likelihood'] ?? $risk['likelihood'] ?>" oninput="updateScores()" id="resLikelihood" class="risk-slider res-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="rlVal"><?= $risk['residual_likelihood'] ?? $risk['likelihood'] ?></div>
              </div>
              <div class="form-group">
                <label class="form-label">Residual Impact</label>
                <input type="range" name="residual_impact" min="1" max="5" value="<?= $risk['residual_impact'] ?? $risk['impact'] ?>" oninput="updateScores()" id="resImpact" class="risk-slider res-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="riVal"><?= $risk['residual_impact'] ?? $risk['impact'] ?></div>
              </div>
              <div class="score-chip residual-chip" id="residualChip">
                <div id="residualScore"><?= $risk['residual_score'] ?? $risk['inherent_score'] ?></div>
                <div id="residualLevel">-</div>
              </div>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <?php foreach (['open'=>'Open','accepted'=>'Accepted','mitigated'=>'Mitigated','closed'=>'Closed','transferred'=>'Transferred'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= $risk['status']===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Treatment Type</label>
              <select name="treatment_type" class="form-control">
                <option value="">—</option>
                <?php foreach (['mitigate','accept','avoid','transfer'] as $t): ?>
                  <option value="<?= $t ?>" <?= ($risk['treatment_type']??'')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Review Date</label>
              <input type="date" name="review_date" class="form-control" value="<?= Security::h($risk['review_date'] ?? '') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Treatment Description</label>
            <textarea name="treatment_description" class="form-control" rows="3"><?= Security::h($risk['treatment_description'] ?? '') ?></textarea>
          </div>

          <!-- Add treatment action -->
          <div class="treatment-add-box">
            <h4><i class="bi bi-plus-circle"></i> Add Treatment Action</h4>
            <input type="hidden" name="add_treatment" value="1">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Type</label>
                <select name="treat_type" class="form-control">
                  <option value="mitigate">Mitigate</option><option value="accept">Accept</option>
                  <option value="avoid">Avoid</option><option value="transfer">Transfer</option>
                </select>
              </div>
              <div class="form-group flex-2">
                <label class="form-label">Action Description</label>
                <input type="text" name="treat_desc" class="form-control" placeholder="e.g. Implement MFA on all admin accounts">
              </div>
              <div class="form-group">
                <label class="form-label">Due Date</label>
                <input type="date" name="treat_due" class="form-control">
              </div>
            </div>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill"></i> Update Risk</button>
            <form method="POST" action="/risk/<?= $risk['id'] ?>/delete" style="display:inline">
              <?= Security::csrfField() ?>
              <button class="btn btn-ghost text-danger" onclick="return confirm('Delete this risk permanently?')"><i class="bi bi-trash"></i> Delete</button>
            </form>
          </div>
        </form>
      </div>
    </div>

    <!-- Treatment history -->
    <?php if ($treatments): ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-list-check"></i> Treatment Actions</h3></div>
      <div class="card-body p0">
        <?php foreach ($treatments as $t): ?>
          <div class="treatment-item">
            <span class="treatment-type-badge"><?= ucfirst($t['treatment_type']) ?></span>
            <div class="treatment-body">
              <div><?= Security::h($t['description']) ?></div>
              <div class="text-muted text-sm"><?= Security::h($t['owner_name'] ?? 'Unassigned') ?> <?= $t['due_date'] ? '· Due '.date('M j, Y',strtotime($t['due_date'])) : '' ?></div>
            </div>
            <span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sidebar info -->
  <div class="risk-sidebar">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> Risk Info</h3></div>
      <div class="card-body">
        <div class="detail-row"><span>Risk ID</span><strong class="mono"><?= Security::h($risk['risk_id'] ?? '—') ?></strong></div>
        <div class="detail-row"><span>Category</span>
          <?php if ($risk['category_name']): ?>
            <span style="display:flex;align-items:center;gap:6px"><span class="category-dot" style="background:<?= Security::h($risk['category_color'] ?? '#666') ?>"></span><?= Security::h($risk['category_name']) ?></span>
          <?php else: ?><strong>Uncategorized</strong><?php endif; ?>
        </div>
        <div class="detail-row"><span>Owner</span><strong><?= Security::h($risk['owner_name'] ?? 'Unassigned') ?></strong></div>
        <div class="detail-row"><span>Identified</span><strong><?= date('M j, Y', strtotime($risk['identified_date'])) ?></strong></div>
        <div class="detail-row"><span>Created by</span><strong><?= Security::h($risk['created_by_name'] ?? '—') ?></strong></div>
        <div class="detail-row"><span>Last updated</span><strong><?= date('M j, Y', strtotime($risk['updated_at'])) ?></strong></div>
      </div>
    </div>
  </div>
</div>

<script>
const levelColors = { Critical:'#ef4444', High:'#f97316', Medium:'#f59e0b', Low:'#22c55e' };
function getLevel(score) { return score > 14 ? 'Critical' : score > 9 ? 'High' : score > 4 ? 'Medium' : 'Low'; }
function updateScores() {
  const l = parseInt(document.getElementById('likelihood').value);
  const i = parseInt(document.getElementById('impact').value);
  const rl = parseInt(document.getElementById('resLikelihood').value);
  const ri = parseInt(document.getElementById('resImpact').value);
  document.getElementById('lVal').textContent = l;
  document.getElementById('iVal').textContent = i;
  document.getElementById('rlVal').textContent = rl;
  document.getElementById('riVal').textContent = ri;
  const iScore = l * i, rScore = rl * ri;
  const iLevel = getLevel(iScore), rLevel = getLevel(rScore);
  document.getElementById('inherentScore').textContent = iScore;
  document.getElementById('inherentLevel').textContent = iLevel;
  document.getElementById('inherentChip').style.background = levelColors[iLevel] + '20';
  document.getElementById('inherentChip').style.color = levelColors[iLevel];
  document.getElementById('residualScore').textContent = rScore;
  document.getElementById('residualLevel').textContent = rLevel;
  document.getElementById('residualChip').style.background = levelColors[rLevel] + '20';
  document.getElementById('residualChip').style.color = levelColors[rLevel];
}
updateScores();
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
