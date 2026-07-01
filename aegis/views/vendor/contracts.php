<?php
$breadcrumbs    = $breadcrumbs    ?? [['Vendors', '/vendor'], ['Contracts', null]];
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
      <span class="ov-num" style="color:var(--success)"><?= $activeCount ?></span>
      <span>Active</span>
    </div>
    <div class="ov-stat">
      <span class="ov-num" style="color:var(--warning)"><?= $expiringCount ?></span>
      <span>Expiring &le;60 Days</span>
    </div>
    <div class="ov-stat">
      <span class="ov-num" style="color:var(--info)">
        <?= $totalValue > 0 ? '$' . number_format($totalValue, 0) : '—' ?>
      </span>
      <span>Total Value (USD equiv.)</span>
    </div>
  </div>
</div>

<?php if ($urgent): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid var(--danger);background:var(--danger-subtle)">
  <div class="card-body" style="display:flex;align-items:center;gap:12px;padding:14px 18px">
    <i class="bi bi-exclamation-triangle-fill" style="color:var(--danger);font-size:20px;flex-shrink:0"></i>
    <div>
      <strong style="color:var(--danger)">Urgent: <?= count($urgent) ?> contract<?= count($urgent) !== 1 ? 's' : '' ?> expiring within 30 days</strong>
      <p style="margin:2px 0 0;font-size:13px;color:var(--danger)">Review auto-renewal settings or begin renegotiation immediately.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($expiring): ?>
<!-- Expiring Soon -->
<div style="margin-bottom:24px">
  <h2 style="font-size:16px;font-weight:600;margin-bottom:12px;display:flex;align-items:center;gap:8px">
    <i class="bi bi-clock-history" style="color:var(--warning)"></i>
    Expiring Soon
    <span style="font-size:12px;font-weight:400;color:var(--text-muted);background:var(--bg-secondary);padding:2px 8px;border-radius:10px"><?= count($expiring) ?></span>
  </h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
    <?php foreach ($expiring as $c):
      $daysLeft = (int)ceil((strtotime($c['end_date']) - time()) / 86400);
      $urgency  = $daysLeft <= 14 ? 'var(--danger)' : ($daysLeft <= 30 ? 'var(--warning)' : 'var(--info)');
    ?>
    <div class="card" style="border-left:3px solid <?= $urgency ?>;padding:0">
      <div class="card-body" style="padding:14px 16px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= Security::h($c['title']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= Security::h($c['vendor_name']) ?></div>
          </div>
          <?php if ($c['auto_renewal']): ?>
          <span style="font-size:11px;background:var(--primary)18;color:var(--primary);padding:2px 7px;border-radius:10px;white-space:nowrap;margin-left:8px">Auto-Renews</span>
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
            <th scope="col">Vendor</th>
            <th scope="col">Contract</th>
            <th scope="col">Number</th>
            <th scope="col">Status</th>
            <th scope="col">Value</th>
            <th scope="col">Start</th>
            <th scope="col">End</th>
            <th scope="col">Auto-Renewal</th>
            <th scope="col">Owner</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contracts as $c):
            $statusMap = [
              'active'     => ['color'=>'var(--success)','bg'=>'var(--success-subtle)','label'=>'Active'],
              'draft'      => ['color'=>'var(--text-muted)','bg'=>'var(--bg-subtle)','label'=>'Draft'],
              'expired'    => ['color'=>'var(--danger)','bg'=>'var(--danger-subtle)','label'=>'Expired'],
              'terminated' => ['color'=>'var(--text-muted)','bg'=>'var(--surface-alt)','label'=>'Terminated'],
            ];
            $badge = $statusMap[$c['status']] ?? ['color'=>'var(--text-muted)','bg'=>'var(--bg-subtle)','label'=>ucfirst($c['status'])];
          ?>
          <tr>
            <td style="font-weight:500"><?= Security::h($c['vendor_name']) ?></td>
            <td><?= Security::h($c['title']) ?></td>
            <td style="font-family:monospace;font-size:12px;color:var(--text-muted)"><?= $c['contract_number'] ? Security::h($c['contract_number']) : '—' ?></td>
            <td>
              <span class="status-chip" style="background:<?= $badge['color'] ?>18;color:<?= $badge['color'] ?>;border:1px solid <?= $badge['color'] ?>40">
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
                  $renewal  = VendorController::contractRenewalStatus($c['end_date'], $c['status'], isset($c['renewal_notice_days']) ? (int)$c['renewal_notice_days'] : null);
                  $endColor = $renewal === 'expired' ? 'var(--danger)' : ($renewal === 'due' ? 'var(--warning)' : 'inherit');
                ?>
                <span style="color:<?= $endColor ?>"><?= date('M j, Y', strtotime($c['end_date'])) ?></span>
                <?php if ($renewal === 'expired'): ?>
                  <span class="badge badge-danger" style="font-size:0.68rem"><i class="bi bi-calendar-x"></i> Expired</span>
                <?php elseif ($renewal === 'due'): ?>
                  <span class="badge badge-warning" style="font-size:0.68rem"><i class="bi bi-clock-history"></i> Renewal due</span>
                <?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if ($c['auto_renewal']): ?>
                <span style="color:var(--success)"><i class="bi bi-check-circle-fill"></i></span>
              <?php else: ?>
                <span style="color:var(--border)"><i class="bi bi-dash-circle"></i></span>
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
    <div class="empty-state-sm">
      <i class="bi bi-file-earmark-text" style="font-size:40px"></i>
      <p style="font-size:15px;margin:0">No contracts found.</p>
      <p style="font-size:13px;margin:8px 0 0">Add contracts from the vendor detail page.</p>
    </div>
    <?php endif; ?>
  </div>
</div>
