<?php
$score    = (int)$risk['inherent_score'];
$resScore = (int)($risk['residual_score'] ?? $score);
$tgtScore = ($risk['target_likelihood'] && $risk['target_impact'])
    ? (int)$risk['target_likelihood'] * (int)$risk['target_impact'] : null;

function riskLevelStr(int $s): string {
    return $s > 14 ? 'Critical' : ($s > 9 ? 'High' : ($s > 4 ? 'Medium' : 'Low'));
}
function riskLevelColor(int $s): string {
    return $s > 14 ? '#ef4444' : ($s > 9 ? '#f97316' : ($s > 4 ? '#f59e0b' : '#22c55e'));
}

$level    = riskLevelStr($score);
$resLevel = riskLevelStr($resScore);
$lc       = riskLevelColor($score);

$strategyMeta = [
    'mitigate' => ['label'=>'Mitigate', 'icon'=>'shield-fill-check',  'color'=>'#2563eb', 'hint'=>'Reduce likelihood or impact'],
    'accept'   => ['label'=>'Accept',   'icon'=>'check-circle-fill',   'color'=>'#b45309', 'hint'=>'Formally accept as-is'],
    'transfer' => ['label'=>'Transfer', 'icon'=>'arrow-left-right',    'color'=>'var(--secondary)', 'hint'=>'Insurance or third party'],
    'avoid'    => ['label'=>'Avoid',    'icon'=>'x-octagon-fill',      'color'=>'#dc2626', 'hint'=>'Eliminate the risk source'],
];
$statusLabels = [
    'open'        => ['label'=>'Open',        'color'=>'#dc2626', 'bg'=>'#fef2f2', 'border'=>'#fca5a5'],
    'in_review'   => ['label'=>'In Review',   'color'=>'#2563eb', 'bg'=>'#eff6ff', 'border'=>'#93c5fd'],
    'monitoring'  => ['label'=>'Monitoring',  'color'=>'#16a34a', 'bg'=>'#f0fdf4', 'border'=>'#86efac'],
    'accepted'    => ['label'=>'Accepted',    'color'=>'#d97706', 'bg'=>'#fffbeb', 'border'=>'#fcd34d'],
    'closed'      => ['label'=>'Closed',      'color'=>'#71717a', 'bg'=>'#f4f4f5', 'border'=>'#d4d4d8'],
    'transferred' => ['label'=>'Transferred', 'color'=>'var(--secondary)', 'bg'=>'rgba(55,65,81,.06)', 'border'=>'#d1d5db'],
];
$assessmentMeta = [
    'draft'          => ['label'=>'Draft',          'color'=>'#71717a', 'icon'=>'pencil-fill'],
    'pending_review' => ['label'=>'Pending Review', 'color'=>'#d97706', 'icon'=>'hourglass-split'],
    'approved'       => ['label'=>'Approved',       'color'=>'#16a34a', 'icon'=>'patch-check-fill'],
];
$proximityLabels = ['immediate'=>'Immediate','short_term'=>'Short Term (1–6 mo)','medium_term'=>'Medium Term (6–18 mo)','long_term'=>'Long Term (18+ mo)'];
$velocityLabels  = [1=>'Very Slow',2=>'Slow',3=>'Moderate',4=>'Fast',5=>'Immediate'];
$sourceLabels    = ['strategic'=>'Strategic','operational'=>'Operational','financial'=>'Financial','compliance'=>'Compliance','technology'=>'Technology','reputational'=>'Reputational','external'=>'External','people'=>'People','project'=>'Project'];
$effLabels       = ['none'=>'None','partial'=>'Partial','substantial'=>'Substantial','full'=>'Full'];
$effColors       = ['none'=>'#ef4444','partial'=>'#f59e0b','substantial'=>'#3b82f6','full'=>'#22c55e'];
$actionStatuses  = ['planned'=>'Planned','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];

$st = $statusLabels[$risk['status']] ?? $statusLabels['open'];
$as = $assessmentMeta[$risk['assessment_status']] ?? $assessmentMeta['draft'];

// Score-based strategy suggestion
$suggested = match(true) {
    $score >= 20 => ['mitigate','transfer'],
    $score >= 15 => ['mitigate'],
    $score >= 10 => ['mitigate','accept'],
    $score >= 5  => ['accept','mitigate'],
    default      => ['accept'],
};

$pageTitle   = Security::h($risk['title']);
$activeModule= 'risk';
$breadcrumbs = [['Risk Register','/risk'],[$risk['risk_id'] ?? 'Risk', null]];
ob_start();
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
<?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
<?php unset($_SESSION['flash_error']); endif; ?>

<!-- Page Header -->
<div class="page-header" style="flex-wrap:wrap;gap:12px">
  <div style="min-width:0;flex:1">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">
      <span class="mono text-sm text-muted"><?= Security::h($risk['risk_id'] ?? '') ?></span>
      <!-- Assessment status -->
      <span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;background:<?= $as['color'] ?>18;color:<?= $as['color'] ?>;border:1px solid <?= $as['color'] ?>40">
        <i class="bi bi-<?= $as['icon'] ?>"></i> <?= $as['label'] ?>
      </span>
      <!-- Operational status -->
      <span style="font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px;background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
        <?= $st['label'] ?>
      </span>
      <!-- Strategy tags -->
      <?php foreach ($risk['treatment_strategies_arr'] as $strat):
        $sm = $strategyMeta[$strat] ?? null; if (!$sm) continue; ?>
      <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:<?= $sm['color'] ?>15;color:<?= $sm['color'] ?>;border:1px solid <?= $sm['color'] ?>35">
        <i class="bi bi-<?= $sm['icon'] ?>"></i> <?= $sm['label'] ?>
      </span>
      <?php endforeach; ?>
    </div>
    <h1 class="page-title" style="margin:0"><?= Security::h($risk['title']) ?></h1>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;font-size:13px;color:var(--text-muted)">
      <?php if ($risk['category_name']): ?>
      <span><span class="category-dot" style="background:<?= Security::h($risk['category_color'] ?? '#888') ?>"></span><?= Security::h($risk['category_name']) ?></span>
      <?php endif; ?>
      <span><i class="bi bi-person-fill"></i> <?= Security::h($risk['owner_name'] ?? 'Unassigned') ?></span>
      <?php if ($risk['risk_source']): ?><span><i class="bi bi-tag-fill"></i> <?= Security::h($sourceLabels[$risk['risk_source']] ?? ucfirst($risk['risk_source'])) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="page-actions" style="flex-shrink:0">
    <span class="risk-badge-lg risk-<?= strtolower($level) ?>" style="font-size:14px;padding:6px 14px"><?= $level ?> · <?= $score ?></span>
    <?php if (Auth::can('risk.write')): ?>
      <a href="/risk/<?= $risk['id'] ?>/exception/create" class="btn btn-warning btn-sm"><i class="bi bi-shield-exclamation"></i> Exception</a>
    <?php endif; ?>
    <a href="/risk/dashboard" class="btn btn-ghost btn-sm"><i class="bi bi-grid-1x2-fill"></i> Dashboard</a>
    <a href="/risk" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left"></i> Register</a>
  </div>
</div>

<div class="r-layout">

  <!-- ═══════════════════ MAIN COLUMN ═══════════════════ -->
  <div class="r-main">

    <!-- ── Assessment Form ────────────────────────────────────────────────── -->
    <?php if (Auth::can('risk.write')): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-sliders"></i> Risk Assessment</h3>
        <div style="display:flex;align-items:center;gap:8px">
          <?php if ($risk['assessment_status'] === 'draft'): ?>
            <form method="POST" action="/risk/<?= $risk['id'] ?>/submit-review" style="display:inline">
              <?= Security::csrfField() ?>
              <button class="btn btn-sm btn-secondary" title="Submit for review"><i class="bi bi-send-fill"></i> Submit for Review</button>
            </form>
          <?php elseif ($risk['assessment_status'] === 'pending_review' && Auth::role() === 'admin'): ?>
            <button class="btn btn-sm btn-success" data-show-modal="approveModal">
              <i class="bi bi-patch-check-fill"></i> Approve
            </button>
            <button class="btn btn-sm btn-danger" data-show-modal="rejectModal">
              <i class="bi bi-x-circle-fill"></i> Send Back
            </button>
          <?php elseif ($risk['assessment_status'] === 'approved'): ?>
            <span style="color:#16a34a;font-size:12px;font-weight:600">
              <i class="bi bi-patch-check-fill"></i> Approved by <?= Security::h($risk['reviewed_by_name'] ?? 'Admin') ?>
              <?= $risk['reviewed_at'] ? ' · ' . date('M j, Y', strtotime($risk['reviewed_at'])) : '' ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <?php if ($risk['description']): ?>
        <div class="r-desc-box"><?= Security::h($risk['description']) ?></div>
        <?php endif; ?>

        <form method="POST" action="/risk/<?= $risk['id'] ?>/update">
          <?= Security::csrfField() ?>

          <!-- ── Score Grid ─────────────────────────────────── -->
          <div class="r-score-grid">
            <!-- Inherent -->
            <div class="r-score-col">
              <h4 class="r-score-head">Inherent Risk</h4>
              <div class="form-group">
                <label class="form-label">Likelihood</label>
                <input type="range" name="likelihood" min="1" max="5" value="<?= $risk['likelihood'] ?>" data-input="updateScores" id="s_l" class="risk-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="v_l"><?= $risk['likelihood'] ?></div>
              </div>
              <div class="form-group">
                <label class="form-label">Impact</label>
                <input type="range" name="impact" min="1" max="5" value="<?= $risk['impact'] ?>" data-input="updateScores" id="s_i" class="risk-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="v_i"><?= $risk['impact'] ?></div>
              </div>
              <div class="score-chip" id="chip_i"><div id="sc_i"><?= $score ?></div><div id="sl_i"><?= $level ?></div></div>
            </div>

            <div class="r-score-arrow"><i class="bi bi-arrow-right-short" style="font-size:22px"></i><span>Treatment</span></div>

            <!-- Residual -->
            <div class="r-score-col">
              <h4 class="r-score-head">Residual Risk</h4>
              <div class="form-group">
                <label class="form-label">Residual Likelihood</label>
                <input type="range" name="residual_likelihood" min="1" max="5" value="<?= $risk['residual_likelihood'] ?? $risk['likelihood'] ?>" data-input="updateScores" id="s_rl" class="risk-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="v_rl"><?= $risk['residual_likelihood'] ?? $risk['likelihood'] ?></div>
              </div>
              <div class="form-group">
                <label class="form-label">Residual Impact</label>
                <input type="range" name="residual_impact" min="1" max="5" value="<?= $risk['residual_impact'] ?? $risk['impact'] ?>" data-input="updateScores" id="s_ri" class="risk-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="v_ri"><?= $risk['residual_impact'] ?? $risk['impact'] ?></div>
              </div>
              <div class="score-chip" id="chip_r"><div id="sc_r"><?= $resScore ?></div><div id="sl_r"><?= $resLevel ?></div></div>
            </div>

            <div class="r-score-arrow"><i class="bi bi-arrow-right-short" style="font-size:22px"></i><span>Target</span></div>

            <!-- Target -->
            <div class="r-score-col">
              <h4 class="r-score-head">Target Risk</h4>
              <div class="form-group">
                <label class="form-label">Target Likelihood</label>
                <input type="range" name="target_likelihood" min="1" max="5" value="<?= $risk['target_likelihood'] ?? max(1, (int)$risk['likelihood'] - 1) ?>" data-input="updateScores" id="s_tl" class="risk-slider tgt-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="v_tl"><?= $risk['target_likelihood'] ?? max(1, (int)$risk['likelihood'] - 1) ?></div>
              </div>
              <div class="form-group">
                <label class="form-label">Target Impact</label>
                <input type="range" name="target_impact" min="1" max="5" value="<?= $risk['target_impact'] ?? max(1, (int)$risk['impact'] - 1) ?>" data-input="updateScores" id="s_ti" class="risk-slider tgt-slider">
                <div class="slider-markers"><span>1</span><span>2</span><span>3</span><span>4</span><span>5</span></div>
                <div class="slider-val" id="v_ti"><?= $risk['target_impact'] ?? max(1, (int)$risk['impact'] - 1) ?></div>
              </div>
              <div class="score-chip tgt-chip" id="chip_t"><div id="sc_t"><?= $tgtScore ?? '—' ?></div><div id="sl_t">Target</div></div>
            </div>
          </div>

          <!-- ── Risk Attributes ────────────────────────────── -->
          <div class="form-row" style="margin-top:18px">
            <div class="form-group">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <?php foreach ($statusLabels as $v => $s): ?>
                  <option value="<?= $v ?>" <?= $risk['status']===$v?'selected':'' ?>><?= $s['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Risk Owner</label>
              <select name="owner_id" class="form-control">
                <option value="">Unassigned</option>
                <?php foreach ($users as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= (int)($risk['owner_id']??0)===(int)$u['id']?'selected':'' ?>><?= Security::h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Review Date</label>
              <input type="date" name="review_date" class="form-control" value="<?= Security::h($risk['review_date'] ?? '') ?>">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Risk Source</label>
              <select name="risk_source" class="form-control">
                <option value="">Not specified</option>
                <?php foreach ($sourceLabels as $k=>$l): ?>
                  <option value="<?= $k ?>" <?= ($risk['risk_source']??'')===$k?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Velocity <span class="form-hint" style="margin:0">(how quickly it could hit)</span></label>
              <select name="velocity" class="form-control">
                <?php foreach ($velocityLabels as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= (int)($risk['velocity']??3)===$v?'selected':'' ?>><?= $v ?> — <?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Proximity (Time Horizon)</label>
              <select name="proximity" class="form-control">
                <?php foreach ($proximityLabels as $k=>$l): ?>
                  <option value="<?= $k ?>" <?= ($risk['proximity']??'medium_term')===$k?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Assessment Confidence</label>
              <select name="confidence" class="form-control">
                <?php foreach (['low'=>'Low','medium'=>'Medium','high'=>'High'] as $k=>$l): ?>
                  <option value="<?= $k ?>" <?= ($risk['confidence']??'medium')===$k?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- ── Response Strategy ──────────────────────────── -->
          <div class="form-group">
            <label class="form-label">
              Response Strategy
              <span id="stratHint" class="form-hint" style="margin:0;font-style:italic"></span>
            </label>
            <div class="strategy-grid">
              <?php foreach ($strategyMeta as $key => $sm):
                $checked    = in_array($key, $risk['treatment_strategies_arr'], true);
                $isSuggested = in_array($key, $suggested, true);
              ?>
              <label class="strategy-opt <?= $isSuggested?'strat-suggested':'' ?>" id="sl_<?= $key ?>">
                <input type="checkbox" name="treatment_strategies[]" value="<?= $key ?>"
                       <?= $checked?'checked':'' ?> data-change="onStratChange">
                <i class="bi bi-<?= $sm['icon'] ?>" style="font-size:20px"></i>
                <span class="strat-lbl"><?= $sm['label'] ?></span>
                <span class="strat-hint"><?= $sm['hint'] ?></span>
                <?php if ($isSuggested): ?><span class="strat-tag">Suggested</span><?php endif; ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Treatment Notes</label>
            <textarea name="treatment_description" class="form-control" rows="2" placeholder="Describe how selected strategies are being applied..."><?= Security::h($risk['treatment_description'] ?? '') ?></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Update Note <span class="form-hint" style="margin:0">(recorded in history)</span></label>
            <input type="text" name="update_note" class="form-control" placeholder="e.g. Re-assessed after Q2 audit findings...">
          </div>

          <div class="form-actions" style="display:flex;gap:8px;align-items:center">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save-fill"></i> Save Changes</button>
            <form method="POST" action="/risk/<?= $risk['id'] ?>/delete" style="display:inline">
              <?= Security::csrfField() ?>
              <button class="btn btn-ghost text-danger" data-confirm-click="Delete this risk permanently?"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Financial Exposure ──────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-currency-dollar"></i> Financial Exposure</h3></div>
      <div class="card-body">
        <?php if (Auth::can('risk.write')): ?>
        <form method="POST" action="/risk/<?= $risk['id'] ?>/update">
          <?= Security::csrfField() ?>
          <?php /* Pass through required fields unchanged */ ?>
          <input type="hidden" name="likelihood"   value="<?= $risk['likelihood'] ?>">
          <input type="hidden" name="impact"        value="<?= $risk['impact'] ?>">
          <input type="hidden" name="status"        value="<?= Security::h($risk['status']) ?>">
          <?php foreach ($risk['treatment_strategies_arr'] as $s): ?>
          <input type="hidden" name="treatment_strategies[]" value="<?= Security::h($s) ?>">
          <?php endforeach; ?>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Minimum Loss Scenario</label>
              <div class="input-group"><span class="input-prefix">$</span>
                <input type="number" name="financial_min" class="form-control" step="0.01" min="0"
                       value="<?= Security::h($risk['financial_min'] ?? '') ?>" placeholder="0">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Most Likely Loss</label>
              <div class="input-group"><span class="input-prefix">$</span>
                <input type="number" name="financial_likely" class="form-control" step="0.01" min="0"
                       value="<?= Security::h($risk['financial_likely'] ?? '') ?>" placeholder="0">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Maximum Loss Scenario</label>
              <div class="input-group"><span class="input-prefix">$</span>
                <input type="number" name="financial_max" class="form-control" step="0.01" min="0"
                       value="<?= Security::h($risk['financial_max'] ?? '') ?>" placeholder="0">
              </div>
            </div>
          </div>
          <?php
          $fMin    = $risk['financial_min']    ? (float)$risk['financial_min']    : null;
          $fLikely = $risk['financial_likely'] ? (float)$risk['financial_likely'] : null;
          $fMax    = $risk['financial_max']    ? (float)$risk['financial_max']    : null;
          ?>
          <?php if ($fMin !== null || $fLikely !== null || $fMax !== null): ?>
          <div class="fin-bar-wrap">
            <?php $maxVal = $fMax ?: $fLikely ?: $fMin ?: 1; ?>
            <?php if ($fMin !== null): ?>
            <div class="fin-bar-row"><span>Min</span>
              <div class="fin-bar" style="width:<?= min(100, (int)(($fMin/$maxVal)*100)) ?>%;background:#22c55e"></div>
              <span class="fin-val">$<?= number_format($fMin, 0) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($fLikely !== null): ?>
            <div class="fin-bar-row"><span>Likely</span>
              <div class="fin-bar" style="width:<?= min(100, (int)(($fLikely/$maxVal)*100)) ?>%;background:#f59e0b"></div>
              <span class="fin-val">$<?= number_format($fLikely, 0) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($fMax !== null): ?>
            <div class="fin-bar-row"><span>Max</span>
              <div class="fin-bar" style="width:100%;background:#ef4444"></div>
              <span class="fin-val">$<?= number_format($fMax, 0) ?></span>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-sm btn-secondary" style="margin-top:4px"><i class="bi bi-save"></i> Save</button>
        </form>
        <?php else: ?>
          <div class="form-row">
            <?php if ($risk['financial_min']): ?><div class="detail-row"><span>Minimum</span><strong>$<?= number_format((float)$risk['financial_min'], 0) ?></strong></div><?php endif; ?>
            <?php if ($risk['financial_likely']): ?><div class="detail-row"><span>Most Likely</span><strong>$<?= number_format((float)$risk['financial_likely'], 0) ?></strong></div><?php endif; ?>
            <?php if ($risk['financial_max']): ?><div class="detail-row"><span>Maximum</span><strong>$<?= number_format((float)$risk['financial_max'], 0) ?></strong></div><?php endif; ?>
            <?php if (!$risk['financial_min'] && !$risk['financial_likely'] && !$risk['financial_max']): ?>
              <p class="text-muted" style="font-size:13px">No financial exposure data recorded.</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php endif; /* Auth::can('risk.write') */ ?>

    <!-- ── Linked Controls ────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-shield-lock-fill"></i> Linked Controls</h3>
        <span class="badge" style="background:var(--bg-secondary);color:var(--text-muted)"><?= count($linkedControls) ?> linked</span>
      </div>
      <?php if (!empty($linkedControls)): ?>
      <div class="card-body" style="padding:0">
        <table class="data-table">
          <thead><tr><th>Control</th><th>Package</th><th>Control Status</th><th>Effectiveness</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($linkedControls as $lc): ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:12px" class="mono"><?= Security::h($lc['objective_code']) ?></div>
                <div style="font-size:12px;color:var(--text-muted)"><?= Security::h(mb_strimwidth($lc['objective_title'], 0, 60, '…')) ?></div>
              </td>
              <td class="text-sm"><?= Security::h($lc['package_name']) ?></td>
              <td><span class="badge badge-<?= $lc['control_status'] ?>"><?= ucfirst(str_replace('_',' ',$lc['control_status'])) ?></span></td>
              <td>
                <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;background:<?= $effColors[$lc['effectiveness']] ?>18;color:<?= $effColors[$lc['effectiveness']] ?>;border:1px solid <?= $effColors[$lc['effectiveness']] ?>35">
                  <?= $effLabels[$lc['effectiveness']] ?>
                </span>
              </td>
              <td>
                <?php if (Auth::can('risk.write')): ?>
                <form method="POST" action="/risk/control-link/<?= $lc['id'] ?>/remove" style="display:inline">
                  <?= Security::csrfField() ?>
                  <button class="btn btn-sm btn-ghost text-danger" data-confirm-click="Remove this control link?" title="Unlink"><i class="bi bi-x-lg"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="card-body" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">
        No controls linked. Linking controls helps demonstrate risk treatment coverage.
      </div>
      <?php endif; ?>

      <?php if (Auth::can('risk.write') && !empty($availableControls)): ?>
      <div class="card-body" style="border-top:1px solid var(--border)">
        <form method="POST" action="/risk/<?= $risk['id'] ?>/link-control">
          <?= Security::csrfField() ?>
          <div class="form-row" style="align-items:flex-end;gap:8px">
            <div class="form-group flex-3">
              <label class="form-label">Add Control</label>
              <select name="control_implementation_id" class="form-control">
                <option value="">Select a control…</option>
                <?php foreach ($availableControls as $c): ?>
                  <option value="<?= $c['id'] ?>">[<?= Security::h($c['package_name']) ?>] <?= Security::h($c['code']) ?> — <?= Security::h(mb_strimwidth($c['title'], 0, 55, '…')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 130px">
              <label class="form-label">Effectiveness</label>
              <select name="effectiveness" class="form-control">
                <?php foreach ($effLabels as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 auto">
              <label class="form-label">&nbsp;</label>
              <button type="submit" class="btn btn-primary" style="white-space:nowrap"><i class="bi bi-plus-lg"></i> Link</button>
            </div>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Response Actions ───────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-list-check"></i> Response Actions</h3>
        <div style="display:flex;gap:6px;align-items:center">
          <?php
          $actionCounts = ['planned'=>0,'in_progress'=>0,'completed'=>0];
          foreach ($responseActions as $ra) { if (isset($actionCounts[$ra['status']])) $actionCounts[$ra['status']]++; }
          ?>
          <?php if ($actionCounts['in_progress']): ?><span style="font-size:11px;background:#dbeafe;color:#2563eb;padding:2px 8px;border-radius:20px"><?= $actionCounts['in_progress'] ?> active</span><?php endif; ?>
          <?php if ($actionCounts['completed']): ?><span style="font-size:11px;background:#d1fae5;color:#059669;padding:2px 8px;border-radius:20px"><?= $actionCounts['completed'] ?> done</span><?php endif; ?>
          <?php if ($actionCounts['planned']): ?><span style="font-size:11px;background:#fef3c7;color:#d97706;padding:2px 8px;border-radius:20px"><?= $actionCounts['planned'] ?> planned</span><?php endif; ?>
        </div>
      </div>
      <?php if (!empty($responseActions)): ?>
      <div class="card-body" style="padding:0">
        <?php foreach ($responseActions as $ra):
          $sm = $strategyMeta[$ra['treatment_type']] ?? $strategyMeta['mitigate'];
          $overdue = $ra['due_date'] && $ra['due_date'] < date('Y-m-d') && $ra['status'] !== 'completed';
          $raStatusColors = ['planned'=>'#d97706','in_progress'=>'#2563eb','completed'=>'#059669','cancelled'=>'#a1a1aa'];
          $raColor = $raStatusColors[$ra['status']] ?? '#71717a';
        ?>
        <div class="ra-item <?= $ra['status']==='completed'?'ra-done':'' ?>">
          <div style="display:flex;align-items:flex-start;gap:10px;flex:1;min-width:0">
            <span class="ra-badge" style="background:<?= $sm['color'] ?>15;color:<?= $sm['color'] ?>;border-color:<?= $sm['color'] ?>35">
              <?= $sm['label'] ?>
            </span>
            <div style="flex:1;min-width:0">
              <div style="font-size:14px;font-weight:500"><?= Security::h($ra['description']) ?></div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px;display:flex;gap:10px;flex-wrap:wrap">
                <span><i class="bi bi-person"></i> <?= Security::h($ra['owner_name'] ?? 'Unassigned') ?></span>
                <?php if ($ra['due_date']): ?><span style="color:<?= $overdue?'#dc2626':'inherit' ?>"><i class="bi bi-calendar-event"></i> Due <?= date('M j, Y', strtotime($ra['due_date'])) ?><?= $overdue?' ⚠':'' ?></span><?php endif; ?>
                <?php if ($ra['effort']): ?><span><i class="bi bi-stopwatch"></i> <?= Security::h($ra['effort']) ?></span><?php endif; ?>
                <?php if ($ra['cost_estimate']): ?><span><i class="bi bi-currency-dollar"></i> $<?= number_format((float)$ra['cost_estimate'],0) ?></span><?php endif; ?>
                <?php if ($ra['completion_notes']): ?><span style="font-style:italic"><?= Security::h($ra['completion_notes']) ?></span><?php endif; ?>
              </div>
            </div>
          </div>
          <?php if (Auth::can('risk.write')): ?>
          <form method="POST" action="/risk/response-action/<?= $ra['id'] ?>/update" style="display:flex;gap:6px;align-items:center;flex-shrink:0">
            <?= Security::csrfField() ?>
            <select name="status" class="form-control form-control-sm" style="font-size:11px;padding:2px 6px;width:auto">
              <?php foreach ($actionStatuses as $sv=>$sl): ?>
                <option value="<?= $sv ?>" <?= $ra['status']===$sv?'selected':'' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-ghost" style="padding:2px 7px" title="Update"><i class="bi bi-check2"></i></button>
          </form>
          <?php else: ?>
          <span style="font-size:11px;padding:3px 8px;border-radius:20px;background:<?= $raColor ?>18;color:<?= $raColor ?>;border:1px solid <?= $raColor ?>35;white-space:nowrap"><?= $actionStatuses[$ra['status']] ?? ucfirst($ra['status']) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="card-body" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">No response actions yet.</div>
      <?php endif; ?>

      <?php if (Auth::can('risk.write')): ?>
      <div class="card-body" style="border-top:1px solid var(--border)">
        <div style="font-size:12px;font-weight:600;margin-bottom:10px"><i class="bi bi-plus-circle"></i> Add Response Action</div>
        <form method="POST" action="/risk/<?= $risk['id'] ?>/response-action">
          <?= Security::csrfField() ?>
          <div class="form-row" style="align-items:flex-end;gap:8px">
            <div class="form-group" style="flex:0 0 120px">
              <label class="form-label">Type</label>
              <select name="action_type" class="form-control">
                <?php foreach ($strategyMeta as $k=>$m): ?><option value="<?= $k ?>"><?= $m['label'] ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group flex-3">
              <label class="form-label">Description *</label>
              <input type="text" name="description" class="form-control" placeholder="Describe the action..." required>
            </div>
            <div class="form-group" style="flex:0 0 130px">
              <label class="form-label">Assign To</label>
              <select name="assigned_to" class="form-control">
                <?php foreach ($users as $u): ?>
                  <option value="<?= $u['id'] ?>" <?= $u['id']===Auth::id()?'selected':'' ?>><?= Security::h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 130px">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" class="form-control">
            </div>
            <div class="form-group" style="flex:0 0 90px">
              <label class="form-label">Effort</label>
              <select name="effort" class="form-control">
                <option value="">—</option><option>Low</option><option>Medium</option><option>High</option>
              </select>
            </div>
            <div class="form-group" style="flex:0 0 auto">
              <label class="form-label">&nbsp;</label>
              <button type="submit" class="btn btn-primary" style="white-space:nowrap"><i class="bi bi-plus-lg"></i> Add</button>
            </div>
          </div>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Score History Chart ────────────────────────────────────────────── -->
    <?php if (!empty($scoreHistory)): ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-graph-up-arrow"></i> Score History</h3></div>
      <div class="card-body">
        <canvas id="histChart" height="140" style="width:100%"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Treatment Plans ────────────────────────────────────────────────── -->
    <?php
    $tpStratColors = ['mitigate'=>['bg'=>'#3b82f620','c'=>'#3b82f6','b'=>'#3b82f640'],'transfer'=>['bg'=>'#8b5cf620','c'=>'#8b5cf6','b'=>'#8b5cf640'],'accept'=>['bg'=>'#f59e0b20','c'=>'#f59e0b','b'=>'#f59e0b40'],'avoid'=>['bg'=>'#ef444420','c'=>'#ef4444','b'=>'#ef444440']];
    $tpStColors    = ['draft'=>['bg'=>'#a1a1aa20','c'=>'#a1a1aa'],'active'=>['bg'=>'rgba(22, 163, 74, .08)','c'=>'var(--primary)'],'completed'=>['bg'=>'#05966920','c'=>'#059669'],'cancelled'=>['bg'=>'#a1a1aa20','c'=>'#a1a1aa']];
    ?>
    <?php if (!empty($treatmentPlans)): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-kanban-fill"></i> Treatment Plans</h3>
        <?php if (Auth::can('risk.write')): ?><a href="/risk/<?= (int)$risk['id'] ?>/treatment/create" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Add Plan</a><?php endif; ?>
      </div>
      <div class="card-body" style="padding:0">
        <table class="data-table">
          <thead><tr><th>Plan</th><th>Strategy</th><th>Status</th><th>Progress</th><th>Target</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($treatmentPlans as $tp):
            $tsc = $tpStratColors[$tp['strategy']] ?? $tpStratColors['mitigate'];
            $tst = $tpStColors[$tp['status']] ?? $tpStColors['draft'];
            $tot = (int)$tp['total_milestones']; $done = (int)$tp['completed_milestones'];
            $pct = $tot > 0 ? (int)round(($done/$tot)*100) : 0;
          ?>
          <tr>
            <td style="font-weight:500"><?= Security::h($tp['title']) ?></td>
            <td><span class="status-chip" style="background:<?= $tsc['bg'] ?>;color:<?= $tsc['c'] ?>;border:1px solid <?= $tsc['b'] ?>"><?= ucfirst($tp['strategy']) ?></span></td>
            <td><span class="status-chip" style="background:<?= $tst['bg'] ?>;color:<?= $tst['c'] ?>"><?= ucfirst($tp['status']) ?></span></td>
            <td style="min-width:100px">
              <?php if ($tot > 0): ?>
              <div style="display:flex;align-items:center;gap:6px">
                <div style="flex:1;height:5px;background:var(--border);border-radius:4px;overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#059669':'var(--primary)' ?>;border-radius:4px"></div></div>
                <span style="font-size:11px;color:var(--text-muted)"><?= $done ?>/<?= $tot ?></span>
              </div>
              <?php else: ?><span class="text-muted text-sm">No milestones</span><?php endif; ?>
            </td>
            <td class="text-sm"><?= $tp['target_date'] ? date('M j, Y', strtotime($tp['target_date'])) : '—' ?></td>
            <td><a href="/treatment/<?= (int)$tp['id'] ?>" class="btn btn-sm btn-secondary"><i class="bi bi-eye"></i></a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php else: ?>
    <?php if (Auth::can('risk.write')): ?>
    <div style="text-align:center;padding:6px">
      <a href="/risk/<?= (int)$risk['id'] ?>/treatment/create" class="btn btn-sm btn-secondary"><i class="bi bi-kanban-fill"></i> Create Treatment Plan</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div><!-- /r-main -->

  <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
  <div class="r-sidebar">

    <!-- Risk Info -->
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> Risk Info</h3></div>
      <div class="card-body">
        <div class="detail-row"><span>Risk ID</span><strong class="mono"><?= Security::h($risk['risk_id'] ?? '—') ?></strong></div>
        <div class="detail-row"><span>Category</span>
          <?php if ($risk['category_name']): ?>
          <span style="display:flex;align-items:center;gap:5px"><span class="category-dot" style="background:<?= Security::h($risk['category_color']??'#888') ?>"></span><?= Security::h($risk['category_name']) ?></span>
          <?php else: ?><strong>Uncategorized</strong><?php endif; ?>
        </div>
        <div class="detail-row"><span>Owner</span><strong><?= Security::h($risk['owner_name'] ?? 'Unassigned') ?></strong></div>
        <div class="detail-row"><span>Source</span><strong><?= Security::h($sourceLabels[$risk['risk_source']??''] ?? '—') ?></strong></div>
        <div class="detail-row"><span>Inherent Score</span>
          <strong><?= $score ?> <span class="risk-badge risk-<?= strtolower($level) ?>"><?= $level ?></span></strong>
        </div>
        <?php if ($risk['residual_likelihood']): ?>
        <div class="detail-row"><span>Residual Score</span>
          <strong><?= $resScore ?> <span class="risk-badge risk-<?= strtolower($resLevel) ?>"><?= $resLevel ?></span></strong>
        </div>
        <?php endif; ?>
        <?php if ($tgtScore): ?>
        <div class="detail-row"><span>Target Score</span><strong><?= $tgtScore ?> <span class="risk-badge risk-<?= strtolower(riskLevelStr($tgtScore)) ?>"><?= riskLevelStr($tgtScore) ?></span></strong></div>
        <?php endif; ?>
        <div class="detail-row"><span>Velocity</span><strong><?= Security::h($velocityLabels[$risk['velocity']??3]) ?></strong></div>
        <div class="detail-row"><span>Proximity</span><strong><?= Security::h($proximityLabels[$risk['proximity']??'medium_term']) ?></strong></div>
        <div class="detail-row"><span>Confidence</span><strong><?= ucfirst($risk['confidence'] ?? 'medium') ?></strong></div>
        <div class="detail-row"><span>Identified</span><strong><?= date('M j, Y', strtotime($risk['identified_date'])) ?></strong></div>
        <?php if ($risk['review_date']): ?>
        <?php $rvOvd = $risk['review_date'] < date('Y-m-d'); ?>
        <div class="detail-row"><span>Review Due</span><strong style="color:<?= $rvOvd?'#dc2626':'inherit' ?>"><?= date('M j, Y', strtotime($risk['review_date'])) ?><?= $rvOvd?' ⚠':'' ?></strong></div>
        <?php endif; ?>
        <div class="detail-row"><span>Created By</span><strong><?= Security::h($risk['created_by_name'] ?? '—') ?></strong></div>
        <div class="detail-row"><span>Last Updated</span><strong><?= date('M j, Y', strtotime($risk['updated_at'])) ?></strong></div>
        <div class="detail-row"><span>Controls Linked</span><strong><?= count($linkedControls) ?></strong></div>
        <div class="detail-row"><span>Actions</span>
          <strong><?= count(array_filter($responseActions, fn($a)=>$a['status']!=='completed')) ?> open / <?= count($responseActions) ?> total</strong>
        </div>
      </div>
    </div>

    <!-- Risk Appetite -->
    <?php if ($appetite): $ac=['zero'=>'#dc2626','low'=>'#d97706','moderate'=>'#2563eb','high'=>'#16a34a'][$appetite['appetite']] ?? '#71717a'; ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-speedometer2"></i> Risk Appetite</h3></div>
      <div class="card-body">
        <div class="detail-row"><span>Level</span><strong style="color:<?= $ac ?>"><?= ucfirst($appetite['appetite']) ?></strong></div>
        <?php if ($appetite['max_score']): ?>
        <div class="detail-row"><span>Max Score</span><strong><?= $appetite['max_score'] ?></strong></div>
        <?php $exceeds = $score > (int)$appetite['max_score']; ?>
        <div style="margin-top:8px">
          <?php if ($exceeds): ?>
          <div class="alert-box error" style="margin:0;padding:8px 10px;font-size:12px"><i class="bi bi-exclamation-triangle-fill"></i> Exceeds appetite by <?= $score - (int)$appetite['max_score'] ?></div>
          <?php else: ?>
          <div class="alert-box success" style="margin:0;padding:8px 10px;font-size:12px"><i class="bi bi-check-circle-fill"></i> Within appetite (<?= (int)$appetite['max_score'] - $score ?> points below max)</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Risk Hierarchy -->
    <?php if ($risk['parent_risk_id'] || !empty($childRisks)): ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-diagram-3-fill"></i> Risk Hierarchy</h3></div>
      <div class="card-body">
        <?php if ($risk['parent_risk_id']): ?>
        <div style="margin-bottom:10px">
          <div class="text-muted" style="font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:4px">Parent Risk</div>
          <a href="/risk/<?= (int)$risk['parent_risk_id'] ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit">
            <span class="risk-badge risk-<?= strtolower(riskLevelStr((int)$risk['parent_score'])) ?>"><?= $risk['parent_score'] ?></span>
            <span style="font-size:13px"><?= Security::h($risk['parent_risk_id_code'] ?? '') ?> — <?= Security::h(mb_strimwidth($risk['parent_title']??'', 0, 40, '…')) ?></span>
          </a>
        </div>
        <?php endif; ?>
        <?php if (!empty($childRisks)): ?>
        <div>
          <div class="text-muted" style="font-size:11px;font-weight:700;text-transform:uppercase;margin-bottom:6px">Child Risks (<?= count($childRisks) ?>)</div>
          <?php foreach ($childRisks as $cr): ?>
          <a href="/risk/<?= (int)$cr['id'] ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;padding:4px 0;border-bottom:1px solid var(--border)">
            <span class="risk-badge risk-<?= strtolower(riskLevelStr((int)$cr['inherent_score'])) ?>"><?= $cr['inherent_score'] ?></span>
            <span style="font-size:12px"><?= Security::h($cr['risk_id']) ?> — <?= Security::h(mb_strimwidth($cr['title'], 0, 35, '…')) ?></span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Related Risks -->
    <?php if (!empty($relatedRisks) || Auth::can('risk.write')): ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-link-45deg"></i> Related Risks</h3></div>
      <div class="card-body">
        <?php foreach ($relatedRisks as $rr): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid var(--border)">
          <span class="risk-badge risk-<?= strtolower(riskLevelStr((int)$rr['related_score'])) ?>"><?= $rr['related_score'] ?></span>
          <div style="flex:1;min-width:0">
            <a href="/risk/<?= (int)$rr['related_risk_id'] ?>" style="font-size:12px;font-weight:500;display:block"><?= Security::h($rr['related_risk_code']) ?> <?= Security::h(mb_strimwidth($rr['related_title'], 0, 30, '…')) ?></a>
            <span style="font-size:10px;color:var(--text-muted)"><?= ucfirst(str_replace('_',' ',$rr['link_type'])) ?></span>
          </div>
          <?php if (Auth::can('risk.write')): ?>
          <form method="POST" action="/risk/related-link/<?= $rr['id'] ?>/remove" style="display:inline">
            <?= Security::csrfField() ?>
            <button class="btn btn-sm btn-ghost" style="padding:1px 5px" title="Remove"><i class="bi bi-x"></i></button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($relatedRisks)): ?><p class="text-muted" style="font-size:12px;margin:0">No related risks linked.</p><?php endif; ?>

        <?php if (Auth::can('risk.write') && !empty($allRisks)): ?>
        <form method="POST" action="/risk/<?= $risk['id'] ?>/link-related" style="margin-top:12px">
          <?= Security::csrfField() ?>
          <div class="form-row" style="gap:6px">
            <select name="related_risk_id" class="form-control" style="font-size:12px;flex:1">
              <option value="">Link a risk…</option>
              <?php foreach ($allRisks as $ar): ?>
                <option value="<?= $ar['id'] ?>"><?= Security::h($ar['risk_id']) ?> — <?= Security::h(mb_strimwidth($ar['title'], 0, 40, '…')) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="link_type" class="form-control" style="font-size:12px;flex:0 0 110px">
              <option value="related">Related</option><option value="causes">Causes</option>
              <option value="caused_by">Caused By</option><option value="aggregates">Aggregates</option>
            </select>
            <button type="submit" class="btn btn-sm btn-secondary"><i class="bi bi-plus-lg"></i></button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Acceptance Certificate -->
    <?php if ($activeAcceptance || Auth::can('risk.write')): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-patch-check-fill" style="color:#16a34a"></i> Risk Acceptance</h3>
        <?php if (Auth::can('risk.write')): ?>
          <a href="/risk/<?= (int)$risk['id'] ?>/accept" class="btn btn-ghost btn-sm"><i class="bi bi-plus-lg"></i> Issue</a>
        <?php endif; ?>
      </div>
      <div class="card-body" style="padding:12px 16px">
        <?php if ($activeAcceptance): ?>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <span style="background:#f0fdf4;color:#16a34a;border:1px solid #86efac;border-radius:20px;font-size:11px;font-weight:700;padding:2px 10px">Active</span>
            <span style="font-size:12px;color:var(--text-muted)">until <?= date('M j, Y', strtotime($activeAcceptance['valid_until'])) ?></span>
          </div>
          <div style="font-size:12px;color:var(--text-secondary);margin-bottom:6px">
            Accepted by <strong><?= Security::h($activeAcceptance['acceptor_name']) ?></strong>
          </div>
          <?php if ($activeAcceptance['conditions']): ?>
            <div style="font-size:11px;color:var(--text-muted);font-style:italic;margin-bottom:8px"><?= Security::h(mb_strimwidth($activeAcceptance['conditions'], 0, 100, '…')) ?></div>
          <?php endif; ?>
          <?php if (strtotime($activeAcceptance['valid_until']) < strtotime('+30 days')): ?>
            <div style="font-size:11px;color:#d97706;font-weight:600;margin-bottom:8px"><i class="bi bi-exclamation-triangle"></i> Expiring soon</div>
          <?php endif; ?>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="/risk-acceptances/<?= (int)$activeAcceptance['id'] ?>/renew" class="btn btn-ghost btn-sm" style="font-size:11px"><i class="bi bi-arrow-repeat"></i> Renew</a>
            <form method="POST" action="/risk-acceptances/<?= (int)$activeAcceptance['id'] ?>/revoke" style="margin:0" data-confirm="Revoke this acceptance certificate?">
              <?= Security::csrfField() ?>
              <button type="submit" class="btn btn-ghost btn-sm" style="font-size:11px;color:#ef4444"><i class="bi bi-x-circle"></i> Revoke</button>
            </form>
          </div>
        <?php else: ?>
          <p class="text-muted" style="font-size:12px;margin:0">No active acceptance certificate. <a href="/risk/<?= (int)$risk['id'] ?>/accept">Issue one</a> if this risk is formally accepted.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Linked KRIs -->
    <?php if (!empty($linkedKRIs)): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-activity" style="color:var(--secondary)"></i> Key Risk Indicators</h3>
        <a href="/kris" class="btn btn-ghost btn-sm" style="font-size:11px">All KRIs</a>
      </div>
      <div class="card-body" style="padding:8px 0">
        <?php foreach ($linkedKRIs as $kri):
          $kriStatus = $kri['status'] ?? 'normal';
          $kriColor  = match($kriStatus) { 'red' => '#ef4444', 'amber' => '#f59e0b', default => '#22c55e' };
          $kriIcon   = match($kriStatus) { 'red' => 'exclamation-octagon-fill', 'amber' => 'exclamation-triangle-fill', default => 'check-circle-fill' };
        ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 16px;border-bottom:1px solid var(--border)">
          <i class="bi bi-<?= $kriIcon ?>" style="color:<?= $kriColor ?>;font-size:14px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <a href="/kris/<?= (int)$kri['id'] ?>" style="font-size:12px;font-weight:500;display:block;color:inherit"><?= Security::h($kri['name']) ?></a>
            <?php if ($kri['current_value'] !== null): ?>
              <span style="font-size:11px;color:var(--text-muted)"><?= number_format((float)$kri['current_value'], 2) ?> <?= Security::h($kri['unit'] ?? '') ?></span>
            <?php else: ?>
              <span style="font-size:11px;color:var(--text-muted)">No readings yet</span>
            <?php endif; ?>
          </div>
          <span style="font-size:10px;font-weight:700;color:<?= $kriColor ?>;text-transform:uppercase"><?= ucfirst($kriStatus) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Scenarios & Bow-Tie links -->
    <div class="card">
      <div class="card-body" style="padding:10px 12px;display:flex;flex-direction:column;gap:8px">
        <a href="/risk/<?= (int)$risk['id'] ?>/bowtie" class="btn btn-ghost btn-sm" style="justify-content:flex-start;gap:8px;text-align:left">
          <i class="bi bi-diagram-3" style="color:var(--secondary)"></i>
          <div><strong style="display:block;font-size:12px">Bow-Tie Analysis</strong><span style="font-size:11px;color:var(--text-muted)">Causes, barriers &amp; consequences</span></div>
        </a>
        <a href="/risk/<?= (int)$risk['id'] ?>/scenario/create" class="btn btn-ghost btn-sm" style="justify-content:flex-start;gap:8px;text-align:left">
          <i class="bi bi-graph-up-arrow" style="color:#2563eb"></i>
          <div><strong style="display:block;font-size:12px">Add Scenario<?php if (!empty($scenarios)): ?> <span style="font-weight:400;color:var(--text-muted)">(<?= count($scenarios) ?>)</span><?php endif; ?></strong><span style="font-size:11px;color:var(--text-muted)">Stress-test &amp; model outcomes</span></div>
        </a>
        <?php if ($controlEffSuggestion && ($resScore > $controlEffSuggestion['score'])): ?>
        <div style="background:var(--bg-secondary);border-radius:8px;padding:10px;border:1px solid var(--border)">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px"><i class="bi bi-lightbulb-fill" style="color:#f59e0b"></i> Residual Score Suggestion</div>
          <div style="font-size:12px">Based on <strong><?= ucfirst($controlEffSuggestion['effectiveness']) ?></strong> control effectiveness,
            consider setting residual to <strong><?= $controlEffSuggestion['score'] ?></strong>
            (L<?= $controlEffSuggestion['likelihood'] ?>×I<?= $controlEffSuggestion['impact'] ?>)</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Scenarios list -->
    <?php if (!empty($scenarios)): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-graph-up-arrow" style="color:#2563eb"></i> Risk Scenarios</h3>
        <a href="/risk/<?= (int)$risk['id'] ?>/scenario/create" class="btn btn-ghost btn-sm"><i class="bi bi-plus-lg"></i></a>
      </div>
      <div class="card-body p0">
        <?php foreach ($scenarios as $sc):
          $scColor = $sc['scenario_score'] > 14 ? '#ef4444' : ($sc['scenario_score'] > 9 ? '#f97316' : ($sc['scenario_score'] > 4 ? '#f59e0b' : '#22c55e'));
          $scTypeColors = ['stress'=>'#ef4444','catastrophic'=>'var(--secondary)','regulatory'=>'#d97706','base'=>'#2563eb','optimistic'=>'#16a34a'];
          $scTypeColor  = $scTypeColors[$sc['scenario_type']] ?? '#71717a';
        ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid var(--border)">
          <span style="background:<?= $scColor ?>20;color:<?= $scColor ?>;font-size:13px;font-weight:700;width:28px;text-align:center;border-radius:4px;padding:2px 0"><?= (int)$sc['scenario_score'] ?></span>
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:500"><?= Security::h(mb_strimwidth($sc['name'], 0, 40, '…')) ?></div>
            <span style="font-size:10px;color:<?= $scTypeColor ?>;font-weight:700;text-transform:uppercase"><?= ucfirst($sc['scenario_type']) ?></span>
          </div>
          <form method="POST" action="/risk-scenarios/<?= (int)$sc['id'] ?>/delete" style="margin:0" data-confirm="Delete this scenario?">
            <?= Security::csrfField() ?>
            <button type="submit" class="btn btn-ghost btn-sm" style="padding:2px 5px;color:var(--text-muted)"><i class="bi bi-trash3" style="font-size:11px"></i></button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /r-sidebar -->
</div><!-- /r-layout -->

<!-- Approve Modal -->
<div id="approveModal" style="display:none;position:fixed;inset:0;background:#00000060;z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--bg-primary);border-radius:12px;padding:24px;width:420px;max-width:95vw">
    <h3 style="margin:0 0 12px">Approve Risk Assessment</h3>
    <form method="POST" action="/risk/<?= $risk['id'] ?>/approve">
      <?= Security::csrfField() ?>
      <div class="form-group"><label class="form-label">Approval Notes</label>
        <textarea name="review_notes" class="form-control" rows="3" placeholder="Optional notes…"></textarea>
      </div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-success">Approve</button>
        <button type="button" class="btn btn-ghost" data-close-modal="approveModal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:#00000060;z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--bg-primary);border-radius:12px;padding:24px;width:420px;max-width:95vw">
    <h3 style="margin:0 0 12px">Send Back for Revision</h3>
    <form method="POST" action="/risk/<?= $risk['id'] ?>/reject-review">
      <?= Security::csrfField() ?>
      <div class="form-group"><label class="form-label">Reason for Revision</label>
        <textarea name="review_notes" class="form-control" rows="3" placeholder="Explain what needs to change…" required></textarea>
      </div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-danger">Send Back</button>
        <button type="button" class="btn btn-ghost" data-close-modal="rejectModal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<style nonce="<?= Security::nonce() ?>">
.r-layout { display:grid; grid-template-columns:1fr 290px; gap:16px; align-items:start; margin-top:16px; }
.r-main    { display:flex; flex-direction:column; gap:16px; }
.r-sidebar { display:flex; flex-direction:column; gap:14px; }
@media(max-width:960px){ .r-layout{grid-template-columns:1fr} .r-sidebar{order:-1} }

/* Score grid */
.r-score-grid { display:grid; grid-template-columns:1fr auto 1fr auto 1fr; gap:12px; align-items:start; }
@media(max-width:700px){ .r-score-grid{grid-template-columns:1fr} }
.r-score-col  { display:flex; flex-direction:column; gap:10px; }
.r-score-head { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); margin:0; }
.r-score-arrow { display:flex; flex-direction:column; align-items:center; justify-content:center; padding-top:48px; color:var(--text-muted); font-size:11px; gap:2px; }
.score-chip   { border-radius:10px; padding:10px; text-align:center; background:var(--bg-secondary); border:1px solid var(--border); }
.score-chip div:first-child { font-size:28px; font-weight:700; }
.score-chip div:last-child  { font-size:11px; font-weight:600; text-transform:uppercase; }
.tgt-chip { border-style:dashed; opacity:.85; }
.risk-slider,.tgt-slider { width:100%; }
.slider-markers { display:flex; justify-content:space-between; font-size:10px; color:var(--text-muted); }
.slider-val     { text-align:center; font-weight:700; font-size:16px; }

/* Strategy grid */
.strategy-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-top:6px; }
@media(max-width:600px){ .strategy-grid{grid-template-columns:1fr 1fr} }
.strategy-opt { display:flex; flex-direction:column; align-items:center; gap:5px; padding:14px 8px; border-radius:10px; border:2px solid var(--border); background:var(--bg-secondary); cursor:pointer; text-align:center; transition:all .15s; position:relative; }
.strategy-opt:has(input:checked) { border-color:var(--primary); background:color-mix(in srgb,var(--primary) 8%,transparent); }
.strat-suggested { border-style:dashed; }
.strategy-opt input { position:absolute; opacity:0; pointer-events:none; }
.strat-lbl  { font-weight:700; font-size:13px; }
.strat-hint { font-size:10px; color:var(--text-muted); line-height:1.3; }
.strat-tag  { font-size:9px; font-weight:700; background:var(--primary); color:#fff; border-radius:20px; padding:1px 6px; }

/* Response actions */
.ra-item    { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; padding:12px 16px; border-bottom:1px solid var(--border); transition:background .1s; }
.ra-item:last-child { border-bottom:none; }
.ra-item:hover { background:var(--bg-secondary); }
.ra-done    { opacity:.6; }
.ra-badge   { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; border:1px solid; white-space:nowrap; flex-shrink:0; margin-top:1px; }

/* Financial bars */
.fin-bar-wrap { margin:14px 0 8px; display:flex; flex-direction:column; gap:8px; }
.fin-bar-row  { display:flex; align-items:center; gap:10px; font-size:12px; }
.fin-bar-row > span:first-child { width:40px; color:var(--text-muted); text-align:right; }
.fin-bar      { height:18px; border-radius:4px; min-width:4px; transition:width .3s; }
.fin-val      { font-weight:600; white-space:nowrap; }

/* Input group */
.input-group { display:flex; align-items:stretch; }
.input-prefix { background:var(--bg-secondary); border:1px solid var(--border); border-right:none; padding:0 10px; display:flex; align-items:center; font-size:13px; color:var(--text-muted); border-radius:6px 0 0 6px; }
.input-group .form-control { border-radius:0 6px 6px 0; }

/* Detail rows */
.detail-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; font-size:12px; border-bottom:1px solid var(--border); }
.detail-row:last-child { border-bottom:none; }
.detail-row > span:first-child { color:var(--text-muted); }

/* Desc box */
.r-desc-box { background:var(--bg-secondary); border-radius:8px; padding:12px 14px; font-size:13px; margin-bottom:16px; border:1px solid var(--border); }
</style>

<script nonce="<?= Security::nonce() ?>">
const LC = {Critical:'#ef4444',High:'#f97316',Medium:'#f59e0b',Low:'#22c55e'};
function lv(s){return s>14?'Critical':s>9?'High':s>4?'Medium':'Low'}
function chip(elId,sc,s){const e=document.getElementById(elId);if(!e)return;e.style.background=LC[lv(s)]+'20';e.style.color=LC[lv(s)];e.style.borderColor=LC[lv(s)]+'40';document.getElementById(sc).textContent=s;document.getElementById(sc.replace('sc_','sl_')).textContent=lv(s)}
function updateScores(){
  const l=+document.getElementById('s_l').value,i=+document.getElementById('s_i').value;
  const rl=+document.getElementById('s_rl').value,ri=+document.getElementById('s_ri').value;
  const tl=+document.getElementById('s_tl').value,ti=+document.getElementById('s_ti').value;
  const vals={l,i,rl,ri,tl,ti};
  ['l','i','rl','ri','tl','ti'].forEach(k=>{const e=document.getElementById('v_'+k);if(e)e.textContent=vals[k]});
  chip('chip_i','sc_i',l*i);chip('chip_r','sc_r',rl*ri);chip('chip_t','sc_t',tl*ti);
  onStratChange();
}
function onStratChange(){
  const score=parseInt(document.getElementById('sc_i').textContent);
  const el=document.getElementById('stratHint');if(!el)return;
  if(score>=20)el.textContent='— Score ≥20: Mitigate + Transfer recommended';
  else if(score>=15)el.textContent='— Score ≥15: Mitigate strongly recommended';
  else if(score>=10)el.textContent='— Score 10–14: Mitigate or Accept';
  else if(score>=5) el.textContent='— Score 5–9: Accept with monitoring';
  else el.textContent='— Score <5: Accept is appropriate';
}
updateScores();

<?php if (!empty($scoreHistory)): ?>
// Score history chart
(function(){
  const canvas=document.getElementById('histChart');
  if(!canvas)return;
  const ctx=canvas.getContext('2d');
  const W=canvas.offsetWidth||700; canvas.width=W; canvas.height=140;
  const data=<?= json_encode(array_map(fn($h)=>['s'=>(int)$h['score'],'r'=>$h['residual_score']?(int)$h['residual_score']:null,'d'=>date('M j',strtotime($h['created_at']))], $scoreHistory)) ?>;
  const PAD={t:12,r:20,b:30,l:36};
  const cW=W-PAD.l-PAD.r, cH=140-PAD.t-PAD.b;
  const maxS=25, n=data.length;
  function xp(i){return PAD.l+i*(cW/(n>1?n-1:1));}
  function yp(v){return PAD.t+cH-(v/maxS)*cH;}
  // Background bands
  const bands=[{y:0,h:4/25,c:'#22c55e18'},{y:4/25,h:5/25,c:'#f59e0b15'},{y:9/25,h:5/25,c:'#f9731615'},{y:14/25,h:11/25,c:'#ef444415'}];
  bands.forEach(b=>{ctx.fillStyle=b.c;ctx.fillRect(PAD.l,PAD.t+cH-b.h*cH-b.y*cH,cW,b.h*cH)});
  // Gridlines
  ctx.strokeStyle='#00000012';ctx.lineWidth=1;
  [5,10,15,20,25].forEach(v=>{ctx.beginPath();ctx.moveTo(PAD.l,yp(v));ctx.lineTo(PAD.l+cW,yp(v));ctx.stroke();
    ctx.fillStyle='#a1a1aa';ctx.font='10px sans-serif';ctx.fillText(v,2,yp(v)+3);});
  // Inherent line
  ctx.beginPath();ctx.strokeStyle='#ef4444';ctx.lineWidth=2;
  data.forEach((d,i)=>{i===0?ctx.moveTo(xp(i),yp(d.s)):ctx.lineTo(xp(i),yp(d.s))});ctx.stroke();
  // Residual line
  const hasRes=data.some(d=>d.r!==null);
  if(hasRes){ctx.beginPath();ctx.strokeStyle='#3b82f6';ctx.lineWidth=2;ctx.setLineDash([5,3]);
    data.forEach((d,i)=>{if(d.r===null)return;i===0?ctx.moveTo(xp(i),yp(d.r)):ctx.lineTo(xp(i),yp(d.r))});ctx.stroke();ctx.setLineDash([]);}
  // Dots and labels
  data.forEach((d,i)=>{
    ctx.fillStyle='#ef4444';ctx.beginPath();ctx.arc(xp(i),yp(d.s),3,0,Math.PI*2);ctx.fill();
    if(i===0||i===n-1||(n>8&&i%Math.ceil(n/6)===0)){ctx.fillStyle='#71717a';ctx.font='9px sans-serif';ctx.fillText(d.d,xp(i)-12,PAD.t+cH+14);}
  });
  // Legend
  ctx.fillStyle='#ef4444';ctx.fillRect(W-130,8,12,3);ctx.fillStyle='#374151';ctx.font='10px sans-serif';ctx.fillText('Inherent',W-114,12);
  if(hasRes){ctx.strokeStyle='#3b82f6';ctx.setLineDash([4,2]);ctx.beginPath();ctx.moveTo(W-60,10);ctx.lineTo(W-48,10);ctx.stroke();ctx.setLineDash([]);ctx.fillStyle='#374151';ctx.fillText('Residual',W-44,12);}
})();
<?php endif; ?>
</script>
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
