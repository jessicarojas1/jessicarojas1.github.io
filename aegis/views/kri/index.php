<?php
$breadcrumbs  = $breadcrumbs  ?? [['KRI', null]];
// Flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Count by RAG
$ragCounts = ['green' => 0, 'amber' => 0, 'red' => 0, 'grey' => 0];
foreach ($kris as $k) {
    $rag = $k['rag'] ?? 'grey';
    if (isset($ragCounts[$rag])) $ragCounts[$rag]++;
}

// RAG color map
$ragColors = [
    'green' => ['#f0fdf4', 'var(--primary)', '#bbf7d0'],
    'amber' => ['#fffbeb', 'var(--warning)', '#fde68a'],
    'red'   => ['#fef2f2', 'var(--danger)', '#fecaca'],
    'grey'  => ['#f9fafb', '#71717a', '#e4e4e7'],
];

// Active filter from query string
$activeFilter = $_GET['rag'] ?? '';

// Frequency labels
$freqLabels = [
    'daily'     => 'Daily',
    'weekly'    => 'Weekly',
    'monthly'   => 'Monthly',
    'quarterly' => 'Quarterly',
];
?>

<?php if ($flashSuccess): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($flashError) ?></div>
<?php endif; ?>

<!-- Page header -->
<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-speedometer2" style="margin-right:8px;"></i>Key Risk Indicators</h1>
    <p class="page-subtitle">Monitor measurable metrics that signal changes in risk exposure</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('risk.write')): ?>
      <a href="/kris/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New KRI</a>
    <?php endif; ?>
  </div>
</div>

<!-- RAG Summary Bar -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;">
  <?php
  $chipDefs = [
    ['green', 'Green',   'bi-check-circle-fill', 'var(--primary)', '#f0fdf4', '#bbf7d0'],
    ['amber', 'Amber',   'bi-exclamation-triangle-fill', 'var(--warning)', '#fffbeb', '#fde68a'],
    ['red',   'Red',     'bi-exclamation-octagon-fill', 'var(--danger)', '#fef2f2', '#fecaca'],
    ['grey',  'No Data', 'bi-dash-circle-fill', '#71717a', '#f9fafb', '#e4e4e7'],
  ];
  foreach ($chipDefs as [$rag, $label, $icon, $color, $bg, $border]):
    $count   = $ragCounts[$rag];
    $isActive = ($activeFilter === $rag);
    $ringStyle = $isActive ? "outline:3px solid {$color};outline-offset:2px;" : '';
  ?>
  <a href="<?= $isActive ? '/kris' : '/kris?rag=' . $rag ?>" style="text-decoration:none;">
    <div class="card" style="border:1.5px solid <?= $color ?>40;cursor:pointer;transition:transform .15s;<?= $ringStyle ?>">
      <div class="card-body" style="display:flex;align-items:center;gap:16px;padding:18px 20px;">
        <div style="width:52px;height:52px;border-radius:14px;background:<?= $color ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <i class="bi <?= $icon ?>" style="font-size:24px;color:<?= $color ?>;"></i>
        </div>
        <div>
          <div style="font-size:32px;font-weight:800;line-height:1;color:<?= $color ?>;"><?= $count ?></div>
          <div style="font-size:12px;font-weight:600;color:<?= $color ?>;margin-top:2px;opacity:.85;"><?= $label ?></div>
        </div>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<?php
// Apply filter
$displayKris = $activeFilter
    ? array_filter($kris, fn($k) => ($k['rag'] ?? 'grey') === $activeFilter)
    : $kris;
?>

<?php if ($activeFilter): ?>
  <div style="margin-bottom:16px;display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-muted);">
    <i class="bi bi-funnel-fill"></i>
    Showing <strong><?= Security::h(ucfirst($activeFilter)) ?></strong> KRIs only
    <a href="/kris" style="margin-left:6px;" class="btn btn-ghost btn-sm"><i class="bi bi-x-lg"></i> Clear filter</a>
  </div>
<?php endif; ?>

<?php if (empty($displayKris)): ?>
  <!-- Empty state -->
  <div class="card" style="text-align:center;padding:60px 24px;">
    <i class="bi bi-speedometer2" style="font-size:48px;color:var(--text-light);display:block;margin-bottom:16px;"></i>
    <h3 style="font-size:18px;font-weight:600;margin-bottom:8px;color:var(--text-muted);">
      <?= $activeFilter ? 'No KRIs in this category' : 'No Key Risk Indicators yet' ?>
    </h3>
    <p style="color:var(--text-light);margin-bottom:20px;">
      <?= $activeFilter
        ? 'Try clearing the filter to see all KRIs.'
        : 'Create your first KRI to start monitoring risk exposure.' ?>
    </p>
    <?php if (!$activeFilter && Auth::can('risk.write')): ?>
      <a href="/kris/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create First KRI</a>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- KRI Cards Grid -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;">
    <?php foreach ($displayKris as $k):
      $rag = $k['rag'] ?? 'grey';
      [$ragBg, $ragColor, $ragBorder] = $ragColors[$rag];
      $latestVal  = $k['latest_value'] ?? null;
      $latestDate = $k['latest_date']  ?? null;
      $ragLabel   = ['green'=>'GREEN','amber'=>'AMBER','red'=>'RED','grey'=>'NO DATA'][$rag];
      $ragIcon    = ['green'=>'bi-check-circle-fill','amber'=>'bi-exclamation-triangle-fill','red'=>'bi-exclamation-octagon-fill','grey'=>'bi-dash-circle-fill'][$rag];
      $dirLabel   = $k['direction'] === 'higher_worse' ? 'Higher = worse risk' : 'Lower = worse risk';
      $freqLabel  = $freqLabels[$k['frequency'] ?? 'monthly'] ?? ucfirst($k['frequency'] ?? '');

      // Threshold scale calculation for the mini bar
      $green = (float)$k['threshold_green'];
      $amber = (float)$k['threshold_amber'];
      $red   = (float)$k['threshold_red'];
      $hi    = $k['direction'] === 'higher_worse' ? $red : $green;
      $lo    = $k['direction'] === 'higher_worse' ? 0    : $red;
      $range = max($hi - $lo, 0.0001);
      if ($k['direction'] === 'higher_worse') {
          $greenPct = round(($green / max($red, 0.0001)) * 100, 1);
          $amberPct = round((($amber - $green) / max($red, 0.0001)) * 100, 1);
          $redPct   = 100 - $greenPct - $amberPct;
      } else {
          $redPct   = round(($red / max($green, 0.0001)) * 100, 1);
          $amberPct = round((($amber - $red) / max($green, 0.0001)) * 100, 1);
          $greenPct = 100 - $redPct - $amberPct;
      }
      $greenPct = max(0, min(100, $greenPct));
      $amberPct = max(0, min(100, $amberPct));
      $redPct   = max(0, min(100, $redPct));
    ?>
    <div class="card" style="border-left:5px solid <?= $ragColor ?>;position:relative;overflow:hidden;">
      <!-- RAG dot (top right) -->
      <div style="position:absolute;top:16px;right:16px;">
        <div title="<?= $ragLabel ?>" style="width:20px;height:20px;border-radius:50%;background:<?= $ragColor ?>;box-shadow:0 0 0 4px <?= $ragColor ?>22;"></div>
      </div>

      <div class="card-body" style="padding:20px 20px 16px;">
        <!-- Title + badges -->
        <div style="padding-right:32px;">
          <h3 style="font-size:15px;font-weight:700;margin:0 0 6px;line-height:1.3;">
            <a href="/kris/<?= (int)$k['id'] ?>" style="color:var(--text-primary);text-decoration:none;">
              <?= Security::h($k['title']) ?>
            </a>
          </h3>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
            <span style="background:var(--bg-subtle);color:var(--text-muted);padding:2px 9px;border-radius:99px;font-size:11px;font-weight:600;border:1px solid var(--border);">
              <?= $freqLabel ?>
            </span>
            <span style="background:<?= $ragColor ?>20;color:<?= $ragColor ?>;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;border:1px solid <?= $ragColor ?>40;">
              <i class="bi <?= $ragIcon ?>"></i> <?= $ragLabel ?>
            </span>
          </div>
        </div>

        <!-- Latest value -->
        <div style="margin-bottom:16px;">
          <?php if ($latestVal !== null): ?>
            <div style="font-size:28px;font-weight:800;color:<?= $ragColor ?>;line-height:1;">
              <?= Security::h(rtrim(rtrim(number_format((float)$latestVal, 4), '0'), '.')) ?>
              <span style="font-size:14px;font-weight:500;color:var(--text-muted);margin-left:4px;"><?= Security::h($k['unit'] ?? '') ?></span>
            </div>
            <?php if ($latestDate): ?>
              <div style="font-size:11px;color:var(--text-light);margin-top:3px;">
                as of <?= Security::h(date('M j, Y', strtotime($latestDate))) ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div style="font-size:20px;font-weight:600;color:var(--text-light);">No data</div>
            <div style="font-size:11px;color:var(--text-light);margin-top:3px;">No readings recorded yet</div>
          <?php endif; ?>
        </div>

        <!-- Mini threshold bar -->
        <div style="margin-bottom:14px;">
          <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:4px;">Thresholds</div>
          <div style="height:8px;border-radius:4px;overflow:hidden;display:flex;background:var(--bg-secondary);">
            <?php if ($k['direction'] === 'higher_worse'): ?>
              <div style="width:<?= $greenPct ?>%;background:var(--primary);" title="Green ≤ <?= Security::h((string)$green) ?>"></div>
              <div style="width:<?= $amberPct ?>%;background:var(--warning);" title="Amber ≤ <?= Security::h((string)$amber) ?>"></div>
              <div style="width:<?= $redPct  ?>%;background:var(--danger);" title="Red > <?= Security::h((string)$amber) ?>"></div>
            <?php else: ?>
              <div style="width:<?= $redPct  ?>%;background:var(--danger);" title="Red < <?= Security::h((string)$red) ?>"></div>
              <div style="width:<?= $amberPct ?>%;background:var(--warning);" title="Amber ≥ <?= Security::h((string)$red) ?>"></div>
              <div style="width:<?= $greenPct ?>%;background:var(--primary);" title="Green ≥ <?= Security::h((string)$green) ?>"></div>
            <?php endif; ?>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-light);margin-top:3px;">
            <span style="color:var(--primary);">G: <?= Security::h(rtrim(rtrim(number_format($green,4),'0'),'.')) ?></span>
            <span style="color:var(--warning);">A: <?= Security::h(rtrim(rtrim(number_format($amber,4),'0'),'.')) ?></span>
            <span style="color:var(--danger);">R: <?= Security::h(rtrim(rtrim(number_format($red,4),'0'),'.')) ?></span>
          </div>
        </div>

        <!-- Direction indicator -->
        <div style="font-size:11px;color:var(--text-muted);margin-bottom:12px;display:flex;align-items:center;gap:4px;">
          <i class="bi bi-arrow-<?= $k['direction'] === 'higher_worse' ? 'up' : 'down' ?>-circle" style="color:<?= $k['direction'] === 'higher_worse' ? 'var(--danger)' : 'var(--warning)' ?>;"></i>
          <?= $dirLabel ?>
        </div>

        <!-- Owner + Linked risk -->
        <?php if (!empty($k['owner_name']) || !empty($k['risk_title'])): ?>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;display:flex;flex-direction:column;gap:3px;">
            <?php if (!empty($k['owner_name'])): ?>
              <span><i class="bi bi-person-fill" style="opacity:.6;"></i> <?= Security::h($k['owner_name']) ?></span>
            <?php endif; ?>
            <?php if (!empty($k['risk_title'])): ?>
              <span>
                <i class="bi bi-link-45deg" style="opacity:.6;"></i>
                <a href="/risk/<?= (int)$k['linked_risk_id'] ?>" style="color:var(--primary);font-size:12px;">
                  <?= Security::h($k['risk_title']) ?>
                </a>
              </span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Actions -->
        <div style="display:flex;gap:8px;">
          <a href="/kris/<?= (int)$k['id'] ?>" class="btn btn-primary btn-sm" style="flex:1;text-align:center;">
            <i class="bi bi-graph-up"></i> View / Record
          </a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
