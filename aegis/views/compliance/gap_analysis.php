<?php
// $packages, $gaps, $crossFramework provided by controller
$totalGaps = count($gaps);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Compliance Gap Analysis</h1>
    <p class="page-subtitle">Cross-framework view of unimplemented and overdue controls across all active compliance packages.</p>
  </div>
  <div class="page-actions">
    <a href="/compliance" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Compliance</a>
  </div>
</div>

<!-- ═══════════════════════════════════════════
     SECTION 1 — Per-Package Scorecard
════════════════════════════════════════════ -->
<h2 style="font-size:16px;font-weight:600;margin:0 0 14px;display:flex;align-items:center;gap:8px">
  <i class="bi bi-grid-3x3-gap" style="color:var(--primary)"></i>
  Package Scorecards
</h2>

<?php if ($packages): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:32px">
  <?php foreach ($packages as $pkg):
    $total       = max((int)$pkg['total_controls'], 1);
    $implemented = (int)$pkg['implemented'];
    $inProgress  = (int)$pkg['in_progress'];
    $notStarted  = (int)$pkg['not_started'];
    $overdue     = (int)$pkg['overdue'];
    $pct         = round($implemented / $total * 100);
    $pctColor    = $pct >= 80 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626');
  ?>
  <div class="card" style="padding:0">
    <div class="card-body" style="padding:18px">
      <!-- Header row -->
      <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:14px">
        <span style="font-size:11px;font-weight:700;background:#ede9fe;color:#5b21b6;padding:3px 8px;border-radius:6px;white-space:nowrap;margin-top:2px">
          <?= Security::h($pkg['standard_code']) ?>
        </span>
        <div style="min-width:0">
          <div style="font-weight:600;font-size:14px;line-height:1.3"><?= Security::h($pkg['name']) ?></div>
          <div style="font-size:12px;color:#64748b;margin-top:2px"><?= Security::h($pkg['standard_name']) ?></div>
        </div>
      </div>

      <!-- Progress bar -->
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <div style="flex:1;background:#e2e8f0;border-radius:4px;height:8px;overflow:hidden">
          <div style="width:<?= $pct ?>%;background:<?= $pctColor ?>;height:100%;border-radius:4px;transition:width .4s"></div>
        </div>
        <span style="font-size:13px;font-weight:700;color:<?= $pctColor ?>;min-width:38px;text-align:right"><?= $pct ?>%</span>
      </div>

      <!-- Stat chips -->
      <div style="display:flex;flex-wrap:wrap;gap:8px;font-size:12px">
        <span style="background:#dcfce7;color:#15803d;padding:3px 9px;border-radius:10px;font-weight:500">
          <i class="bi bi-check-circle-fill"></i> <?= $implemented ?> Implemented
        </span>
        <span style="background:#dbeafe;color:#1d4ed8;padding:3px 9px;border-radius:10px;font-weight:500">
          <i class="bi bi-arrow-repeat"></i> <?= $inProgress ?> In Progress
        </span>
        <span style="background:#f1f5f9;color:#64748b;padding:3px 9px;border-radius:10px;font-weight:500">
          <i class="bi bi-circle"></i> <?= $notStarted ?> Not Started
        </span>
        <?php if ($overdue > 0): ?>
        <span style="background:#fee2e2;color:#dc2626;padding:3px 9px;border-radius:10px;font-weight:500">
          <i class="bi bi-exclamation-triangle-fill"></i> <?= $overdue ?> Overdue
        </span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:32px">
  <div class="card-body" style="text-align:center;padding:40px;color:#94a3b8">
    <i class="bi bi-clipboard2-x" style="font-size:36px;display:block;margin-bottom:10px"></i>
    <p style="margin:0">No active compliance packages found. <a href="/compliance/import">Import a framework</a> to get started.</p>
  </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════
     SECTION 2 — Top Gaps Table
════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
  <h2 style="font-size:16px;font-weight:600;margin:0;display:flex;align-items:center;gap:8px">
    <i class="bi bi-exclamation-triangle" style="color:#d97706"></i>
    Control Gaps Requiring Attention
  </h2>
  <span style="font-size:13px;color:#64748b;background:#f1f5f9;padding:4px 12px;border-radius:12px">
    <?= $totalGaps ?> gap<?= $totalGaps !== 1 ? 's' : '' ?><?= $totalGaps >= 100 ? ' (showing top 100)' : '' ?>
  </span>
</div>

<div class="card" style="margin-bottom:32px">
  <div class="card-body" style="padding:0">
    <?php if ($gaps): ?>
    <div style="overflow-x:auto">
      <table class="data-table" style="min-width:860px">
        <thead>
          <tr>
            <th>Standard</th>
            <th>Control Code</th>
            <th>Control Title</th>
            <th>Package</th>
            <th>Status</th>
            <th>Due Date</th>
            <th>Assigned To</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($gaps as $gap):
            $isOverdue = $gap['due_date'] && strtotime($gap['due_date']) < time()
                         && $gap['status'] !== 'implemented';
            if ($isOverdue) {
              $statusLabel = 'Overdue';
              $statusBg    = '#fee2e2';
              $statusColor = '#dc2626';
            } else {
              $statusLabel = $gap['status'] ? ucwords(str_replace('_',' ',$gap['status'])) : 'Not Started';
              $statusBg    = '#f1f5f9';
              $statusColor = '#64748b';
            }
            $dueDateColor = ($gap['due_date'] && strtotime($gap['due_date']) < time()) ? '#dc2626' : 'inherit';
          ?>
          <tr>
            <td>
              <span style="font-size:11px;font-weight:700;background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:5px">
                <?= Security::h($gap['standard_code']) ?>
              </span>
            </td>
            <td style="font-family:monospace;font-size:12px;white-space:nowrap"><?= Security::h($gap['code'] ?? '') ?></td>
            <td style="font-size:13px;max-width:260px">
              <span title="<?= Security::h($gap['description'] ?? '') ?>"><?= Security::h($gap['title']) ?></span>
            </td>
            <td style="font-size:13px"><?= Security::h($gap['package_name']) ?></td>
            <td>
              <span class="status-chip" style="background:<?= $statusBg ?>;color:<?= $statusColor ?>">
                <?= Security::h($statusLabel) ?>
              </span>
            </td>
            <td style="font-size:13px;white-space:nowrap;color:<?= $dueDateColor ?>">
              <?= $gap['due_date'] ? date('M j, Y', strtotime($gap['due_date'])) : '—' ?>
            </td>
            <td style="font-size:13px"><?= $gap['assigned_name'] ? Security::h($gap['assigned_name']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:48px 20px;color:#94a3b8">
      <i class="bi bi-patch-check" style="font-size:40px;display:block;margin-bottom:12px;color:#059669"></i>
      <p style="font-size:15px;margin:0;color:#059669;font-weight:600">No control gaps found!</p>
      <p style="font-size:13px;margin:8px 0 0">All active controls are implemented or in progress.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════
     SECTION 3 — Cross-Framework Gaps
════════════════════════════════════════════ -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
  <div>
    <h2 style="font-size:16px;font-weight:600;margin:0 0 4px;display:flex;align-items:center;gap:8px">
      <i class="bi bi-diagram-3" style="color:#dc2626"></i>
      Controls Failing Across Multiple Frameworks
    </h2>
    <p style="font-size:13px;color:#64748b;margin:0">
      These control gaps affect multiple compliance programs simultaneously — remediate these first for the broadest impact.
    </p>
  </div>
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
            <th style="text-align:center">Frameworks Count</th>
            <th style="text-align:center">Implemented In</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($crossFramework as $cf):
            $cfFrameworks  = explode(', ', $cf['frameworks'] ?? '');
            $cfCount       = (int)$cf['framework_count'];
            $cfImplemented = (int)$cf['implemented_in'];
            $priority      = $cfCount >= 3 ? '#dc2626' : ($cfCount === 2 ? '#d97706' : '#64748b');
          ?>
          <tr>
            <td style="font-size:13px;max-width:300px;font-weight:500"><?= Security::h($cf['title']) ?></td>
            <td>
              <div style="display:flex;flex-wrap:wrap;gap:5px">
                <?php foreach ($cfFrameworks as $fw): ?>
                <span style="font-size:11px;font-weight:700;background:#ede9fe;color:#5b21b6;padding:2px 7px;border-radius:5px">
                  <?= Security::h(trim($fw)) ?>
                </span>
                <?php endforeach; ?>
              </div>
            </td>
            <td style="text-align:center">
              <span style="font-size:15px;font-weight:700;color:<?= $priority ?>"><?= $cfCount ?></span>
            </td>
            <td style="text-align:center;font-size:13px;color:#64748b">
              <?= $cfImplemented ?> / <?= $cfCount ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:40px 20px;color:#94a3b8">
      <i class="bi bi-diagram-3" style="font-size:36px;display:block;margin-bottom:10px"></i>
      <p style="margin:0;font-size:14px">No cross-framework gaps found. Either only one framework is active, or all shared controls are implemented.</p>
    </div>
    <?php endif; ?>
  </div>
</div>
