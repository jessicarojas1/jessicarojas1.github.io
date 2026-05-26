<?php
$pageTitle    = 'Log Risk';
$activeModule = 'risk';
$breadcrumbs  = [['Risk Register','/risk'],['New Risk',null]];
ob_start();
?>

<div class="page-header">
  <h1 class="page-title">Log New Risk</h1>
  <a href="/risk" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($_SESSION['risk_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['risk_error']) ?></div>
  <?php unset($_SESSION['risk_error']); ?>
<?php endif; ?>

<div class="two-col-layout">
  <div class="form-page card" style="flex:2">
    <div class="card-body">
      <form method="POST" action="/risk/create">
        <?= Security::csrfField() ?>

        <div class="form-row">
          <div class="form-group flex-2">
            <label class="form-label required">Risk Title</label>
            <input type="text" name="title" class="form-control" placeholder="Describe the risk in one sentence..." required>
          </div>
          <div class="form-group">
            <label class="form-label">Risk ID</label>
            <input type="text" name="risk_id" class="form-control" placeholder="Auto-generated if blank">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-control">
              <option value="">Uncategorized</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= Security::h($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Risk Owner</label>
            <select name="owner_id" class="form-control">
              <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $u['id']===Auth::id()?'selected':'' ?>><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Review Date</label>
            <input type="date" name="review_date" class="form-control">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="4" placeholder="Describe the risk scenario, potential impact, and affected assets..."></textarea>
        </div>

        <!-- Risk Scoring -->
        <div class="risk-scoring-section">
          <h4 class="section-title"><i class="bi bi-grid-3x3-gap-fill"></i> Risk Scoring</h4>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Likelihood (1–5)</label>
              <div class="slider-group">
                <input type="range" name="likelihood" min="1" max="5" value="3" oninput="updateScore()" id="likelihood">
                <div class="slider-labels"><span>1-Rare</span><span>3-Possible</span><span>5-Certain</span></div>
                <div class="slider-value" id="likelihoodVal">3</div>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Impact (1–5)</label>
              <div class="slider-group">
                <input type="range" name="impact" min="1" max="5" value="3" oninput="updateScore()" id="impact">
                <div class="slider-labels"><span>1-Negligible</span><span>3-Moderate</span><span>5-Critical</span></div>
                <div class="slider-value" id="impactVal">3</div>
              </div>
            </div>
          </div>
          <div class="score-preview">
            <div class="score-display" id="scoreDisplay">
              <div class="score-num" id="scoreNum">9</div>
              <div class="score-label" id="scoreLabel">Medium</div>
            </div>
            <div class="score-hint">Score = Likelihood × Impact (max 25)</div>
          </div>
        </div>

        <!-- Response Strategy -->
        <div class="form-group" style="margin-top:4px">
          <label class="form-label">Response Strategy <span class="form-hint" style="margin:0">(select all that apply)</span></label>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:6px" id="strategyGrid">
            <?php foreach (['mitigate'=>'Mitigate','accept'=>'Accept','transfer'=>'Transfer','avoid'=>'Avoid'] as $k=>$l): ?>
            <label style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 8px;border-radius:8px;border:2px solid var(--border);background:var(--bg-secondary);cursor:pointer;text-align:center;transition:all .15s" class="strat-lbl" id="slbl_<?= $k ?>">
              <input type="checkbox" name="treatment_strategies[]" value="<?= $k ?>" style="position:absolute;opacity:0" onchange="toggleStrat('<?= $k ?>')">
              <span style="font-size:18px"><?= match($k){'mitigate'=>'🛡️','accept'=>'✅','transfer'=>'↔️','avoid'=>'🚫'} ?></span>
              <span style="font-weight:600;font-size:13px"><?= $l ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Treatment Notes</label>
          <input type="text" name="treatment_description" class="form-control" placeholder="Briefly describe your response approach...">
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-danger"><i class="bi bi-plus-lg"></i> Log Risk</button>
          <a href="/risk" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Risk level guide -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> Risk Level Guide</h3></div>
    <div class="card-body">
      <div class="risk-guide">
        <div class="guide-item critical"><span class="risk-badge risk-critical">Critical</span><span>Score > 14</span></div>
        <div class="guide-item high"><span class="risk-badge risk-high">High</span><span>Score 10–14</span></div>
        <div class="guide-item medium"><span class="risk-badge risk-medium">Medium</span><span>Score 5–9</span></div>
        <div class="guide-item low"><span class="risk-badge risk-low">Low</span><span>Score ≤ 4</span></div>
      </div>
      <div class="mini-matrix" id="miniMatrix"></div>
    </div>
  </div>
</div>

<script>
function toggleStrat(key) {
  const lbl = document.getElementById('slbl_' + key);
  const cb  = lbl.querySelector('input');
  if (cb.checked) {
    lbl.style.borderColor = 'var(--primary)';
    lbl.style.background  = 'color-mix(in srgb, var(--primary) 10%, transparent)';
  } else {
    lbl.style.borderColor = 'var(--border)';
    lbl.style.background  = 'var(--bg-secondary)';
  }
}
function updateScore() {
  const l = parseInt(document.getElementById('likelihood').value);
  const i = parseInt(document.getElementById('impact').value);
  document.getElementById('likelihoodVal').textContent = l;
  document.getElementById('impactVal').textContent = i;
  const score = l * i;
  document.getElementById('scoreNum').textContent = score;
  const level = score > 14 ? 'Critical' : score > 9 ? 'High' : score > 4 ? 'Medium' : 'Low';
  const colors = { Critical:'#ef4444', High:'#f97316', Medium:'#f59e0b', Low:'#22c55e' };
  document.getElementById('scoreLabel').textContent = level;
  document.getElementById('scoreDisplay').style.background = colors[level] + '20';
  document.getElementById('scoreDisplay').style.borderColor = colors[level] + '50';
  document.getElementById('scoreNum').style.color = colors[level];
  document.getElementById('scoreLabel').style.color = colors[level];
}
updateScore();
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
