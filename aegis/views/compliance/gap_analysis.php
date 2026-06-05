<?php
$breadcrumbs  = $breadcrumbs  ?? [['Compliance', '/compliance'], ['Gap Analysis', null]];
// $packages, $gaps, $crossFramework provided by controller

// Server-side filter application
$filterFramework = $_GET['framework'] ?? '';
$filterGapStatus = $_GET['gap_status'] ?? '';

$displayedGaps = $gaps;
if ($filterFramework !== '') {
    $displayedGaps = array_values(array_filter($displayedGaps, fn($g) => ($g['standard_code'] ?? '') === $filterFramework));
}
if ($filterGapStatus !== '') {
    $displayedGaps = array_values(array_filter($displayedGaps, function($g) use ($filterGapStatus) {
        $isOverdue = $g['due_date'] && strtotime($g['due_date']) < time() && ($g['status'] ?? '') !== 'implemented';
        $gapStatus = $isOverdue ? 'overdue' : (($g['status'] ?? '') ?: 'not_started');
        return $gapStatus === $filterGapStatus;
    }));
}

$totalGaps    = count($gaps);
$overdueGaps  = count(array_filter($gaps, fn($g) => $g['due_date'] && strtotime($g['due_date']) < time() && ($g['status'] ?? '') !== 'implemented'));
$notStarted   = count(array_filter($gaps, fn($g) => !$g['status'] || $g['status'] === 'not_started'));
$inProgress   = count(array_filter($gaps, fn($g) => ($g['status'] ?? '') === 'in_progress'));
$pkgsWithGaps = count(array_unique(array_column($gaps, 'package_name')));
$shownGaps    = count($displayedGaps);
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-exclamation-triangle-fill" style="color:var(--warning);margin-right:8px"></i>Compliance Gap Analysis</h1>
    <p class="page-subtitle">Cross-framework view of unimplemented and overdue controls.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#gapFilters"><i class="bi bi-funnel-fill"></i> Filters</button>
    <button class="btn btn-ghost btn-sm" id="btnExportGapsCsv"><i class="bi bi-download"></i> Export CSV</button>
    <a href="/compliance" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Compliance</a>
  </div>
</div>

<!-- KPI Summary -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px">
  <?php
  $kpis = [
    ['Total Gaps',      $totalGaps,    'bi-exclamation-circle', 'var(--warning)', 'rgba(217,119,6,.1)'],
    ['Overdue',         $overdueGaps,  'bi-clock-fill',         'var(--danger)', 'rgba(220,38,38,.1)'],
    ['Not Started',     $notStarted,   'bi-circle',             '#6b7280', 'rgba(107,114,128,.1)'],
    ['In Progress',     $inProgress,   'bi-arrow-repeat',       'var(--moderate)', 'rgba(37,99,235,.1)'],
    ['Packages Affected',$pkgsWithGaps,'bi-grid-3x3-gap',       '#7c3aed', 'rgba(124,58,237,.1)'],
  ];
  foreach ($kpis as [$label, $val, $icon, $color, $bg]):
  ?>
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px">
      <div style="width:44px;height:44px;border-radius:10px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi <?= $icon ?>" style="font-size:20px;color:<?= $color ?>"></i>
      </div>
      <div>
        <div style="font-size:22px;font-weight:800;color:<?= $color ?>;line-height:1"><?= $val ?></div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:3px;font-weight:500"><?= $label ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Package Scorecards -->
<?php if ($packages): ?>
<h2 style="font-size:15px;font-weight:700;margin:0 0 12px;display:flex;align-items:center;gap:8px;color:var(--text)">
  <i class="bi bi-grid-3x3-gap" style="color:var(--primary)"></i> Package Scorecards
</h2>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:28px">
  <?php foreach ($packages as $pkg):
    $total       = max((int)$pkg['total_controls'], 1);
    $implemented = (int)$pkg['implemented'];
    $inProg      = (int)$pkg['in_progress'];
    $notStr      = (int)$pkg['not_started'];
    $overdue     = (int)$pkg['overdue'];
    $pct         = round($implemented / $total * 100);
    $pctColor    = $pct >= 80 ? 'var(--success)' : ($pct >= 50 ? 'var(--warning)' : 'var(--danger)');
  ?>
  <div class="card" style="padding:0">
    <div class="card-body" style="padding:16px">
      <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px">
        <span style="font-size:11px;font-weight:700;background:rgba(91,33,182,.12);color:var(--purple);padding:3px 8px;border-radius:6px;white-space:nowrap;margin-top:2px;flex-shrink:0">
          <?= Security::h($pkg['standard_code']) ?>
        </span>
        <div style="min-width:0">
          <div style="font-weight:600;font-size:13px;line-height:1.3"><?= Security::h($pkg['name']) ?></div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= Security::h($pkg['standard_name']) ?></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <div style="flex:1;background:var(--border);border-radius:4px;height:7px;overflow:hidden">
          <div style="width:<?= $pct ?>%;background:<?= $pctColor ?>;height:100%;border-radius:4px;transition:width .4s"></div>
        </div>
        <span style="font-size:13px;font-weight:700;color:<?= $pctColor ?>;min-width:36px;text-align:right"><?= $pct ?>%</span>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;font-size:11px">
        <span style="background:rgba(5,150,105,.12);color:var(--success);padding:2px 8px;border-radius:10px;font-weight:600">
          <i class="bi bi-check-circle-fill"></i> <?= $implemented ?> Done
        </span>
        <span style="background:rgba(37,99,235,.12);color:var(--moderate);padding:2px 8px;border-radius:10px;font-weight:600">
          <i class="bi bi-arrow-repeat"></i> <?= $inProg ?> In Progress
        </span>
        <span style="background:rgba(107,114,128,.1);color:var(--text-muted);padding:2px 8px;border-radius:10px;font-weight:600">
          <i class="bi bi-circle"></i> <?= $notStr ?> Not Started
        </span>
        <?php if ($overdue > 0): ?>
        <span style="background:rgba(220,38,38,.12);color:var(--danger);padding:2px 8px;border-radius:10px;font-weight:600">
          <i class="bi bi-exclamation-triangle-fill"></i> <?= $overdue ?> Overdue
        </span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:28px">
  <div class="card-body" style="text-align:center;padding:40px;color:var(--text-muted)">
    <i class="bi bi-clipboard2-x" style="font-size:36px;display:block;margin-bottom:10px"></i>
    <p style="margin:0">No active compliance packages found. <a href="/compliance/import">Import a framework</a> to get started.</p>
  </div>
</div>
<?php endif; ?>

<!-- Gaps Table with filter bar -->
<h2 style="font-size:15px;font-weight:700;margin:0 0 12px;display:flex;align-items:center;gap:8px;color:var(--text)">
  <i class="bi bi-exclamation-triangle" style="color:var(--warning)"></i>
  Control Gaps
  <span style="font-size:12px;font-weight:500;color:var(--text-muted);background:var(--bg-secondary);padding:2px 10px;border-radius:10px;border:1px solid var(--border)">
    <?= $shownGaps ?> gap<?= $shownGaps !== 1 ? 's' : '' ?>
  </span>
</h2>

<div class="filter-bar" id="gapFilters">
  <form method="GET">
    <select name="framework" class="form-control form-control-sm" style="width:auto;min-width:140px">
      <option value="">All Frameworks</option>
      <?php $uniqueStds = array_unique(array_column($gaps, 'standard_code'));
            sort($uniqueStds);
            foreach ($uniqueStds as $std): ?>
        <option value="<?= Security::h($std) ?>" <?= ($_GET['framework'] ?? '') === $std ? 'selected' : '' ?>><?= Security::h($std) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="gap_status" class="form-control form-control-sm" style="width:auto;min-width:130px">
      <option value="">All Statuses</option>
      <option value="overdue" <?= ($_GET['gap_status'] ?? '') === 'overdue' ? 'selected' : '' ?>>Overdue</option>
      <option value="in_progress" <?= ($_GET['gap_status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
      <option value="not_started" <?= ($_GET['gap_status'] ?? '') === 'not_started' ? 'selected' : '' ?>>Not Started</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <a href="/compliance/gap-analysis" class="btn btn-ghost btn-sm">Clear</a>
  </form>
</div>

<div class="card" style="margin-bottom:28px">
  <div class="card-body" style="padding:0">
    <?php if ($displayedGaps): ?>
    <div style="overflow-x:auto">
      <table class="data-table" style="min-width:820px" id="gapsTable">
        <thead>
          <tr>
            <th>Standard</th>
            <th>Code</th>
            <th>Control Title</th>
            <th>Package</th>
            <th>Status</th>
            <th>Due Date</th>
            <th>Assigned To</th>
            <th style="width:60px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($displayedGaps as $gap):
            $isOverdue = $gap['due_date'] && strtotime($gap['due_date']) < time()
                         && ($gap['status'] ?? '') !== 'implemented';
            if ($isOverdue) {
              $statusLabel = 'Overdue';
              $statusBg    = 'rgba(220,38,38,.12)';
              $statusColor = 'var(--danger)';
            } elseif (($gap['status'] ?? '') === 'in_progress') {
              $statusLabel = 'In Progress';
              $statusBg    = 'rgba(37,99,235,.12)';
              $statusColor = 'var(--moderate)';
            } else {
              $statusLabel = $gap['status'] ? ucwords(str_replace('_',' ',$gap['status'])) : 'Not Started';
              $statusBg    = 'rgba(107,114,128,.1)';
              $statusColor = 'var(--text-muted)';
            }
            $dueDateColor = ($gap['due_date'] && strtotime($gap['due_date']) < time()) ? 'var(--danger)' : 'var(--text)';
          ?>
          <tr>
            <td>
              <span style="font-size:11px;font-weight:700;background:rgba(91,33,182,.12);color:var(--purple);padding:2px 7px;border-radius:5px">
                <?= Security::h($gap['standard_code']) ?>
              </span>
            </td>
            <td style="font-family:monospace;font-size:12px;white-space:nowrap;color:var(--text-secondary)"><?= Security::h($gap['code'] ?? '') ?></td>
            <td style="font-size:13px">
              <span title="<?= Security::h($gap['title']) ?>" style="display:block;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= Security::h($gap['title']) ?>
              </span>
            </td>
            <td style="font-size:12px;color:var(--text-secondary)"><?= Security::h($gap['package_name']) ?></td>
            <td>
              <span style="display:inline-flex;align-items:center;gap:4px;background:<?= $statusBg ?>;color:<?= $statusColor ?>;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap">
                <?= Security::h($statusLabel) ?>
              </span>
            </td>
            <td style="font-size:13px;white-space:nowrap;color:<?= $dueDateColor ?>;font-weight:<?= $isOverdue ? '700' : '400' ?>">
              <?= $gap['due_date'] ? date('M j, Y', strtotime($gap['due_date'])) : '—' ?>
            </td>
            <td style="font-size:13px;color:var(--text-secondary)"><?= $gap['assigned_name'] ? Security::h($gap['assigned_name']) : '—' ?></td>
            <td>
              <?php if (!empty($gap['package_id']) && !empty($gap['objective_id'])): ?>
                <a href="/compliance/<?= (int)$gap['package_id'] ?>/objective/<?= (int)$gap['objective_id'] ?>"
                   class="btn btn-ghost btn-sm" title="View control">
                  <i class="bi bi-arrow-right-circle"></i>
                </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:48px 20px;color:var(--text-muted)">
      <i class="bi bi-patch-check" style="font-size:40px;display:block;margin-bottom:12px;color:var(--success)"></i>
      <p style="font-size:15px;margin:0;color:var(--success);font-weight:600">No control gaps found!</p>
      <p style="font-size:13px;margin:8px 0 0">All active controls are implemented or in progress.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Cross-Framework Gaps -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
  <h2 style="font-size:15px;font-weight:700;margin:0;display:flex;align-items:center;gap:8px;color:var(--text)">
    <i class="bi bi-diagram-3" style="color:var(--danger)"></i>
    Controls Failing Across Multiple Frameworks
  </h2>
  <p style="font-size:12px;color:var(--text-muted);margin:0">Remediate these first for the broadest compliance impact.</p>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <?php if ($crossFramework): ?>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Control Title</th>
            <th>Frameworks Affected</th>
            <th style="text-align:center">Count</th>
            <th style="text-align:center">Implemented In</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($crossFramework as $cf):
            $cfFrameworks  = explode(', ', $cf['frameworks'] ?? '');
            $cfCount       = (int)$cf['framework_count'];
            $cfImplemented = (int)$cf['implemented_in'];
            $priority      = $cfCount >= 3 ? 'var(--danger)' : ($cfCount === 2 ? 'var(--warning)' : 'var(--text-secondary)');
          ?>
          <tr>
            <td style="font-size:13px;font-weight:500">
              <span title="<?= Security::h($cf['title']) ?>" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px">
                <?= Security::h($cf['title']) ?>
              </span>
            </td>
            <td>
              <div style="display:flex;flex-wrap:wrap;gap:5px">
                <?php foreach ($cfFrameworks as $fw): ?>
                <span style="font-size:11px;font-weight:700;background:rgba(91,33,182,.12);color:var(--purple);padding:2px 7px;border-radius:5px">
                  <?= Security::h(trim($fw)) ?>
                </span>
                <?php endforeach; ?>
              </div>
            </td>
            <td style="text-align:center">
              <span style="font-size:16px;font-weight:800;color:<?= $priority ?>"><?= $cfCount ?></span>
            </td>
            <td style="text-align:center;font-size:13px;color:var(--text-secondary)">
              <?= $cfImplemented ?> / <?= $cfCount ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:40px 20px;color:var(--text-muted)">
      <i class="bi bi-diagram-3" style="font-size:36px;display:block;margin-bottom:10px"></i>
      <p style="margin:0;font-size:14px">No cross-framework gaps found.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
// CSV export
document.getElementById('btnExportGapsCsv').addEventListener('click', function() {
  var rows = document.querySelectorAll('#gapsTable tbody tr');
  var csv  = ['Standard,Code,Title,Package,Status,Due Date,Assigned To'];
  rows.forEach(function(row) {
    if (row.style.display === 'none') return;
    var cells = row.querySelectorAll('td');
    var vals  = [];
    [0,1,2,3,4,5,6].forEach(function(i) {
      var txt = cells[i] ? cells[i].textContent.replace(/\s+/g,' ').trim() : '';
      vals.push('"' + txt.replace(/"/g,'""') + '"');
    });
    csv.push(vals.join(','));
  });
  var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
  var a    = document.createElement('a');
  a.href   = URL.createObjectURL(blob);
  a.download = 'compliance_gaps_<?= date('Y-m-d') ?>.csv';
  a.click();
});
</script>
