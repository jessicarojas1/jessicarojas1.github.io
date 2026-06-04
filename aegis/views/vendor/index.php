<?php
$pageTitle    = 'Vendor Risk Management';
$activeModule = 'vendor';
$breadcrumbs  = [['Vendor Risk Management', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Vendor Risk Management</h1>
    <p class="page-subtitle">Track, assess, and manage third-party vendor risks</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('vendor.write')): ?>
      <a href="/vendor/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Vendor</a>
    <?php endif; ?>
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
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon" style="background:#eff6ff;color:#2563eb"><i class="bi bi-buildings"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['total'] ?? 0) ?></div>
      <div class="stat-label">Total Vendors</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#f0fdf4;color:#059669"><i class="bi bi-check-circle-fill"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['active_count'] ?? 0) ?></div>
      <div class="stat-label">Active Vendors</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#fef2f2;color:#dc2626"><i class="bi bi-exclamation-octagon-fill"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['critical_count'] ?? 0) ?></div>
      <div class="stat-label">Critical Tier</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#fff7ed;color:#d97706"><i class="bi bi-database-fill-lock"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['data_access_count'] ?? 0) ?></div>
      <div class="stat-label">Data Access</div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:1;min-width:180px">
        <label class="form-label" style="font-size:12px;margin-bottom:4px">Risk Tier</label>
        <select name="risk_tier" id="filterRiskTier" class="form-control">
          <option value="">All Tiers</option>
          <?php foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= ($_GET['risk_tier'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:180px">
        <label class="form-label" style="font-size:12px;margin-bottom:4px">Status</label>
        <select name="status" id="filterStatus" class="form-control">
          <option value="">All Statuses</option>
          <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'under_review' => 'Under Review', 'terminated' => 'Terminated'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= ($_GET['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:2;min-width:220px">
        <label class="form-label" style="font-size:12px;margin-bottom:4px">Search</label>
        <input type="text" name="search" class="form-control" placeholder="Search name, code, category, contact..." value="<?= Security::h($_GET['search'] ?? '') ?>">
      </div>
      <div style="display:flex;gap:8px;padding-bottom:1px">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <a href="/vendor" class="btn btn-secondary btn-sm">Clear</a>
      </div>
    </form>
  </div>
</div>

<!-- Vendor Table -->
<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Code</th>
          <th>Vendor Name</th>
          <th>Category</th>
          <th>Risk Tier</th>
          <th>Status</th>
          <th>Data Access</th>
          <th>Critical Svc</th>
          <th>Contract End</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($vendors): foreach ($vendors as $v):
          $tierColors = ['critical' => '#dc2626', 'high' => '#d97706', 'medium' => '#0284c7', 'low' => '#059669'];
          $tierColor  = $tierColors[$v['risk_tier']] ?? '#6b7280';

          $statusColors = [
            'active'       => '#059669',
            'inactive'     => '#6b7280',
            'under_review' => '#d97706',
            'terminated'   => '#dc2626',
          ];
          $statusColor = $statusColors[$v['status']] ?? '#6b7280';
          $statusLabel = match($v['status']) {
            'active'       => 'Active',
            'inactive'     => 'Inactive',
            'under_review' => 'Under Review',
            'terminated'   => 'Terminated',
            default        => ucfirst($v['status']),
          };

          $contractEndHighlight = '';
          $contractEndDisplay   = '—';
          if ($v['contract_end']) {
              $daysLeft = (int)ceil((strtotime($v['contract_end']) - time()) / 86400);
              $contractEndDisplay = date('M j, Y', strtotime($v['contract_end']));
              if ($daysLeft <= 30 && $daysLeft >= 0) {
                  $contractEndHighlight = 'color:#d97706;font-weight:600';
              } elseif ($daysLeft < 0) {
                  $contractEndHighlight = 'color:#dc2626;font-weight:600';
              }
          }
        ?>
          <tr>
            <td><span style="font-family:monospace;font-size:13px"><?= Security::h($v['vendor_code']) ?></span></td>
            <td>
              <a href="/vendor/<?= (int)$v['id'] ?>" style="font-weight:500;color:inherit;text-decoration:none">
                <?= Security::h($v['name']) ?>
              </a>
            </td>
            <td><?= $v['category'] ? Security::h($v['category']) : '<span style="color:#9ca3af">—</span>' ?></td>
            <td>
              <span class="status-chip" style="background:<?= $tierColor ?>20;color:<?= $tierColor ?>;border:1px solid <?= $tierColor ?>40;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap">
                <?= ucfirst(Security::h($v['risk_tier'])) ?>
              </span>
            </td>
            <td>
              <span class="status-chip" style="background:<?= $statusColor ?>20;color:<?= $statusColor ?>;border:1px solid <?= $statusColor ?>40;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:500;white-space:nowrap">
                <?= $statusLabel ?>
              </span>
            </td>
            <td>
              <?php if ($v['data_access']): ?>
                <span style="color:#d97706;font-weight:600;font-size:13px"><i class="bi bi-check-circle-fill"></i> Yes</span>
              <?php else: ?>
                <span style="color:#9ca3af;font-size:13px">No</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($v['critical_service']): ?>
                <span style="color:#dc2626;font-weight:600;font-size:13px"><i class="bi bi-exclamation-triangle-fill"></i> Yes</span>
              <?php else: ?>
                <span style="color:#9ca3af;font-size:13px">No</span>
              <?php endif; ?>
            </td>
            <td>
              <span style="<?= $contractEndHighlight ?>;font-size:13px">
                <?= $contractEndDisplay ?>
                <?php if ($v['contract_end'] && isset($daysLeft) && $daysLeft <= 30 && $daysLeft >= 0): ?>
                  <small style="display:block;font-size:11px;font-weight:400"><?= $daysLeft ?>d left</small>
                <?php elseif ($v['contract_end'] && isset($daysLeft) && $daysLeft < 0): ?>
                  <small style="display:block;font-size:11px;font-weight:400">Expired</small>
                <?php endif; ?>
              </span>
            </td>
            <td style="white-space:nowrap">
              <a href="/vendor/<?= (int)$v['id'] ?>" class="btn btn-secondary btn-sm" title="View">
                <i class="bi bi-eye"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="9" style="text-align:center;padding:48px 24px;color:#6b7280">
              <div style="display:flex;flex-direction:column;align-items:center;gap:8px">
                <i class="bi bi-buildings" style="font-size:32px;color:#d1d5db"></i>
                <p style="margin:0;font-size:15px">No vendors found.</p>
                <?php if (Auth::can('vendor.write')): ?>
                  <a href="/vendor/create" class="btn btn-primary btn-sm" style="margin-top:8px"><i class="bi bi-plus-lg"></i> Add Vendor</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
(function() {
  var filterRiskTier = document.getElementById('filterRiskTier');
  if (filterRiskTier) { filterRiskTier.addEventListener('change', function() { this.form.submit(); }); }
  var filterStatus = document.getElementById('filterStatus');
  if (filterStatus) { filterStatus.addEventListener('change', function() { this.form.submit(); }); }
})();
</script>
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
