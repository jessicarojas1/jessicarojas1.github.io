<?php ob_start(); ?>

<?php if (!empty($_GET['deleted'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Asset deleted.</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Asset Inventory</h1>
    <p class="page-subtitle">Manage and track all organizational assets</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('risk.write')): ?>
      <a href="/assets/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Asset</a>
    <?php endif; ?>
  </div>
</div>

<!-- KPI stat cards -->
<div class="kpi-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
  <div class="card kpi-card">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;">
      <div style="width:48px;height:48px;border-radius:12px;background:rgba(79,70,229,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-hdd-stack-fill" style="font-size:22px;color:var(--primary);"></i>
      </div>
      <div>
        <div style="font-size:28px;font-weight:700;line-height:1;"><?= (int)($summary['total'] ?? 0) ?></div>
        <div style="font-size:12px;color:var(--text-muted);font-weight:500;margin-top:2px;">Total Assets</div>
      </div>
    </div>
  </div>
  <div class="card kpi-card">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;">
      <div style="width:48px;height:48px;border-radius:12px;background:rgba(239,68,68,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-exclamation-octagon-fill" style="font-size:22px;color:#ef4444;"></i>
      </div>
      <div>
        <div style="font-size:28px;font-weight:700;line-height:1;color:#ef4444;"><?= (int)($summary['critical'] ?? 0) ?></div>
        <div style="font-size:12px;color:var(--text-muted);font-weight:500;margin-top:2px;">Critical Assets</div>
      </div>
    </div>
  </div>
  <div class="card kpi-card">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;">
      <div style="width:48px;height:48px;border-radius:12px;background:rgba(249,115,22,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-exclamation-triangle-fill" style="font-size:22px;color:#f97316;"></i>
      </div>
      <div>
        <div style="font-size:28px;font-weight:700;line-height:1;color:#f97316;"><?= (int)($summary['high'] ?? 0) ?></div>
        <div style="font-size:12px;color:var(--text-muted);font-weight:500;margin-top:2px;">High Criticality</div>
      </div>
    </div>
  </div>
  <div class="card kpi-card">
    <div class="card-body" style="display:flex;align-items:center;gap:16px;">
      <div style="width:48px;height:48px;border-radius:12px;background:rgba(100,116,139,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-slash-circle-fill" style="font-size:22px;color:var(--text-muted);"></i>
      </div>
      <div>
        <div style="font-size:28px;font-weight:700;line-height:1;color:var(--text-muted);"><?= (int)($summary['decommissioned'] ?? 0) ?></div>
        <div style="font-size:12px;color:var(--text-muted);font-weight:500;margin-top:2px;">Decommissioned</div>
      </div>
    </div>
  </div>
</div>

<?php
$typeLabels = [
    'server'      => 'Server',
    'workstation' => 'Workstation',
    'application' => 'Application',
    'database'    => 'Database',
    'network'     => 'Network Device',
    'cloud'       => 'Cloud Resource',
    'mobile'      => 'Mobile Device',
    'iot'         => 'IoT Device',
    'saas'        => 'SaaS',
];
$_assetActiveFilters = (int)!empty($_GET['type']) + (int)!empty($_GET['criticality']) + (int)!empty($_GET['status']);
?>
<div class="filter-toolbar">
  <form method="GET" action="/assets">
    <div class="filter-popover-wrap">
      <button type="button" class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#assetFilterPopover">
        <i class="bi bi-funnel-fill"></i> Filters
        <?php if ($_assetActiveFilters > 0): ?>
          <span class="filter-active-count"><?= $_assetActiveFilters ?></span>
        <?php endif; ?>
      </button>
      <div id="assetFilterPopover" class="filter-popover">
        <div class="form-group">
          <label class="form-label">Asset Type</label>
          <select name="type" class="form-control form-control-sm">
            <option value="">All Types</option>
            <?php foreach ($typeLabels as $val => $label):
              $sel = (($_GET['type'] ?? '') === $val) ? 'selected' : ''; ?>
              <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Criticality</label>
          <select name="criticality" class="form-control form-control-sm">
            <option value="">All Criticality</option>
            <?php foreach (['critical'=>'Critical','high'=>'High','medium'=>'Medium','low'=>'Low'] as $val => $label): ?>
              <option value="<?= $val ?>" <?= (($_GET['criticality'] ?? '') === $val) ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control form-control-sm">
            <option value="">All Statuses</option>
            <?php foreach (['active'=>'Active','decommissioned'=>'Decommissioned','maintenance'=>'Maintenance'] as $val => $label): ?>
              <option value="<?= $val ?>" <?= (($_GET['status'] ?? '') === $val) ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-popover-footer">
          <a href="/assets" class="btn btn-ghost btn-sm">Clear</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check"></i> Apply</button>
        </div>
      </div>
    </div>
  </form>
  <span style="font-size:13px;color:var(--text-muted);"><?= count($assets) ?> asset<?= count($assets) !== 1 ? 's' : '' ?></span>
</div>

<?php
// Helper: type icon
function assetTypeIcon(string $type): string {
    $icons = [
        'server'      => 'bi-server',
        'workstation' => 'bi-laptop',
        'application' => 'bi-window',
        'database'    => 'bi-database',
        'network'     => 'bi-diagram-3',
        'cloud'       => 'bi-cloud-fill',
        'mobile'      => 'bi-phone',
        'iot'         => 'bi-cpu',
        'saas'        => 'bi-box-arrow-up-right',
    ];
    return $icons[$type] ?? 'bi-hdd';
}

// Helper: criticality badge style
function criticalityBadge(string $crit): string {
    $map = [
        'critical' => ['#fef2f2','var(--danger)','Critical'],
        'high'     => ['#fff7ed','#ea580c','High'],
        'medium'   => ['#fffbeb','var(--warning)','Medium'],
        'low'      => ['#f0fdf4','var(--primary)','Low'],
    ];
    [$bg, $color, $label] = $map[$crit] ?? ['#f4f4f5','#71717a',ucfirst($crit)];
    return "<span style=\"background:{$bg};color:{$color};border:1px solid {$color}33;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:600;\">{$label}</span>";
}
?>

<!-- Asset table -->
<div class="card data-table-wrap">
  <div class="card-body p0">
    <table class="table data-table" style="min-width:900px;">
      <thead>
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>Criticality</th>
          <th>Classification</th>
          <th>Status</th>
          <th>Owner</th>
          <th>Last Scanned</th>
          <th style="width:80px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($assets): foreach ($assets as $a):
          $statusColors = [
            'active'         => ['#f0fdf4','var(--primary)'],
            'decommissioned' => ['#f9fafb','#71717a'],
            'maintenance'    => ['#fffbeb','var(--warning)'],
          ];
          [$sBg, $sColor] = $statusColors[$a['status'] ?? ''] ?? ['#f4f4f5','#71717a'];
        ?>
          <tr>
            <td>
              <a href="/assets/<?= (int)$a['id'] ?>" class="table-link fw-500">
                <?= Security::h($a['name']) ?>
              </a>
              <?php if (!empty($a['hostname'])): ?>
                <div style="font-size:11px;color:var(--text-muted);"><?= Security::h($a['hostname']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span style="display:inline-flex;align-items:center;gap:6px;">
                <i class="bi <?= assetTypeIcon($a['asset_type'] ?? '') ?>" style="color:var(--text-muted);"></i>
                <?= Security::h($typeLabels[$a['asset_type'] ?? ''] ?? ucfirst($a['asset_type'] ?? '')) ?>
              </span>
            </td>
            <td><?= criticalityBadge($a['criticality'] ?? 'low') ?></td>
            <td>
              <?php if (!empty($a['classification'])): ?>
                <span class="badge"><?= Security::h($a['classification']) ?></span>
              <?php else: ?>
                <span style="color:var(--text-light);">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span style="background:<?= $sBg ?>;color:<?= $sColor ?>;border:1px solid <?= $sColor ?>33;padding:2px 10px;border-radius:99px;font-size:11px;font-weight:600;">
                <?= ucfirst(Security::h($a['status'] ?? '')) ?>
              </span>
            </td>
            <td><?= Security::h($a['owner_name'] ?? '—') ?></td>
            <td>
              <?php if (!empty($a['last_scanned'])): ?>
                <span style="font-size:12px;"><?= Security::h(date('M j, Y', strtotime($a['last_scanned']))) ?></span>
              <?php else: ?>
                <span style="color:var(--text-light);">Never</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="action-btns">
                <a href="/assets/<?= (int)$a['id'] ?>" class="btn btn-ghost btn-sm" title="View asset">
                  <i class="bi bi-eye"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="8" class="empty-row">
              <div class="empty-state-sm">
                <i class="bi bi-hdd-stack"></i>
                <p>No assets found. <?php if (Auth::can('risk.write')): ?><a href="/assets/create">Add your first asset</a>.<?php endif; ?></p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$content      = ob_get_clean();
$pageTitle    = 'Asset Inventory';
$activeModule = 'assets';
$breadcrumbs  = [['Asset Inventory', null]];
require AEGIS_ROOT . '/views/layout.php';
?>
