<?php
$totalContracts = count($contracts);
$activeCount    = count(array_filter($contracts, fn($c) => $c['status'] === 'active'));
$expiringCount  = count($expiring);
$totalValue     = array_sum(array_filter(array_column($contracts, 'value'), fn($v) => $v !== null));

// Warning: any expiring within 30 days
$urgent = array_filter($expiring, function($c) {
    return $c['end_date'] && (strtotime($c['end_date']) - time()) <= 30 * 86400;
});
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Vendor Contracts</h1>
    <p class="page-subtitle">Track contract terms, expiry dates, and auto-renewal alerts across all vendors.</p>
  </div>
  <div class="page-actions">
    <a href="/vendor" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> All Vendors</a>
  </div>
</div>

<!-- Stats row -->
<div class="overview-bar" style="margin-bottom:20px">
  <div class="overview-stats" style="display:flex;gap:32px;flex-wrap:wrap;padding:16px 20px">
    <div class="ov-stat">
      <span class="ov-num" style="color:var(--primary)"><?= $totalContracts ?></span>
      <span>Total Contracts</span>
    </div>
    <div class="ov-stat">
      <span class="ov-num" style="color:#059669"><?= $activeCount ?></span>
      <span>Active</span>
    </div>
    <div class="ov-stat">
      <span class="ov-num" style="color:#d97706"><?= $expiringCount ?></span>
      <span>Expiring &le;60 Days</span>
    </div>
    <div class="ov-stat">
      <span class="ov-num" style="color:#0284c7">
        <?= $totalValue > 0 ? '$' . number_format($totalValue, 0) : '—' ?>
      </span>
      <span>Total Value (USD equiv.)</span>
    </div>
  </div>
</div>

<?php if ($urgent): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid #dc2626;background:var(--danger-subtle)">
  <div class="card-body" style="display:flex;align-items:center;gap:12px;padding:14px 18px">
    <i class="bi bi-exclamation-triangle-fill" style="color:#dc2626;font-size:20px;flex-shrink:0"></i>
    <div>
      <strong style="color:#991b1b">Urgent: <?= count($urgent) ?> contract<?= count($urgent) !== 1 ? 's' : '' ?> expiring within 30 days</strong>
      <p style="margin:2px 0 0;font-size:13px;color:var(--danger)">Review auto-renewal settings or begin renegotiation immediately.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($expiring): ?>
<!-- Expiring Soon -->
<div style="margin-bottom:24px">
  <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;display:flex;align-items:center;gap:8px">
    <i class="bi bi-clock-history" style="color:#d97706"></i>
    Expiring Soon
    <span style="font-size:12px;font-weight:400;color:var(--text-muted);background:#f4f4f5;padding:2px 8px;border-radius:10px"><?= count($expiring) ?></span>
  </h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
    <?php foreach ($expiring as $c):
      $daysLeft = (int)ceil((strtotime($c['end_date']) - time()) / 86400);
      $urgency  = $daysLeft <= 14 ? '#dc2626' : ($daysLeft <= 30 ? '#d97706' : '#0284c7');
    ?>
    <div class="card" style="border-left:3px solid <?= $urgency ?>;padding:0">
      <div class="card-body" style="padding:14px 16px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= Security::h($c['title']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= Security::h($c['vendor_name']) ?></div>
          </div>
          <?php if ($c['auto_renewal']): ?>
          <span style="font-size:11px;background:#dcfce7;color:#15803d;padding:2px 7px;border-radius:10px;white-space:nowrap;margin-left:8px">Auto-Renews</span>
          <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;font-size:12px">
          <span style="color:<?= $urgency ?>;font-weight:600">
            <i class="bi bi-calendar-x"></i>
            <?= $daysLeft > 0 ? $daysLeft . ' day' . ($daysLeft !== 1 ? 's' : '') . ' left' : 'Expiring today' ?>
          </span>
          <?php if ($c['value'] !== null): ?>
          <span style="color:var(--text-muted)"><?= Security::h($c['currency']) ?> <?= number_format((float)$c['value'], 0) ?></span>
          <?php endif; ?>
        </div>
        <div style="margin-top:10px">
          <a href="/vendor/<?= (int)$c['vendor_id'] ?>" class="btn btn-ghost btn-sm" style="font-size:12px;padding:4px 10px">
            <i class="bi bi-box-arrow-up-right"></i> View Vendor
          </a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- All Contracts Table -->
<div class="card">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-file-earmark-text" style="color:var(--primary)"></i>
      <span class="card-title">All Contracts</span>
    </div>
  </div>
  <div class="card-body" style="padding:0">
    <?php if ($contracts): ?>
    <div style="overflow-x:auto">
      <table class="data-table" style="min-width:900px">
        <thead>
          <tr>
            <th>Vendor</th>
            <th>Contract</th>
            <th>Number</th>
            <th>Status</th>
            <th>Value</th>
            <th>Start</th>
            <th>End</th>
            <th>Auto-Renewal</th>
            <th>Owner</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contracts as $c):
            $statusMap = [
              'active'     => ['color'=>'#059669','bg'=>'#dcfce7','label'=>'Active'],
              'draft'      => ['color'=>'#71717a','bg'=>'#f4f4f5','label'=>'Draft'],
              'expired'    => ['color'=>'#dc2626','bg'=>'#fee2e2','label'=>'Expired'],
              'terminated' => ['color'=>'#a1a1aa','bg'=>'#f9fafb','label'=>'Terminated'],
            ];
            $badge = $statusMap[$c['status']] ?? ['color'=>'#71717a','bg'=>'#f4f4f5','label'=>ucfirst($c['status'])];
          ?>
          <tr>
            <td style="font-weight:500"><?= Security::h($c['vendor_name']) ?></td>
            <td><?= Security::h($c['title']) ?></td>
            <td style="font-family:monospace;font-size:12px;color:var(--text-muted)"><?= $c['contract_number'] ? Security::h($c['contract_number']) : '—' ?></td>
            <td>
              <span class="status-chip" style="background:<?= $badge['bg'] ?>;color:<?= $badge['color'] ?>;border:1px solid <?= $badge['color'] ?>30">
                <?= $badge['label'] ?>
              </span>
            </td>
            <td style="font-size:13px">
              <?php if ($c['value'] !== null): ?>
                <?= Security::h($c['currency']) ?> <?= number_format((float)$c['value'], 2) ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="font-size:13px;white-space:nowrap"><?= $c['start_date'] ? date('M j, Y', strtotime($c['start_date'])) : '—' ?></td>
            <td style="font-size:13px;white-space:nowrap">
              <?php if ($c['end_date']): ?>
                <?php
                  $dLeft = (int)ceil((strtotime($c['end_date']) - time()) / 86400);
                  $endColor = ($c['status'] === 'active' && $dLeft <= 30) ? '#dc2626' : (($c['status'] === 'active' && $dLeft <= 60) ? '#d97706' : 'inherit');
                ?>
                <span style="color:<?= $endColor ?>"><?= date('M j, Y', strtotime($c['end_date'])) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if ($c['auto_renewal']): ?>
                <span style="color:#059669"><i class="bi bi-check-circle-fill"></i></span>
              <?php else: ?>
                <span style="color:#d1d5db"><i class="bi bi-dash-circle"></i></span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px"><?= $c['owner_name'] ? Security::h($c['owner_name']) : '—' ?></td>
            <td>
              <a href="/vendor/<?= (int)$c['vendor_id'] ?>" class="btn btn-ghost btn-sm">
                <i class="bi bi-eye"></i> View
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:48px 20px;color:#a1a1aa">
      <i class="bi bi-file-earmark-text" style="font-size:40px;display:block;margin-bottom:12px"></i>
      <p style="font-size:15px;margin:0">No contracts found.</p>
      <p style="font-size:13px;margin:8px 0 0">Add contracts from the vendor detail page.</p>
    </div>
    <?php endif; ?>
  </div>
</div>
