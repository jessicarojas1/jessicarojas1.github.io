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

        <!-- Basic Info -->
        <div class="section-header" style="margin-bottom:12px">
          <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6366f1"><i class="bi bi-info-circle-fill"></i> Basic Information</span>
        </div>

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
            <label class="form-label">Risk Source</label>
            <select name="risk_source" class="form-control">
              <option value="">— Select source —</option>
              <?php foreach (['strategic'=>'Strategic','operational'=>'Operational','financial'=>'Financial','compliance'=>'Compliance','technology'=>'Technology','reputational'=>'Reputational','external'=>'External','people'=>'People','project'=>'Project'] as $sv=>$sl): ?>
                <option value="<?= $sv ?>"><?= $sl ?></option>
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
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Review Date</label>
            <input type="date" name="review_date" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Parent Risk</label>
            <select name="parent_risk_id" class="form-control">
              <option value="">No parent</option>
              <?php foreach ($parentRisks as $pr): ?>
                <option value="<?= $pr['id'] ?>">[<?= Security::h($pr['risk_id'] ?? '—') ?>] <?= Security::h($pr['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3" placeholder="Describe the risk scenario, potential impact, and affected assets..."></textarea>
        </div>

        <!-- Risk Scoring -->
        <div class="risk-scoring-section" style="margin-top:20px">
          <div class="section-header" style="margin-bottom:12px">
            <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6366f1"><i class="bi bi-grid-3x3-gap-fill"></i> Risk Scoring</span>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Likelihood (1–5)</label>
              <div class="slider-group">
                <input type="range" name="likelihood" min="1" max="5" value="3" data-input="updateScore" id="likelihood">
                <div class="slider-labels"><span>1-Rare</span><span>3-Possible</span><span>5-Certain</span></div>
                <div class="slider-value" id="likelihoodVal">3</div>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Impact (1–5)</label>
              <div class="slider-group">
                <input type="range" name="impact" min="1" max="5" value="3" data-input="updateScore" id="impact">
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

        <!-- Target (Residual) Score -->
        <div style="margin-top:16px;padding:14px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--border)">
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px"><i class="bi bi-bullseye"></i> Target Residual Score (after full treatment)</div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Target Likelihood</label>
              <select name="target_likelihood" class="form-control">
                <option value="">Same as inherent</option>
                <option value="1">1 – Rare</option>
                <option value="2">2 – Unlikely</option>
                <option value="3">3 – Possible</option>
                <option value="4">4 – Likely</option>
                <option value="5">5 – Almost Certain</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Target Impact</label>
              <select name="target_impact" class="form-control">
                <option value="">Same as inherent</option>
                <option value="1">1 – Negligible</option>
                <option value="2">2 – Minor</option>
                <option value="3">3 – Moderate</option>
                <option value="4">4 – Major</option>
                <option value="5">5 – Critical</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Response Strategy -->
        <div style="margin-top:20px">
          <div class="section-header" style="margin-bottom:12px">
            <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6366f1"><i class="bi bi-shield-fill-check"></i> Response Strategy</span>
          </div>
          <div class="form-group">
            <label class="form-label">Treatment Approach <span class="form-hint" style="margin:0">(select all that apply)</span></label>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:6px" id="strategyGrid">
              <?php foreach (['mitigate'=>'Mitigate','accept'=>'Accept','transfer'=>'Transfer','avoid'=>'Avoid'] as $k=>$l): ?>
              <label style="display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 8px;border-radius:8px;border:2px solid var(--border);background:var(--bg-secondary);cursor:pointer;text-align:center;transition:all .15s" class="strat-lbl" id="slbl_<?= $k ?>">
                <input type="checkbox" name="treatment_strategies[]" value="<?= $k ?>" style="position:absolute;opacity:0" data-change="toggleStrat" data-arg="<?= $k ?>">
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
        </div>

        <!-- Enterprise Attributes -->
        <div style="margin-top:20px">
          <div class="section-header" style="margin-bottom:12px">
            <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6366f1"><i class="bi bi-bar-chart-fill"></i> Risk Attributes</span>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Velocity <span class="form-hint" style="margin:0">(how fast could this materialise)</span></label>
              <div class="slider-group">
                <input type="range" name="velocity" min="1" max="5" value="3" data-value-display="velocityVal" id="velocitySlider">
                <div class="slider-labels"><span>1-Slow</span><span>3-Medium</span><span>5-Rapid</span></div>
                <div class="slider-value" id="velocityVal">3</div>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Time Horizon</label>
              <select name="proximity" class="form-control">
                <option value="immediate">Immediate (now)</option>
                <option value="short_term">Short-term (< 6 mo)</option>
                <option value="medium_term" selected>Medium-term (6–18 mo)</option>
                <option value="long_term">Long-term (> 18 mo)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Confidence in Assessment</label>
              <select name="confidence" class="form-control">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Financial Exposure -->
        <div style="margin-top:16px;padding:14px;background:var(--bg-secondary);border-radius:8px;border:1px solid var(--border)">
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px"><i class="bi bi-currency-dollar"></i> Financial Exposure (optional)</div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Currency</label>
              <select name="financial_currency" class="form-control">
                <option value="USD" selected>USD</option>
                <option value="EUR">EUR</option>
                <option value="GBP">GBP</option>
                <option value="CAD">CAD</option>
                <option value="AUD">AUD</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Minimum Loss</label>
              <input type="number" name="financial_min" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="form-group">
              <label class="form-label">Likely Loss</label>
              <input type="number" name="financial_likely" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
            <div class="form-group">
              <label class="form-label">Maximum Loss</label>
              <input type="number" name="financial_max" class="form-control" placeholder="0.00" min="0" step="0.01">
            </div>
          </div>
        </div>

        <div class="form-actions" style="margin-top:20px">
          <button type="submit" class="btn btn-danger"><i class="bi bi-plus-lg"></i> Log Risk</button>
          <a href="/risk" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:16px">
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

    <!-- Velocity guide -->
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-lightning-fill"></i> Velocity Guide</h3></div>
      <div class="card-body" style="font-size:13px">
        <table style="width:100%;border-collapse:collapse">
          <?php foreach ([
            1 => ['Slow','Years to materialise'],
            2 => ['Low','12–18 months'],
            3 => ['Medium','3–12 months'],
            4 => ['High','1–3 months'],
            5 => ['Rapid','Imminent / days'],
          ] as $v => [$l, $d]): ?>
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:5px 6px;font-weight:700;color:#6366f1;width:20px"><?= $v ?></td>
            <td style="padding:5px 6px;font-weight:600"><?= $l ?></td>
            <td style="padding:5px 6px;color:var(--text-muted)"><?= $d ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
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
