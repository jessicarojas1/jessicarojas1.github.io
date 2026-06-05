<?php
$breadcrumbs = [['Compliance', '/compliance'], ['Testing Dashboard', null]];

function ctResultBadge(string $result): string {
    return match($result) {
        'pass'       => '<span class="badge" style="background:var(--primary-tint);color:var(--primary);border:1px solid var(--primary-ring)">Pass</span>',
        'fail'       => '<span class="badge" style="background:var(--danger-tint);color:var(--danger);border:1px solid var(--danger-ring)">Fail</span>',
        'partial'    => '<span class="badge" style="background:var(--warning-tint);color:var(--warning);border:1px solid var(--warning-ring)">Partial</span>',
        'not_tested' => '<span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);border:1px solid var(--border)">Not Tested</span>',
        default      => '<span class="badge">' . htmlspecialchars($result, ENT_QUOTES, 'UTF-8') . '</span>',
    };
}

// Build summary keyed by result
$summaryMap = [];
foreach ($summary as $s) {
    $summaryMap[$s['result']] = (int)$s['cnt'];
}
$cntPass      = $summaryMap['pass'] ?? 0;
$cntFail      = $summaryMap['fail'] ?? 0;
$cntPartial   = $summaryMap['partial'] ?? 0;
$cntNotTested = $summaryMap['not_tested'] ?? 0;
$cntTotal     = $cntPass + $cntFail + $cntPartial + $cntNotTested;
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Control Testing Dashboard</h1>
    <p class="page-subtitle">Overview of control effectiveness testing across all compliance packages</p>
  </div>
  <div class="page-actions">
    <a href="/compliance" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Compliance</a>
  </div>
</div>

<!-- Stat chips -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
  <div style="flex:1;min-width:120px;background:var(--primary-tint);border:1px solid var(--primary-ring);border-radius:12px;padding:16px 20px;text-align:center">
    <div style="font-size:32px;font-weight:700;color:var(--primary);line-height:1"><?= $cntPass ?></div>
    <div style="font-size:13px;color:var(--primary);margin-top:4px;font-weight:600">Pass</div>
  </div>
  <div style="flex:1;min-width:120px;background:var(--danger-subtle);border:1px solid rgba(220,38,38,.5);border-radius:12px;padding:16px 20px;text-align:center">
    <div style="font-size:32px;font-weight:700;color:var(--danger);line-height:1"><?= $cntFail ?></div>
    <div style="font-size:13px;color:var(--danger);margin-top:4px;font-weight:600">Fail</div>
  </div>
  <div style="flex:1;min-width:120px;background:var(--warning-subtle);border:1px solid #fcd34d80;border-radius:12px;padding:16px 20px;text-align:center">
    <div style="font-size:32px;font-weight:700;color:var(--warning);line-height:1"><?= $cntPartial ?></div>
    <div style="font-size:13px;color:var(--warning);margin-top:4px;font-weight:600">Partial</div>
  </div>
  <div style="flex:1;min-width:120px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:12px;padding:16px 20px;text-align:center">
    <div style="font-size:32px;font-weight:700;color:var(--text-muted);line-height:1"><?= $cntNotTested ?></div>
    <div style="font-size:13px;color:var(--text-muted);margin-top:4px;font-weight:600">Not Tested</div>
  </div>
  <?php if ($cntTotal > 0): ?>
  <div style="flex:1;min-width:140px;background:var(--info-subtle);border:1px solid rgba(37,99,235,.5);border-radius:12px;padding:16px 20px;text-align:center">
    <div style="font-size:32px;font-weight:700;color:var(--moderate);line-height:1"><?= $cntTotal > 0 ? round($cntPass / $cntTotal * 100) : 0 ?>%</div>
    <div style="font-size:13px;color:var(--moderate);margin-top:4px;font-weight:600">Pass Rate</div>
  </div>
  <?php endif; ?>
</div>

<!-- Overdue tests -->
<?php if (!empty($overdue)): ?>
<div class="card" style="margin-bottom:20px;border-left:4px solid var(--danger)">
  <div class="card-header" style="background:var(--danger-subtle)">
    <div class="card-header-left">
      <i class="bi bi-exclamation-triangle-fill" style="color:var(--danger)"></i>
      <span class="card-title" style="color:var(--danger)">Overdue Tests (<?= count($overdue) ?>)</span>
    </div>
  </div>
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Control Code</th>
          <th>Title</th>
          <th>Package</th>
          <th>Last Result</th>
          <th>Next Test Was Due</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($overdue as $od): ?>
        <tr>
          <td><span style="font-family:monospace;font-weight:700;color:var(--danger)"><?= Security::h($od['code']) ?></span></td>
          <td><?= Security::h($od['title']) ?></td>
          <td><?= Security::h($od['package_name']) ?></td>
          <td><?= ctResultBadge($od['result']) ?></td>
          <td style="color:var(--danger);font-weight:600">
            <i class="bi bi-calendar-x"></i> <?= Security::h($od['next_test_date']) ?>
          </td>
          <td>
            <a href="/compliance/control/<?= (int)$od['objective_id'] ?>/test" class="btn btn-primary btn-sm">
              <i class="bi bi-clipboard2-plus"></i> Test Now
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Recent tests -->
<div class="card">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-clock-history" style="color:var(--primary)"></i>
      <span class="card-title">Recent Tests</span>
      <span style="background:var(--border);color:var(--text-muted);border-radius:12px;padding:2px 10px;font-size:12px;font-weight:600"><?= count($recent) ?></span>
    </div>
  </div>
  <?php if ($recent): ?>
  <div class="card-body p0">
    <div style="overflow-x:auto">
      <table class="table" style="min-width:900px">
        <thead>
          <tr>
            <th>Code</th>
            <th>Control Title</th>
            <th>Package</th>
            <th>Date</th>
            <th>Tester</th>
            <th>Result</th>
            <th style="width:160px">Effectiveness</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $r): ?>
          <tr>
            <td>
              <a href="/compliance/control/<?= (int)$r['objective_id'] ?>/test"
                 style="font-family:monospace;font-weight:700;color:var(--primary);text-decoration:none">
                <?= Security::h($r['code']) ?>
              </a>
            </td>
            <td style="max-width:200px">
              <span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= Security::h($r['title']) ?>">
                <?= Security::h($r['title']) ?>
              </span>
            </td>
            <td style="white-space:nowrap"><?= Security::h($r['package_name']) ?></td>
            <td style="white-space:nowrap"><?= Security::h($r['test_date']) ?></td>
            <td><?= Security::h($r['tester_name'] ?? '—') ?></td>
            <td><?= ctResultBadge($r['result']) ?></td>
            <td>
              <?php if ($r['effectiveness'] !== null): ?>
              <div style="display:flex;align-items:center;gap:8px">
                <div style="flex:1;height:8px;background:var(--border);border-radius:4px;overflow:hidden">
                  <div style="width:<?= (int)$r['effectiveness'] ?>%;height:100%;background:<?= $r['effectiveness'] >= 75 ? 'var(--primary)' : ($r['effectiveness'] >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;border-radius:4px"></div>
                </div>
                <span style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap"><?= (int)$r['effectiveness'] ?>%</span>
              </div>
              <?php else: ?>
              <span style="color:var(--text-muted);font-size:13px">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="/compliance/control/<?= (int)$r['objective_id'] ?>/test" class="btn btn-ghost btn-sm" title="View / Test">
                <i class="bi bi-arrow-right"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
  <div class="card-body">
    <div style="text-align:center;padding:32px;color:var(--text-muted)">
      <i class="bi bi-clipboard2-x" style="font-size:36px;display:block;margin-bottom:12px"></i>
      <p style="margin:0;font-size:15px">No control tests have been recorded yet.</p>
      <p style="margin:8px 0 0;font-size:13px">Navigate to a compliance package and click <strong>Test</strong> on a control to get started.</p>
    </div>
  </div>
  <?php endif; ?>
</div>
