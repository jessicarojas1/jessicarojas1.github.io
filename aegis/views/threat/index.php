<?php
$breadcrumbs = $breadcrumbs ?? [['Threat Register', null]];
// $threats, $stats, $filter, $statusF provided by ThreatController::index()

// Category display config
$catConfig = [
    'people'     => ['label' => 'People',     'color' => 'var(--secondary)', 'bg' => 'rgba(55,65,81,.05)', 'icon' => 'bi-person-fill'],
    'process'    => ['label' => 'Process',    'color' => 'var(--moderate)', 'bg' => 'var(--info-subtle)', 'icon' => 'bi-diagram-3-fill'],
    'technology' => ['label' => 'Technology', 'color' => 'var(--primary)', 'bg' => 'rgba(11,97,4,.06)', 'icon' => 'bi-cpu-fill'],
    'natural'    => ['label' => 'Natural',    'color' => 'var(--primary)', 'bg' => 'var(--success-subtle)', 'icon' => 'bi-cloud-lightning-rain-fill'],
    'regulatory' => ['label' => 'Regulatory', 'color' => '#ea580c', 'bg' => '#fff7ed', 'icon' => 'bi-file-earmark-ruled-fill'],
    'financial'  => ['label' => 'Financial',  'color' => '#ca8a04', 'bg' => '#fefce8', 'icon' => 'bi-currency-dollar'],
];

$statusConfig = [
    'active'    => ['label' => 'Active',    'color' => 'var(--primary)', 'bg' => 'var(--success-subtle)'],
    'mitigated' => ['label' => 'Mitigated', 'color' => 'var(--moderate)', 'bg' => 'var(--info-subtle)'],
    'accepted'  => ['label' => 'Accepted',  'color' => 'var(--warning)', 'bg' => 'var(--warning-subtle)'],
    'retired'   => ['label' => 'Retired',   'color' => '#71717a', 'bg' => '#f9fafb'],
];

// Index stats by category
$statsByCategory = [];
foreach ($stats as $s) {
    $statsByCategory[$s['category']] = $s;
}

function threatScoreColor(int $score): string {
    if ($score <= 4)  return 'var(--primary)';
    if ($score <= 9)  return 'var(--warning)';
    if ($score <= 16) return '#ea580c';
    return 'var(--danger)';
}
function threatScoreBg(int $score): string {
    if ($score <= 4)  return 'var(--success-subtle)';
    if ($score <= 9)  return 'var(--warning-subtle)';
    if ($score <= 16) return '#fff7ed';
    return 'var(--danger-subtle)';
}
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-shield-exclamation" style="margin-right:8px;color:var(--primary);"></i>Threat Register</h1>
    <p class="page-subtitle">Catalog of threat sources linked to organizational risks</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('threat.create')): ?>
      <a href="/threats/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Threat</a>
    <?php endif; ?>
  </div>
</div>

<!-- Category summary bar -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:24px;">
  <?php foreach ($catConfig as $cat => $cfg):
    $s    = $statsByCategory[$cat] ?? null;
    $cnt  = $s ? (int)$s['cnt'] : 0;
    $avg  = $s ? round((float)$s['avg_score'], 1) : 0;
    $isActive = ($filter === $cat);
  ?>
    <a href="<?= $isActive ? '/threats' : '/threats?category=' . $cat . ($statusF ? '&status=' . Security::h($statusF) : '') ?>"
       style="text-decoration:none;">
      <div style="background:<?= $isActive ? $cfg['color'] : 'var(--card-bg)' ?>;color:<?= $isActive ? '#fff' : $cfg['color'] ?>;border:2px solid <?= $cfg['color'] ?>40;border-radius:12px;padding:14px 12px;text-align:center;transition:all .15s;<?= $isActive ? 'box-shadow:0 4px 12px ' . $cfg['color'] . '33;' : '' ?>">
        <i class="bi <?= $cfg['icon'] ?>" style="font-size:20px;display:block;margin-bottom:4px;"></i>
        <div style="font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:4px;"><?= $cfg['label'] ?></div>
        <div style="font-size:22px;font-weight:800;line-height:1;"><?= $cnt ?></div>
        <?php if ($avg > 0): ?>
          <div style="font-size:10px;opacity:.75;margin-top:2px;">Avg score: <?= $avg ?></div>
        <?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php
$_threatActiveFilters = (int)!empty($filter) + (int)!empty($statusF);
?>
<div class="filter-toolbar" style="margin-bottom:20px">
  <form method="GET" action="/threats">
    <div class="filter-popover-wrap">
      <button type="button" class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#threatFilterPopover">
        <i class="bi bi-funnel-fill"></i> Filters
        <?php if ($_threatActiveFilters > 0): ?>
          <span class="filter-active-count"><?= $_threatActiveFilters ?></span>
        <?php endif; ?>
      </button>
      <div id="threatFilterPopover" class="filter-popover">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category" class="form-control form-control-sm">
            <option value="">All Categories</option>
            <?php foreach ($catConfig as $val => $cfg): ?>
              <option value="<?= $val ?>" <?= ($filter === $val) ? 'selected' : '' ?>><?= $cfg['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control form-control-sm">
            <option value="">All Statuses</option>
            <?php foreach ($statusConfig as $val => $cfg): ?>
              <option value="<?= $val ?>" <?= ($statusF === $val) ? 'selected' : '' ?>><?= $cfg['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-popover-footer">
          <a href="/threats" class="btn btn-ghost btn-sm">Clear</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check"></i> Apply</button>
        </div>
      </div>
    </div>
  </form>
  <span style="font-size:13px;color:var(--text-muted);"><?= count($threats) ?> threat<?= count($threats) !== 1 ? 's' : '' ?></span>
</div>

<!-- Threat table -->
<div class="card data-table-wrap">
  <div class="card-body p0">
    <table class="table data-table" style="min-width:960px;">
      <thead>
        <tr>
          <th scope="col">Title</th>
          <th scope="col">Category</th>
          <th scope="col">Status</th>
          <th scope="col" style="text-align:center;">Likelihood</th>
          <th scope="col" style="text-align:center;">Impact</th>
          <th scope="col" style="text-align:center;">Score</th>
          <th scope="col" style="text-align:center;">Linked Risks</th>
          <th scope="col">Owner</th>
          <th scope="col" style="width:70px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($threats): foreach ($threats as $t):
          $cat     = $t['category'] ?? 'technology';
          $catCfg  = $catConfig[$cat]    ?? ['label' => ucfirst($cat), 'color' => '#71717a', 'bg' => '#f4f4f5', 'icon' => 'bi-question'];
          $stCfg   = $statusConfig[$t['status'] ?? 'active'] ?? ['label' => ucfirst($t['status'] ?? ''), 'color' => '#71717a', 'bg' => '#f9fafb'];
          $score   = (int)($t['likelihood'] ?? 0) * (int)($t['impact'] ?? 0);
          $sColor  = threatScoreColor($score);
          $sBg     = threatScoreBg($score);
        ?>
          <tr>
            <td>
              <a href="/threats/<?= (int)$t['id'] ?>" class="table-link fw-500">
                <?= Security::h($t['title']) ?>
              </a>
              <?php if (!empty($t['source'])): ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                  <i class="bi bi-link-45deg"></i> <?= Security::h($t['source']) ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $catCfg['bg'] ?>;color:<?= $catCfg['color'] ?>;border:1px solid <?= $catCfg['color'] ?>33;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:600;">
                <i class="bi <?= $catCfg['icon'] ?>"></i> <?= $catCfg['label'] ?>
              </span>
            </td>
            <td>
              <span style="background:<?= $stCfg['bg'] ?>;color:<?= $stCfg['color'] ?>;border:1px solid <?= $stCfg['color'] ?>33;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:600;">
                <?= $stCfg['label'] ?>
              </span>
            </td>
            <td style="text-align:center;">
              <span style="font-size:13px;font-weight:600;color:var(--text-secondary);"><?= (int)($t['likelihood'] ?? 0) ?></span>
            </td>
            <td style="text-align:center;">
              <span style="font-size:13px;font-weight:600;color:var(--text-secondary);"><?= (int)($t['impact'] ?? 0) ?></span>
            </td>
            <td style="text-align:center;">
              <?php if ($score > 0): ?>
                <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:<?= $sBg ?>;color:<?= $sColor ?>;font-weight:800;font-size:14px;border:2px solid <?= $sColor ?>33;">
                  <?= $score ?>
                </span>
              <?php else: ?>
                <span style="color:var(--text-light);">—</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center;">
              <?php $lr = (int)($t['linked_risks'] ?? 0); ?>
              <?php if ($lr > 0): ?>
                <span style="display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;border-radius:99px;background:rgba(79,70,229,.1);color:var(--primary);font-weight:700;font-size:13px;padding:0 6px;">
                  <?= $lr ?>
                </span>
              <?php else: ?>
                <span style="color:var(--text-light);">0</span>
              <?php endif; ?>
            </td>
            <td>
              <span style="font-size:13px;"><?= Security::h($t['owner_name'] ?? '—') ?></span>
            </td>
            <td>
              <div class="action-btns">
                <a href="/threats/<?= (int)$t['id'] ?>" class="btn btn-ghost btn-sm" title="View threat">
                  <i class="bi bi-eye"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="9" class="empty-row">
              <div class="empty-state-sm">
                <i class="bi bi-shield-exclamation" style="font-size:36px;color:var(--text-light);"></i>
                <p style="margin-top:8px;">No threats found.
                  <?php if (Auth::can('threat.create')): ?>
                    <a href="/threats/create">Add the first threat</a>.
                  <?php endif; ?>
                </p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
