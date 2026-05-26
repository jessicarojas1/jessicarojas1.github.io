<?php
/**
 * views/risk/scenarios_partial.php
 * Partial — included inside the risk detail view (no ob_start, no layout.php).
 * Variables: $risk (risk record), $scenarios (array of scenario records for this risk)
 */

$baseScore = (int)$risk['inherent_score'];
$baseL     = (int)$risk['likelihood'];
$baseI     = (int)$risk['impact'];

$typeMeta = [
    'stress'       => ['label'=>'Stress',        'color'=>'#dc2626', 'bg'=>'#fef2f2', 'border'=>'#fca5a5', 'icon'=>'bi-graph-up-arrow'],
    'base'         => ['label'=>'Base',           'color'=>'#64748b', 'bg'=>'#f1f5f9', 'border'=>'#cbd5e1', 'icon'=>'bi-circle-fill'],
    'optimistic'   => ['label'=>'Optimistic',     'color'=>'#16a34a', 'bg'=>'#f0fdf4', 'border'=>'#86efac', 'icon'=>'bi-graph-down-arrow'],
    'catastrophic' => ['label'=>'Catastrophic',   'color'=>'#1e293b', 'bg'=>'#f8fafc', 'border'=>'#94a3b8', 'icon'=>'bi-exclamation-octagon-fill'],
    'regulatory'   => ['label'=>'Regulatory',     'color'=>'#2563eb', 'bg'=>'#eff6ff', 'border'=>'#93c5fd', 'icon'=>'bi-bank'],
];

function scenPartialLevel(int $s): string {
    return $s > 14 ? 'Critical' : ($s > 9 ? 'High' : ($s > 4 ? 'Medium' : 'Low'));
}
function scenPartialLevelClass(int $s): string {
    return $s > 14 ? 'risk-critical' : ($s > 9 ? 'risk-high' : ($s > 4 ? 'risk-medium' : 'risk-low'));
}

// Compute summary stats
$worstScore    = 0;
$totalScoreSum = 0;
$totalFinancial = 0.0;
foreach ($scenarios as $sc) {
    $ss = (int)$sc['scenario_score'];
    if ($ss > $worstScore)  $worstScore = $ss;
    $totalScoreSum += $ss;
    if (!empty($sc['financial_impact_est'])) {
        $totalFinancial += (float)$sc['financial_impact_est'];
    }
}
$avgScore = count($scenarios) > 0 ? round($totalScoreSum / count($scenarios), 1) : 0;
?>

<div class="card" style="margin-top:20px" id="risk-scenarios-section">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-diagram-3-fill"></i> Risk Scenarios</h3>
    <?php if (Auth::can('risk.write')): ?>
    <a href="/risk/<?= (int)$risk['id'] ?>/scenario/create" class="btn btn-sm btn-secondary">
      <i class="bi bi-plus-lg"></i> Add Scenario
    </a>
    <?php endif; ?>
  </div>

  <?php if (empty($scenarios)): ?>
  <!-- Empty state -->
  <div class="card-body" style="text-align:center;padding:40px 20px">
    <div style="margin-bottom:12px">
      <i class="bi bi-diagram-3" style="font-size:40px;color:var(--text-muted);opacity:.5"></i>
    </div>
    <h4 style="font-size:15px;font-weight:700;margin-bottom:6px;color:var(--text-muted)">No Scenarios Modeled</h4>
    <p style="font-size:13px;color:var(--text-muted);max-width:400px;margin:0 auto 16px">
      Risk scenarios let you model how this risk behaves under different conditions —
      stress, optimistic, regulatory change, or catastrophic failure. Each scenario
      applies likelihood and impact multipliers to produce a projected score.
    </p>
    <?php if (Auth::can('risk.write')): ?>
    <a href="/risk/<?= (int)$risk['id'] ?>/scenario/create" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg"></i> Create First Scenario
    </a>
    <?php endif; ?>
  </div>

  <?php else: ?>

  <div class="card-body p0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="border-bottom:2px solid var(--border);background:var(--bg-secondary)">
          <th style="padding:10px 14px;font-weight:600;color:var(--text-muted);text-align:left">Scenario</th>
          <th style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:center">Scenario Score</th>
          <th style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:center">vs Base</th>
          <th style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:right">Financial Est.</th>
          <th style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:right">Probability</th>
          <?php if (Auth::can('risk.write')): ?>
          <th style="padding:10px 12px;width:36px"></th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($scenarios as $idx => $sc):
          $sm     = $typeMeta[$sc['scenario_type']] ?? $typeMeta['stress'];
          $ss     = (int)$sc['scenario_score'];
          $delta  = $ss - $baseScore;
          $sL     = (int)$sc['scenario_likelihood'];
          $sI     = (int)$sc['scenario_impact'];
          $rowBg  = $idx % 2 === 0 ? '' : 'background:var(--bg-secondary)';
          $uniqueId = 'sc-assump-' . (int)$sc['id'];
        ?>
        <tr style="border-bottom:1px solid var(--border);<?= $rowBg ?>;vertical-align:top">
          <!-- Name + type -->
          <td style="padding:12px 14px">
            <div style="display:flex;align-items:flex-start;gap:8px">
              <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:3px">
                  <span style="font-weight:600;color:var(--text)"><?= Security::h($sc['name']) ?></span>
                  <span style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;
                               padding:2px 7px;border-radius:20px;
                               background:<?= $sm['bg'] ?>;color:<?= $sm['color'] ?>;border:1px solid <?= $sm['border'] ?>">
                    <i class="bi <?= $sm['icon'] ?>"></i> <?= $sm['label'] ?>
                  </span>
                </div>
                <?php if ($sc['description']): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px"><?= Security::h($sc['description']) ?></div>
                <?php endif; ?>
                <?php if ($sc['assumptions']): ?>
                <div style="margin-top:4px">
                  <button type="button"
                          onclick="var el=document.getElementById('<?= $uniqueId ?>');el.style.display=el.style.display==='none'?'block':'none';this.innerHTML=el.style.display!=='none'?'<i class=&quot;bi bi-chevron-up&quot;></i> Hide assumptions':'<i class=&quot;bi bi-chevron-down&quot;></i> Show assumptions'"
                          style="background:none;border:none;padding:0;font-size:11px;color:var(--primary);cursor:pointer;font-weight:600">
                    <i class="bi bi-chevron-down"></i> Show assumptions
                  </button>
                  <div id="<?= $uniqueId ?>" style="display:none;margin-top:6px;background:var(--bg-secondary);
                       border-left:3px solid var(--border);padding:8px 10px;border-radius:0 6px 6px 0;
                       font-size:12px;color:var(--text-muted);white-space:pre-wrap"><?= Security::h($sc['assumptions']) ?></div>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </td>

          <!-- Scenario score -->
          <td style="padding:12px;text-align:center;white-space:nowrap">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">
              L=<?= $sL ?> × I=<?= $sI ?>
            </div>
            <span class="risk-badge <?= scenPartialLevelClass($ss) ?>" style="font-size:14px;font-weight:900;padding:3px 10px">
              <?= $ss ?>
            </span>
            <div style="font-size:11px;color:var(--text-muted);margin-top:3px"><?= scenPartialLevel($ss) ?></div>
          </td>

          <!-- vs Base delta -->
          <td style="padding:12px;text-align:center;white-space:nowrap">
            <?php if ($delta > 0): ?>
              <span style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:700;
                           padding:3px 8px;border-radius:20px;background:#fee2e2;color:#dc2626">
                <i class="bi bi-arrow-up"></i> +<?= $delta ?>
              </span>
            <?php elseif ($delta < 0): ?>
              <span style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:700;
                           padding:3px 8px;border-radius:20px;background:#d1fae5;color:#065f46">
                <i class="bi bi-arrow-down"></i> <?= $delta ?>
              </span>
            <?php else: ?>
              <span style="display:inline-flex;align-items:center;gap:3px;font-size:12px;font-weight:600;
                           padding:3px 8px;border-radius:20px;background:#f1f5f9;color:#64748b">
                &#177; 0
              </span>
            <?php endif; ?>
            <div style="font-size:10px;color:var(--text-muted);margin-top:3px">
              base: <?= $baseScore ?>
            </div>
          </td>

          <!-- Financial estimate -->
          <td style="padding:12px;text-align:right;white-space:nowrap;font-size:13px">
            <?php if (!empty($sc['financial_impact_est'])): ?>
              <strong>$<?= number_format((float)$sc['financial_impact_est'], 0) ?></strong>
            <?php else: ?>
              <span style="color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>

          <!-- Probability -->
          <td style="padding:12px;text-align:right;white-space:nowrap;font-size:13px">
            <?php if ($sc['probability'] !== null && $sc['probability'] !== ''): ?>
              <strong><?= number_format((float)$sc['probability'], 1) ?>%</strong>
            <?php else: ?>
              <span style="color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>

          <!-- Delete -->
          <?php if (Auth::can('risk.write')): ?>
          <td style="padding:12px 10px;text-align:center">
            <form method="POST" action="/risk/scenario/<?= (int)$sc['id'] ?>/delete"
                  onsubmit="return confirm('Delete scenario \'<?= addslashes(Security::h($sc['name'])) ?>\'?')">
              <?= Security::csrfField() ?>
              <button type="submit"
                      style="background:none;border:none;padding:4px 6px;cursor:pointer;color:#ef4444;border-radius:4px"
                      title="Delete scenario">
                <i class="bi bi-trash3-fill"></i>
              </button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Summary row -->
    <div style="padding:12px 16px;border-top:2px solid var(--border);background:var(--bg-secondary);
                display:flex;flex-wrap:wrap;gap:16px;align-items:center;font-size:12px;color:var(--text-muted)">
      <span>
        <i class="bi bi-exclamation-triangle-fill" style="color:#f97316"></i>
        <strong style="color:var(--text)">Worst case score:</strong>
        <span class="risk-badge <?= scenPartialLevelClass($worstScore) ?>" style="font-size:12px;padding:1px 8px"><?= $worstScore ?></span>
        <?= scenPartialLevel($worstScore) ?>
      </span>
      <span>
        <i class="bi bi-calculator-fill" style="color:#6366f1"></i>
        <strong style="color:var(--text)">Average score:</strong> <?= $avgScore ?>
      </span>
      <?php if ($totalFinancial > 0): ?>
      <span>
        <i class="bi bi-currency-dollar" style="color:#16a34a"></i>
        <strong style="color:var(--text)">Total financial exposure:</strong>
        $<?= number_format($totalFinancial, 0) ?>
      </span>
      <?php endif; ?>
      <span style="margin-left:auto;color:var(--text-muted)"><?= count($scenarios) ?> scenario<?= count($scenarios) !== 1 ? 's' : '' ?></span>
    </div>

  </div><!-- /card-body -->
  <?php endif; ?>

</div><!-- /card -->
