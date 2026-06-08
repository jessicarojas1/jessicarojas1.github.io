<?php
/**
 * views/risk/scenario_form.php
 * Create a risk scenario for a given risk.
 * Variables: $risk (risk record), $pageTitle, $activeModule, $breadcrumbs
 */
$breadcrumbs = $breadcrumbs ?? [['Risk Register', '/risk'], ['Scenarios', null]];
$nonce     = Security::nonce();
$baseL     = (int)$risk['likelihood'];
$baseI     = (int)$risk['impact'];
$baseScore = $baseL * $baseI;

function scenarioLevelStr(int $s): string {
    return $s > 14 ? 'Critical' : ($s > 9 ? 'High' : ($s > 4 ? 'Medium' : 'Low'));
}
function scenarioLevelColor(int $s): string {
    return $s > 14 ? '#ef4444' : ($s > 9 ? '#f97316' : ($s > 4 ? '#f59e0b' : '#22c55e'));
}
function scenarioLevelClass(int $s): string {
    return $s > 14 ? 'risk-critical' : ($s > 9 ? 'risk-high' : ($s > 4 ? 'risk-medium' : 'risk-low'));
}
?>
<style nonce="<?= $nonce ?>">
.scenario-layout{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start}
.scenario-preview{background:var(--bg-card);border:2px solid var(--border);border-radius:12px;padding:20px;position:sticky;top:20px}
.scenario-preview-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:14px}
.preview-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.preview-row:last-child{border-bottom:none}
.preview-label{color:var(--text-muted);font-weight:500}
.preview-score{font-size:22px;font-weight:900;line-height:1}
.preview-delta{display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:700;padding:2px 8px;border-radius:20px}
.delta-up{background:#dc262618;color:var(--danger)}
.delta-down{background:#d1fae5;color:var(--success)}
.delta-neutral{background:var(--bg-secondary);color:var(--text-muted)}
.multiplier-group{display:flex;flex-direction:column;gap:6px}
.mult-row{display:flex;align-items:center;gap:12px}
.mult-slider{flex:1}
.mult-val{font-size:16px;font-weight:700;color:var(--primary);min-width:36px;text-align:right}
.mult-desc{font-size:11px;color:var(--text-muted);margin-top:2px}
.type-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:6px}
.type-opt{position:relative}
.type-opt input{position:absolute;opacity:0;width:0;height:0}
.type-card{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 6px;border:2px solid var(--border);border-radius:8px;cursor:pointer;text-align:center;font-size:12px;font-weight:600;transition:border-color .15s,background .15s}
.type-card:hover{border-color:var(--primary);background:rgba(55,65,81,.05)}
.type-opt input:checked+.type-card{border-color:var(--accent-color,var(--primary));background:var(--accent-bg,rgba(55,65,81,.05));color:var(--accent-color,var(--primary))}
.ref-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:10px}
.ref-table th,.ref-table td{padding:5px 8px;border:1px solid var(--border)}
.ref-table th{background:var(--bg-secondary);font-weight:600;color:var(--text-muted)}
.fin-ref-box{background:var(--bg-secondary);border-radius:8px;padding:12px;margin-top:12px;font-size:12px}
.fin-ref-row{display:flex;justify-content:space-between;padding:3px 0;color:var(--text-muted)}
.fin-ref-row strong{color:var(--text)}
.section-divider{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin:18px 0 10px;display:flex;align-items:center;gap:8px}
.section-divider::after{content:'';flex:1;height:1px;background:var(--border)}
@media(max-width:768px){.scenario-layout{grid-template-columns:1fr}}
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">New Risk Scenario</h1>
    <p class="page-subtitle" style="margin:4px 0 0;font-size:13px;color:var(--text-muted)">
      Model a hypothetical scenario for
      <strong><?= Security::h($risk['title']) ?></strong>
      <span class="mono" style="font-size:11px;color:var(--text-muted)">(<?= Security::h($risk['risk_id'] ?? '') ?>)</span>
    </p>
  </div>
  <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Risk</a>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
<?php unset($_SESSION['flash_error']); endif; ?>

<form method="POST" action="/risk/<?= (int)$risk['id'] ?>/scenario/create">
  <?= Security::csrfField() ?>

  <div class="scenario-layout">

    <!-- ═══════ LEFT: FORM ═══════ -->
    <div>

      <!-- Basic Info -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-pencil-square"></i> Scenario Details</h3>
        </div>
        <div class="card-body">

          <div class="form-group" style="margin-bottom:14px">
            <label class="form-label required" for="sc_name">Scenario Name</label>
            <input type="text" id="sc_name" name="name" class="form-control"
                   placeholder="e.g. Major Data Breach Under Adverse Conditions"
                   value="<?= Security::h($_POST['name'] ?? '') ?>" required>
          </div>

          <!-- Scenario Type -->
          <div class="form-group" style="margin-bottom:14px">
            <label class="form-label required">Scenario Type</label>
            <div class="type-grid">
              <?php
              $selectedType = $_POST['scenario_type'] ?? 'stress';
              $typeMeta = [
                  'base'          => ['label'=>'Base',         'icon'=>'bi-circle-fill',          'color'=>'#71717a','bg'=>'#f4f4f5'],
                  'stress'        => ['label'=>'Stress',       'icon'=>'bi-graph-up-arrow',        'color'=>'#dc2626','bg'=>'#fef2f2'],
                  'optimistic'    => ['label'=>'Optimistic',   'icon'=>'bi-graph-down-arrow',      'color'=>'#16a34a','bg'=>'#f0fdf4'],
                  'catastrophic'  => ['label'=>'Catastrophic', 'icon'=>'bi-exclamation-octagon-fill','color'=>'#111111','bg'=>'#f9fafb'],
                  'regulatory'    => ['label'=>'Regulatory',   'icon'=>'bi-bank',                  'color'=>'#2563eb','bg'=>'#eff6ff'],
              ];
              foreach ($typeMeta as $val => $meta):
                  $checked = ($selectedType === $val) ? 'checked' : '';
              ?>
              <label class="type-opt" style="--accent-color:<?= $meta['color'] ?>;--accent-bg:<?= $meta['color'] ?>18">
                <input type="radio" name="scenario_type" value="<?= $val ?>" <?= $checked ?> data-change="updatePreview">
                <div class="type-card">
                  <i class="bi <?= $meta['icon'] ?>" style="font-size:18px;color:<?= $meta['color'] ?>"></i>
                  <?= $meta['label'] ?>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:14px">
            <label class="form-label" for="sc_desc">Description</label>
            <textarea id="sc_desc" name="description" class="form-control" rows="3"
                      placeholder="Describe the conditions of this scenario..."><?= Security::h($_POST['description'] ?? '') ?></textarea>
          </div>

          <div class="form-group">
            <label class="form-label" for="sc_assumptions">Assumptions</label>
            <textarea id="sc_assumptions" name="assumptions" class="form-control" rows="3"
                      placeholder="What conditions make this scenario possible? (e.g. controls fail, external attack coincides with system maintenance...)"><?= Security::h($_POST['assumptions'] ?? '') ?></textarea>
            <span class="form-hint">What would need to be true for this scenario to occur?</span>
          </div>

        </div>
      </div>

      <!-- Score Modifiers -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-sliders"></i> Score Modifiers</h3>
          <span style="font-size:12px;color:var(--text-muted)">Adjust multipliers to model scenario severity</span>
        </div>
        <div class="card-body">

          <div class="form-group" style="margin-bottom:18px">
            <label class="form-label">Likelihood Multiplier</label>
            <div class="multiplier-group">
              <div class="mult-row">
                <input type="range" class="mult-slider risk-slider" id="lMult"
                       name="likelihood_multiplier" min="0.1" max="3.0" step="0.1"
                       value="<?= htmlspecialchars($_POST['likelihood_multiplier'] ?? '1.0') ?>"
                       data-input="updatePreview">
                <span class="mult-val" id="lMultVal"><?= htmlspecialchars($_POST['likelihood_multiplier'] ?? '1.0') ?>×</span>
              </div>
              <div class="mult-desc" id="lMultDesc">e.g. 1.5 = 50% more likely than the base assessment</div>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:20px">
            <label class="form-label">Impact Multiplier</label>
            <div class="multiplier-group">
              <div class="mult-row">
                <input type="range" class="mult-slider risk-slider" id="iMult"
                       name="impact_multiplier" min="0.1" max="3.0" step="0.1"
                       value="<?= htmlspecialchars($_POST['impact_multiplier'] ?? '1.0') ?>"
                       data-input="updatePreview">
                <span class="mult-val" id="iMultVal"><?= htmlspecialchars($_POST['impact_multiplier'] ?? '1.0') ?>×</span>
              </div>
              <div class="mult-desc" id="iMultDesc">e.g. 2.0 = double the base impact</div>
            </div>
          </div>

          <!-- Live Preview -->
          <div style="background:var(--bg-secondary);border-radius:10px;padding:16px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:12px">
              <i class="bi bi-eye"></i> Live Score Preview
            </div>
            <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:center">
              <!-- Base -->
              <div style="text-align:center">
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px">Base</div>
                <div style="font-size:12px;color:var(--text-muted)">L=<?= $baseL ?> × I=<?= $baseI ?></div>
                <div class="risk-badge <?= scenarioLevelClass($baseScore) ?>" style="display:inline-block;margin-top:6px;font-size:16px;font-weight:900;padding:4px 12px">
                  <?= $baseScore ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= scenarioLevelStr($baseScore) ?></div>
              </div>
              <!-- Arrow -->
              <div style="text-align:center;color:var(--text-muted)">
                <i class="bi bi-arrow-right" style="font-size:20px"></i>
              </div>
              <!-- Scenario -->
              <div style="text-align:center">
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px">Scenario</div>
                <div style="font-size:12px;color:var(--text-muted)" id="prevScoreLine">L=<?= $baseL ?> × I=<?= $baseI ?></div>
                <div id="prevScoreBadge" class="risk-badge <?= scenarioLevelClass($baseScore) ?>"
                     style="display:inline-block;margin-top:6px;font-size:16px;font-weight:900;padding:4px 12px">
                  <?= $baseScore ?>
                </div>
                <div id="prevScoreLevel" style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= scenarioLevelStr($baseScore) ?></div>
              </div>
            </div>
            <!-- Delta row -->
            <div style="text-align:center;margin-top:12px">
              <span id="prevDelta" class="preview-delta delta-neutral">± 0 points from base</span>
            </div>
          </div>

        </div>
      </div>

      <!-- Financial Impact -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-currency-dollar"></i> Financial Impact</h3>
        </div>
        <div class="card-body">
          <div class="two-col-layout" style="gap:16px">
            <div class="form-group">
              <label class="form-label" for="sc_financial">Estimated Financial Impact ($)</label>
              <input type="number" id="sc_financial" name="financial_impact_est" class="form-control"
                     min="0" step="0.01" placeholder="e.g. 250000"
                     value="<?= htmlspecialchars($_POST['financial_impact_est'] ?? '') ?>">
              <span class="form-hint">Estimated total financial exposure for this scenario</span>
            </div>
            <div class="form-group">
              <label class="form-label" for="sc_probability">Probability of Occurrence (%)</label>
              <div style="display:flex;align-items:center;gap:6px">
                <input type="number" id="sc_probability" name="probability" class="form-control"
                       min="0" max="100" step="0.01" placeholder="e.g. 5.0"
                       value="<?= htmlspecialchars($_POST['probability'] ?? '') ?>"
                       style="flex:1">
                <span style="font-size:14px;font-weight:600;color:var(--text-muted)">%</span>
              </div>
              <span class="form-hint">Estimated chance (0–100%) this scenario occurs</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Submit -->
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px">
        <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy-fill"></i> Save Scenario</button>
      </div>

    </div><!-- /left -->

    <!-- ═══════ RIGHT: SIDEBAR ═══════ -->
    <div>

      <!-- Multiplier Reference -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-info-circle-fill"></i> Multiplier Reference</h3>
        </div>
        <div class="card-body" style="padding-top:12px">
          <p style="font-size:12px;color:var(--text-muted);margin-bottom:8px">
            Multipliers scale the base likelihood and impact values to model scenario severity.
            Results are capped at 5.
          </p>
          <table class="ref-table">
            <thead>
              <tr>
                <th>Multiplier</th>
                <th>Meaning</th>
                <th>Example</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><strong>0.1–0.5</strong></td>
                <td style="color:var(--success)">Much less severe</td>
                <td>Controls highly effective</td>
              </tr>
              <tr>
                <td><strong>0.6–0.9</strong></td>
                <td style="color:var(--info)">Slightly reduced</td>
                <td>Partial mitigation</td>
              </tr>
              <tr>
                <td><strong>1.0</strong></td>
                <td style="color:var(--text-muted)">Baseline</td>
                <td>No change from base</td>
              </tr>
              <tr>
                <td><strong>1.5</strong></td>
                <td style="color:var(--warning)">50% worse</td>
                <td>Controls partially fail</td>
              </tr>
              <tr>
                <td><strong>2.0</strong></td>
                <td style="color:var(--orange)">Double</td>
                <td>Major control failure</td>
              </tr>
              <tr>
                <td><strong>3.0</strong></td>
                <td style="color:var(--danger)">Extreme</td>
                <td>Catastrophic conditions</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Risk Base Financial Reference -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-bar-chart-fill"></i> Base Financial Exposure</h3>
        </div>
        <div class="card-body" style="padding-top:12px">
          <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            Recorded financial exposure range for this risk:
          </p>
          <?php if ($risk['financial_min'] !== null || $risk['financial_likely'] !== null || $risk['financial_max'] !== null): ?>
          <div class="fin-ref-box">
            <?php if ($risk['financial_min'] !== null): ?>
            <div class="fin-ref-row">
              <span>Minimum</span>
              <strong>$<?= number_format((float)$risk['financial_min'], 0) ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($risk['financial_likely'] !== null): ?>
            <div class="fin-ref-row">
              <span>Most Likely</span>
              <strong>$<?= number_format((float)$risk['financial_likely'], 0) ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($risk['financial_max'] !== null): ?>
            <div class="fin-ref-row">
              <span>Maximum</span>
              <strong>$<?= number_format((float)$risk['financial_max'], 0) ?></strong>
            </div>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <p style="font-size:12px;color:var(--text-muted);font-style:italic">No financial exposure data recorded for this risk.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Scenario Type Guide -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-tag-fill"></i> Scenario Types</h3>
        </div>
        <div class="card-body" style="padding-top:12px">
          <?php foreach ($typeMeta as $val => $meta): ?>
          <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px">
            <i class="bi <?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>;font-size:15px;flex-shrink:0;margin-top:1px"></i>
            <div>
              <div style="font-size:12px;font-weight:700;color:<?= $meta['color'] ?>"><?= $meta['label'] ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?php
                $typeDescs = [
                  'base'         => 'Baseline conditions — no change from current assessment.',
                  'stress'       => 'Adverse conditions where likelihood or impact is elevated.',
                  'optimistic'   => 'Best-case where controls are more effective than expected.',
                  'catastrophic' => 'Extreme worst-case combining multiple failure modes.',
                  'regulatory'   => 'Driven by regulatory change or enforcement action.',
                ];
                echo $typeDescs[$val];
              ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /right sidebar -->

  </div><!-- /scenario-layout -->
</form>

<script nonce="<?= $nonce ?>">
(function(){
  const BASE_L = <?= $baseL ?>;
  const BASE_I = <?= $baseI ?>;
  const BASE_SCORE = <?= $baseScore ?>;

  function scoreLevel(s) {
    if (s > 14) return ['Critical','risk-critical'];
    if (s >  9) return ['High','risk-high'];
    if (s >  4) return ['Medium','risk-medium'];
    return ['Low','risk-low'];
  }

  function multDesc(v) {
    if (v <= 0.5) return 'Significantly reduced from base assessment';
    if (v <  1.0) return 'Slightly reduced from base assessment';
    if (v === 1.0) return 'Baseline — no change from current assessment';
    if (v <= 1.5) return Math.round((v-1)*100) + '% more severe than base';
    if (v <= 2.0) return 'Approximately ' + v.toFixed(1) + '× the base value';
    return 'Extreme: ' + v.toFixed(1) + '× the base (capped at 5)';
  }

  window.updatePreview = function() {
    const lSlider = document.getElementById('lMult');
    const iSlider = document.getElementById('iMult');
    if (!lSlider || !iSlider) return;

    const lMult = parseFloat(lSlider.value);
    const iMult = parseFloat(iSlider.value);

    document.getElementById('lMultVal').textContent  = lMult.toFixed(1) + '×';
    document.getElementById('iMultVal').textContent  = iMult.toFixed(1) + '×';
    document.getElementById('lMultDesc').textContent = multDesc(lMult);
    document.getElementById('iMultDesc').textContent = multDesc(iMult);

    const sL = Math.min(5, Math.round(BASE_L * lMult));
    const sI = Math.min(5, Math.round(BASE_I * iMult));
    const sScore = sL * sI;

    document.getElementById('prevScoreLine').textContent = 'L=' + sL + ' × I=' + sI;

    const badge = document.getElementById('prevScoreBadge');
    badge.textContent = sScore;
    badge.className = 'risk-badge ' + scoreLevel(sScore)[1] + ' ' +
      'risk-badge';
    // re-add inline style so it doesn't conflict with class styles
    badge.style.display = 'inline-block';
    badge.style.marginTop = '6px';
    badge.style.fontSize = '16px';
    badge.style.fontWeight = '900';
    badge.style.padding = '4px 12px';

    document.getElementById('prevScoreLevel').textContent = scoreLevel(sScore)[0];

    const delta = sScore - BASE_SCORE;
    const deltaEl = document.getElementById('prevDelta');
    if (delta > 0) {
      deltaEl.className = 'preview-delta delta-up';
      deltaEl.innerHTML = '<i class="bi bi-arrow-up"></i> +' + delta + ' points from base';
    } else if (delta < 0) {
      deltaEl.className = 'preview-delta delta-down';
      deltaEl.innerHTML = '<i class="bi bi-arrow-down"></i> ' + delta + ' points from base';
    } else {
      deltaEl.className = 'preview-delta delta-neutral';
      deltaEl.innerHTML = '&#177; 0 points — same as base';
    }
  };

  // Init
  updatePreview();
})();
</script>
