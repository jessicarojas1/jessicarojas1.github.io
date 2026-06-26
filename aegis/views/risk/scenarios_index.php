<?php
/**
 * views/risk/scenarios_index.php
 * All risk scenarios across the portfolio.
 * Variables: $scenarios, $countByType, $highestScore, $totalFinancial
 */
$breadcrumbs = [['Risk Register', '/risk'], ['Scenarios', null]];
$nonce = Security::nonce();

$typeMeta = [
    'stress'       => ['label'=>'Stress',        'color'=>'#dc2626','bg'=>'#fef2f2','border'=>'#fca5a5','icon'=>'bi-graph-up-arrow'],
    'base'         => ['label'=>'Base',           'color'=>'#71717a','bg'=>'#f4f4f5','border'=>'#d4d4d8','icon'=>'bi-circle-fill'],
    'optimistic'   => ['label'=>'Optimistic',     'color'=>'#16a34a','bg'=>'#f0fdf4','border'=>'#86efac','icon'=>'bi-graph-down-arrow'],
    'catastrophic' => ['label'=>'Catastrophic',   'color'=>'#111111','bg'=>'#f9fafb','border'=>'#a1a1aa','icon'=>'bi-exclamation-octagon-fill'],
    'regulatory'   => ['label'=>'Regulatory',     'color'=>'#2563eb','bg'=>'#eff6ff','border'=>'#93c5fd','icon'=>'bi-bank'],
];

function scIdxLevel(int $s): string {
    return $s > 14 ? 'Critical' : ($s > 9 ? 'High' : ($s > 4 ? 'Medium' : 'Low'));
}
function scIdxLevelClass(int $s): string {
    return $s > 14 ? 'risk-critical' : ($s > 9 ? 'risk-high' : ($s > 4 ? 'risk-medium' : 'risk-low'));
}
?>
<style nonce="<?= $nonce ?>">
.sc-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.sc-stat{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center}
.sc-stat .num{font-size:26px;font-weight:900;line-height:1}
.sc-stat .lbl{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-top:3px}
.sc-type-badge{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px}
.sc-risk-link{font-weight:600;color:var(--text);text-decoration:none}
.sc-risk-link:hover{color:var(--primary)}
</style>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Scenarios</h1>
    <p class="page-subtitle">All modeled scenarios across the risk portfolio</p>
  </div>
  <div class="page-actions">
    <a href="/risk" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Risk Register</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Stats -->
<div class="sc-stat-grid">
  <div class="sc-stat">
    <div class="num"><?= count($scenarios) ?></div>
    <div class="lbl">Total Scenarios</div>
  </div>
  <div class="sc-stat">
    <div class="num <?= $highestScore > 14 ? 'text-danger' : ($highestScore > 9 ? '' : '') ?>"
         style="color:<?= $highestScore > 14 ? 'var(--danger)' : ($highestScore > 9 ? 'var(--orange)' : ($highestScore > 4 ? 'var(--warning)' : 'var(--success)')) ?>">
      <?= $highestScore ?>
    </div>
    <div class="lbl">Highest Scenario Score</div>
  </div>
  <?php if ($totalFinancial > 0): ?>
  <div class="sc-stat">
    <div class="num" style="font-size:18px;color:var(--success)">$<?= number_format($totalFinancial, 0) ?></div>
    <div class="lbl">Total Financial Exposure</div>
  </div>
  <?php endif; ?>
  <?php foreach ($typeMeta as $type => $meta): ?>
    <?php if (($countByType[$type] ?? 0) > 0): ?>
    <div class="sc-stat">
      <div class="num" style="color:<?= $meta['color'] ?>"><?= $countByType[$type] ?></div>
      <div class="lbl"><?= $meta['label'] ?></div>
    </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<?php if (empty($scenarios)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:50px 20px">
    <i class="bi bi-diagram-3" style="font-size:48px;color:var(--text-muted);opacity:.4;display:block;margin-bottom:14px"></i>
    <h3 style="font-size:16px;font-weight:700;margin-bottom:6px">No Scenarios Yet</h3>
    <p style="font-size:13px;color:var(--text-muted);max-width:400px;margin:0 auto">
      Scenarios are created from individual risk records. Navigate to a risk and use
      "Add Scenario" to begin modeling stress, optimistic, or catastrophic conditions.
    </p>
    <a href="/risk" class="btn btn-primary btn-sm" style="margin-top:16px">
      <i class="bi bi-arrow-right"></i> Go to Risk Register
    </a>
  </div>
</div>
<?php else: ?>

<div class="card">
  <div class="card-body p0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="border-bottom:2px solid var(--border);background:var(--bg-secondary)">
          <th scope="col" style="padding:10px 14px;font-weight:600;color:var(--text-muted);text-align:left">Scenario</th>
          <th scope="col" style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:left">Risk</th>
          <th scope="col" style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:center">Base Score</th>
          <th scope="col" style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:center">Scenario Score</th>
          <th scope="col" style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:center">Delta</th>
          <th scope="col" style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:right">Financial Est.</th>
          <th scope="col" style="padding:10px 12px;font-weight:600;color:var(--text-muted);text-align:right">Probability</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($scenarios as $idx => $sc):
          $sm     = $typeMeta[$sc['scenario_type']] ?? $typeMeta['stress'];
          $ss     = (int)$sc['scenario_score'];
          $base   = (int)$sc['inherent_score'];
          $delta  = $ss - $base;
          $rowBg  = $idx % 2 === 0 ? '' : 'background:var(--bg-secondary)';
        ?>
        <tr style="border-bottom:1px solid var(--border);<?= $rowBg ?>;vertical-align:middle">
          <td style="padding:10px 14px">
            <div style="display:flex;flex-direction:column;gap:3px">
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                <a href="/risk/<?= (int)$sc['risk_id'] ?>#risk-scenarios-section"
                   class="sc-risk-link">
                  <?= Security::h($sc['name']) ?>
                </a>
                <span class="sc-type-badge" style="background:<?= $sm['color'] ?>18;color:<?= $sm['color'] ?>;border:1px solid <?= $sm['color'] ?>40">
                  <i class="bi <?= $sm['icon'] ?>"></i> <?= $sm['label'] ?>
                </span>
              </div>
              <?php if ($sc['description']): ?>
              <div style="font-size:11px;color:var(--text-muted)"><?= Security::h(mb_substr($sc['description'], 0, 90)) ?><?= mb_strlen($sc['description']) > 90 ? '…' : '' ?></div>
              <?php endif; ?>
            </div>
          </td>

          <td style="padding:10px 12px">
            <a href="/risk/<?= (int)$sc['risk_id'] ?>"
               style="color:var(--primary);text-decoration:none;font-weight:500">
              <?= Security::h($sc['risk_title']) ?>
            </a>
            <?php if ($sc['risk_code']): ?>
            <div class="mono" style="font-size:11px;color:var(--text-muted)"><?= Security::h($sc['risk_code']) ?></div>
            <?php endif; ?>
          </td>

          <td style="padding:10px 12px;text-align:center">
            <span class="risk-badge <?= scIdxLevelClass($base) ?>" style="font-size:12px;padding:2px 8px">
              <?= $base ?>
            </span>
          </td>

          <td style="padding:10px 12px;text-align:center">
            <span class="risk-badge <?= scIdxLevelClass($ss) ?>" style="font-size:13px;font-weight:900;padding:3px 10px">
              <?= $ss ?>
            </span>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              L=<?= (int)$sc['scenario_likelihood'] ?> × I=<?= (int)$sc['scenario_impact'] ?>
            </div>
          </td>

          <td style="padding:10px 12px;text-align:center;white-space:nowrap">
            <?php if ($delta > 0): ?>
              <span style="display:inline-flex;align-items:center;gap:2px;font-size:12px;font-weight:700;padding:2px 7px;border-radius:20px;background:var(--danger-subtle);color:var(--danger)">
                <i class="bi bi-arrow-up"></i> +<?= $delta ?>
              </span>
            <?php elseif ($delta < 0): ?>
              <span style="display:inline-flex;align-items:center;gap:2px;font-size:12px;font-weight:700;padding:2px 7px;border-radius:20px;background:var(--success-subtle);color:var(--success)">
                <i class="bi bi-arrow-down"></i> <?= $delta ?>
              </span>
            <?php else: ?>
              <span style="display:inline-flex;align-items:center;font-size:12px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--bg-secondary);color:var(--text-muted)">
                &#177;0
              </span>
            <?php endif; ?>
          </td>

          <td style="padding:10px 12px;text-align:right;white-space:nowrap">
            <?php if (!empty($sc['financial_impact_est'])): ?>
              <strong>$<?= number_format((float)$sc['financial_impact_est'], 0) ?></strong>
            <?php else: ?>
              <span style="color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>

          <td style="padding:10px 12px;text-align:right;white-space:nowrap">
            <?php if ($sc['probability'] !== null && $sc['probability'] !== ''): ?>
              <strong><?= number_format((float)$sc['probability'], 1) ?>%</strong>
            <?php else: ?>
              <span style="color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
