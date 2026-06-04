<?php
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$rag = $kri['rag'] ?? 'grey';

$ragMap = [
    'green' => ['#16a34a', '#f0fdf4', '#bbf7d0', 'GREEN',   'bi-check-circle-fill'],
    'amber' => ['#d97706', '#fffbeb', '#fde68a', 'AMBER',   'bi-exclamation-triangle-fill'],
    'red'   => ['#dc2626', '#fef2f2', '#fecaca', 'RED',     'bi-exclamation-octagon-fill'],
    'grey'  => ['#71717a', '#f9fafb', '#e4e4e7', 'NO DATA', 'bi-dash-circle-fill'],
];
[$ragColor, $ragBg, $ragBorder, $ragLabel, $ragIcon] = $ragMap[$rag] ?? $ragMap['grey'];

$green = (float)$kri['threshold_green'];
$amber = (float)$kri['threshold_amber'];
$red   = (float)$kri['threshold_red'];
$dir   = $kri['direction'] ?? 'higher_worse';

$freqLabels = ['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','quarterly'=>'Quarterly'];
$freqLabel  = $freqLabels[$kri['frequency'] ?? 'monthly'] ?? ucfirst($kri['frequency'] ?? '');

// Latest value
$latestVal = null;
if (!empty($values)) {
    $latestVal = (float)$values[0]['value'];
}

// Trend: compare avg of last 3 vs previous 3
$trend = null;
if (count($values) >= 2) {
    $last3 = array_slice($values, 0, 3);
    $prev3 = array_slice($values, 3, 3);
    $avgLast = count($last3) ? array_sum(array_column($last3, 'value')) / count($last3) : null;
    $avgPrev = count($prev3) ? array_sum(array_column($prev3, 'value')) / count($prev3) : null;
    if ($avgLast !== null && $avgPrev !== null) {
        $trend = ($avgLast > $avgPrev) ? 'up' : (($avgLast < $avgPrev) ? 'down' : 'flat');
    }
}

// Determine RAG for each historical value
function kriValueRag(float $val, float $green, float $amber, float $red, string $dir): string {
    if ($dir === 'higher_worse') {
        if ($val <= $green) return 'green';
        if ($val <= $amber) return 'amber';
        return 'red';
    } else {
        if ($val >= $green) return 'green';
        if ($val >= $amber) return 'amber';
        return 'red';
    }
}

// Scale bar percentages
$scaleMax = max($dir === 'higher_worse' ? $red * 1.2 : $green * 1.2, 0.0001);
if ($dir === 'higher_worse') {
    $greenPct = round(($green / $scaleMax) * 100, 1);
    $amberPct = round((($amber - $green) / $scaleMax) * 100, 1);
    $redPct   = round((($red - $amber) / $scaleMax) * 100, 1);
    $overPct  = max(0, 100 - $greenPct - $amberPct - $redPct);
} else {
    $redPct   = round(($red / $scaleMax) * 100, 1);
    $amberPct = round((($amber - $red) / $scaleMax) * 100, 1);
    $greenPct = round((($green - $amber) / $scaleMax) * 100, 1);
    $overPct  = max(0, 100 - $redPct - $amberPct - $greenPct);
}

// Current value marker position
$markerPct = null;
if ($latestVal !== null) {
    $markerPct = min(98, max(1, round(($latestVal / $scaleMax) * 100, 1)));
}

function fmtNum(float $n): string {
    return rtrim(rtrim(number_format($n, 4), '0'), '.');
}
?>

<?php if ($flashSuccess): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($flashError) ?></div>
<?php endif; ?>

<!-- Page header -->
<div class="page-header">
  <div style="display:flex;align-items:flex-start;gap:14px;">
    <div>
      <h1 class="page-title" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:<?= $ragColor ?>;box-shadow:0 0 0 4px <?= $ragColor ?>22;flex-shrink:0;"></span>
        <?= Security::h($kri['title']) ?>
        <span style="background:var(--bg-subtle);color:var(--text-muted);padding:3px 12px;border-radius:99px;font-size:13px;font-weight:600;border:1px solid var(--border);">
          <?= $freqLabel ?>
        </span>
      </h1>
      <p class="page-subtitle">Key Risk Indicator &mdash; <?= $dir === 'higher_worse' ? 'Higher values indicate more risk' : 'Lower values indicate more risk' ?></p>
    </div>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('risk.write')): ?>
      <form method="POST" action="/kris/<?= (int)$kri['id'] ?>/toggle" style="display:inline;">
        <?= Security::csrfField() ?>
        <button type="submit" class="btn btn-ghost btn-sm">
          <i class="bi bi-<?= $kri['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
          <?= $kri['is_active'] ? 'Deactivate' : 'Reactivate' ?>
        </button>
      </form>
    <?php endif; ?>
    <a href="/kris" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Dashboard</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">

  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Hero RAG Banner -->
    <div class="card" style="border-left:6px solid <?= $ragColor ?>;background:<?= $ragBg ?>;">
      <div class="card-body" style="padding:24px;">
        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
          <div style="text-align:center;">
            <div style="font-size:13px;font-weight:700;color:<?= $ragColor ?>;letter-spacing:.06em;margin-bottom:6px;">STATUS</div>
            <div style="display:flex;align-items:center;gap:8px;">
              <i class="bi <?= $ragIcon ?>" style="font-size:28px;color:<?= $ragColor ?>;"></i>
              <span style="font-size:28px;font-weight:800;color:<?= $ragColor ?>;"><?= $ragLabel ?></span>
            </div>
          </div>
          <div style="width:1px;height:60px;background:<?= $ragColor ?>33;flex-shrink:0;"></div>
          <div>
            <div style="font-size:13px;font-weight:700;color:var(--text-muted);letter-spacing:.06em;margin-bottom:4px;">CURRENT VALUE</div>
            <?php if ($latestVal !== null): ?>
              <div style="font-size:40px;font-weight:800;line-height:1;color:<?= $ragColor ?>;">
                <?= Security::h(fmtNum($latestVal)) ?>
                <span style="font-size:16px;font-weight:500;color:var(--text-muted);margin-left:4px;"><?= Security::h($kri['unit'] ?? '') ?></span>
              </div>
              <?php if (!empty($values[0]['recorded_at'])): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                  Recorded <?= Security::h(date('M j, Y', strtotime($values[0]['recorded_at']))) ?>
                  <?php if (!empty($values[0]['recorder_name'])): ?>
                    by <?= Security::h($values[0]['recorder_name']) ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div style="font-size:28px;font-weight:600;color:var(--text-light);">No data yet</div>
            <?php endif; ?>
          </div>
          <?php if ($trend !== null): ?>
            <div style="margin-left:auto;text-align:center;">
              <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:4px;">TREND</div>
              <?php
              $trendUp = ($trend === 'up');
              $trendDown = ($trend === 'down');
              $trendNeutral = ($trend === 'flat');
              // For higher_worse: up trend is bad (red); for lower_worse: down trend is bad
              $trendBad = ($dir === 'higher_worse' && $trendUp) || ($dir === 'lower_worse' && $trendDown);
              $trendGood = ($dir === 'higher_worse' && $trendDown) || ($dir === 'lower_worse' && $trendUp);
              $trendColor = $trendBad ? '#dc2626' : ($trendGood ? '#16a34a' : '#71717a');
              $trendIcon = $trendUp ? 'bi-arrow-up-circle-fill' : ($trendDown ? 'bi-arrow-down-circle-fill' : 'bi-dash-circle-fill');
              $trendText = $trendUp ? 'Trending ↑' : ($trendDown ? 'Trending ↓' : 'Flat');
              ?>
              <div style="color:<?= $trendColor ?>;font-size:24px;"><i class="bi <?= $trendIcon ?>"></i></div>
              <div style="font-size:12px;font-weight:700;color:<?= $trendColor ?>;"><?= $trendText ?></div>
              <div style="font-size:10px;color:var(--text-muted);">last 3 vs prev 3</div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Threshold Scale -->
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-sliders"></i> Threshold Scale</h4>
      </div>
      <div class="card-body">
        <div style="position:relative;margin:20px 0 30px;">
          <!-- Bar -->
          <div style="height:20px;border-radius:6px;overflow:hidden;display:flex;background:#e4e4e7;">
            <?php if ($dir === 'higher_worse'): ?>
              <div style="width:<?= $greenPct ?>%;background:#16a34a;" title="Green zone"></div>
              <div style="width:<?= $amberPct ?>%;background:#d97706;" title="Amber zone"></div>
              <div style="width:<?= $redPct ?>%;background:#dc2626;"   title="Red zone"></div>
              <div style="width:<?= $overPct ?>%;background:#b91c1c99;" title="Beyond red"></div>
            <?php else: ?>
              <div style="width:<?= $redPct ?>%;background:#dc2626;"   title="Red zone (too low)"></div>
              <div style="width:<?= $amberPct ?>%;background:#d97706;" title="Amber zone"></div>
              <div style="width:<?= $greenPct ?>%;background:#16a34a;" title="Green zone"></div>
              <div style="width:<?= $overPct ?>%;background:#15803d99;" title="Above green"></div>
            <?php endif; ?>
          </div>
          <!-- Value marker -->
          <?php if ($markerPct !== null): ?>
            <div style="position:absolute;top:-4px;left:<?= $markerPct ?>%;transform:translateX(-50%);">
              <div style="width:4px;height:28px;background:#111111;border-radius:2px;"></div>
              <div style="position:absolute;top:-18px;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:10px;font-weight:700;color:var(--text);background:#fff;border:1px solid #e4e4e7;padding:1px 5px;border-radius:4px;">
                <?= Security::h(fmtNum($latestVal)) ?> <?= Security::h($kri['unit'] ?? '') ?>
              </div>
            </div>
          <?php endif; ?>
          <!-- Labels below -->
          <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:11px;font-weight:600;">
            <span style="color:#16a34a;"><i class="bi bi-circle-fill" style="font-size:8px;"></i> Green &le; <?= Security::h(fmtNum($green)) ?></span>
            <span style="color:#d97706;"><i class="bi bi-circle-fill" style="font-size:8px;"></i> Amber &le; <?= Security::h(fmtNum($amber)) ?></span>
            <span style="color:#dc2626;"><i class="bi bi-circle-fill" style="font-size:8px;"></i> Red &gt; <?= Security::h(fmtNum($amber)) ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Details card -->
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-info-circle"></i> KRI Details</h4>
      </div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <?php if (!empty($kri['description'])): ?>
            <div style="grid-column:1/-1;">
              <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:4px;">DESCRIPTION</div>
              <div style="font-size:14px;color:var(--text-primary);"><?= nl2br(Security::h($kri['description'])) ?></div>
            </div>
          <?php endif; ?>
          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:2px;">UNIT</div>
            <div style="font-size:14px;"><?= Security::h($kri['unit'] ?? '—') ?></div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:2px;">DIRECTION</div>
            <div style="font-size:14px;">
              <i class="bi bi-arrow-<?= $dir === 'higher_worse' ? 'up' : 'down' ?>-circle" style="color:<?= $dir === 'higher_worse' ? '#dc2626' : '#d97706' ?>;"></i>
              <?= $dir === 'higher_worse' ? 'Higher = worse' : 'Lower = worse' ?>
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:2px;">FREQUENCY</div>
            <div style="font-size:14px;"><?= $freqLabel ?></div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:2px;">OWNER</div>
            <div style="font-size:14px;"><?= Security::h($kri['owner_name'] ?? 'Unassigned') ?></div>
          </div>
          <?php if (!empty($kri['risk_title'])): ?>
            <div style="grid-column:1/-1;">
              <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:2px;">LINKED RISK</div>
              <div style="font-size:14px;">
                <a href="/risk/<?= (int)$kri['linked_risk_id'] ?>" style="color:var(--primary);">
                  <i class="bi bi-link-45deg"></i> <?= Security::h($kri['risk_title']) ?>
                </a>
              </div>
            </div>
          <?php endif; ?>
          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:2px;">STATUS</div>
            <div style="font-size:14px;">
              <span style="background:<?= $kri['is_active'] ? '#f0fdf4' : '#f9fafb' ?>;color:<?= $kri['is_active'] ? '#16a34a' : '#71717a' ?>;padding:2px 10px;border-radius:99px;font-size:12px;font-weight:600;">
                <?= $kri['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </div>
          </div>
          <div>
            <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:2px;">CREATED</div>
            <div style="font-size:14px;"><?= Security::h(date('M j, Y', strtotime($kri['created_at']))) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- History Table -->
    <?php if (!empty($values)): ?>
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-clock-history"></i> Reading History <span style="font-size:12px;font-weight:400;color:var(--text-muted);">(last 24)</span></h4>
      </div>
      <div class="card-body p0">
        <table class="table data-table" style="min-width:0;">
          <thead>
            <tr>
              <th>Date</th>
              <th>Value</th>
              <th style="width:60px;">RAG</th>
              <th>Notes</th>
              <th>Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($values as $v):
              $vRag = kriValueRag((float)$v['value'], $green, $amber, $red, $dir);
              $vColors = ['green'=>['#f0fdf4','#16a34a'],'amber'=>['#fffbeb','#d97706'],'red'=>['#fef2f2','#dc2626']];
              [$vBg,$vColor] = $vColors[$vRag] ?? ['#f9fafb','#71717a'];
              $vIcon = ['green'=>'bi-check-circle-fill','amber'=>'bi-exclamation-triangle-fill','red'=>'bi-exclamation-octagon-fill'][$vRag] ?? 'bi-dash-circle';
            ?>
              <tr>
                <td style="font-weight:500;"><?= Security::h(date('M j, Y', strtotime($v['recorded_at']))) ?></td>
                <td style="font-weight:700;color:<?= $vColor ?>;">
                  <?= Security::h(fmtNum((float)$v['value'])) ?> <span style="font-size:11px;color:var(--text-muted);"><?= Security::h($kri['unit'] ?? '') ?></span>
                </td>
                <td>
                  <i class="bi <?= $vIcon ?>" style="color:<?= $vColor ?>;font-size:16px;" title="<?= strtoupper($vRag) ?>"></i>
                </td>
                <td style="font-size:12px;color:var(--text-muted);"><?= !empty($v['notes']) ? Security::h($v['notes']) : '<span style="color:var(--text-light);">—</span>' ?></td>
                <td style="font-size:12px;"><?= Security::h($v['recorder_name'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Right column: Record Value form -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <?php if (Auth::can('risk.write') && $kri['is_active']): ?>
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-plus-circle-fill"></i> Record New Value</h4>
      </div>
      <div class="card-body">
        <form method="POST" action="/kris/<?= (int)$kri['id'] ?>/record">
          <?= Security::csrfField() ?>

          <div class="form-group">
            <label class="form-label">Value <span style="color:#ef4444;">*</span></label>
            <div style="display:flex;align-items:center;gap:8px;">
              <input type="number" name="value" class="form-control" step="any" required
                     placeholder="Enter measurement"
                     style="flex:1;">
              <?php if (!empty($kri['unit'])): ?>
                <span style="font-size:13px;font-weight:600;color:var(--text-muted);white-space:nowrap;">
                  <?= Security::h($kri['unit']) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Date</label>
            <input type="date" name="recorded_at" class="form-control"
                   value="<?= date('Y-m-d') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Notes <span style="font-size:11px;font-weight:400;color:var(--text-muted);">(optional)</span></label>
            <textarea name="notes" class="form-control" rows="3"
                      placeholder="Any context, caveats, or observations..."></textarea>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;">
            <i class="bi bi-plus-lg"></i> Record Value
          </button>
        </form>
      </div>
    </div>
    <?php elseif (!$kri['is_active']): ?>
    <div class="card" style="background:#f9fafb;border:1.5px dashed #e4e4e7;">
      <div class="card-body" style="text-align:center;padding:24px;">
        <i class="bi bi-pause-circle" style="font-size:32px;color:var(--text-light);display:block;margin-bottom:8px;"></i>
        <div style="font-size:13px;color:var(--text-muted);">This KRI is inactive. Reactivate it to record new values.</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Threshold Reference Card -->
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-bookmark-check"></i> Threshold Reference</h4>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:10px;">
        <div style="display:flex;align-items:center;justify-content:space-between;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <i class="bi bi-circle-fill" style="color:#16a34a;font-size:12px;"></i>
            <span style="font-weight:600;color:#16a34a;">Green</span>
          </div>
          <span style="font-size:15px;font-weight:700;color:#16a34a;">
            <?= $dir === 'higher_worse' ? '&le;' : '&ge;' ?> <?= Security::h(fmtNum($green)) ?> <?= Security::h($kri['unit'] ?? '') ?>
          </span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <i class="bi bi-circle-fill" style="color:#d97706;font-size:12px;"></i>
            <span style="font-weight:600;color:#d97706;">Amber</span>
          </div>
          <span style="font-size:15px;font-weight:700;color:#d97706;">
            <?= $dir === 'higher_worse' ? '&le;' : '&ge;' ?> <?= Security::h(fmtNum($amber)) ?> <?= Security::h($kri['unit'] ?? '') ?>
          </span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;">
          <div style="display:flex;align-items:center;gap:8px;">
            <i class="bi bi-circle-fill" style="color:#dc2626;font-size:12px;"></i>
            <span style="font-weight:600;color:#dc2626;">Red</span>
          </div>
          <span style="font-size:15px;font-weight:700;color:#dc2626;">
            <?= $dir === 'higher_worse' ? '&gt;' : '&lt;' ?> <?= Security::h(fmtNum($amber)) ?> <?= Security::h($kri['unit'] ?? '') ?>
          </span>
        </div>
      </div>
    </div>

    <?php if (!empty($values) && count($values) >= 2): ?>
    <!-- Mini chart -->
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-graph-up"></i> Trend (last readings)</h4>
      </div>
      <div class="card-body" style="padding:12px 16px;">
        <canvas id="kriChart" height="140"></canvas>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php if (!empty($values) && count($values) >= 2): ?>
<script nonce="<?= Security::nonce() ?>">
(function() {
  var labels = <?= json_encode(array_map(fn($v) => date('M j', strtotime($v['recorded_at'])), array_reverse($values))) ?>;
  var data   = <?= json_encode(array_map(fn($v) => (float)$v['value'], array_reverse($values))) ?>;
  var green  = <?= json_encode($green) ?>;
  var amber  = <?= json_encode($amber) ?>;
  var ctx = document.getElementById('kriChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: <?= json_encode($kri['title']) ?>,
        data: data,
        borderColor: <?= json_encode($ragColor) ?>,
        backgroundColor: <?= json_encode($ragColor . '22') ?>,
        borderWidth: 2,
        pointRadius: 4,
        pointBackgroundColor: <?= json_encode($ragColor) ?>,
        tension: 0.3,
        fill: true,
      }, {
        label: 'Green threshold',
        data: labels.map(() => green),
        borderColor: '#16a34a',
        borderWidth: 1,
        borderDash: [4, 4],
        pointRadius: 0,
        fill: false,
      }, {
        label: 'Amber threshold',
        data: labels.map(() => amber),
        borderColor: '#d97706',
        borderWidth: 1,
        borderDash: [4, 4],
        pointRadius: 0,
        fill: false,
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: false, grid: { color: '#f4f4f5' } },
        x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }
      }
    }
  });
})();
</script>
<?php endif; ?>
