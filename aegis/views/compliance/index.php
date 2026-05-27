<?php
$pageTitle    = 'Compliance Packages';
$activeModule = 'compliance';
$breadcrumbs  = [['Compliance', '/compliance'], ['Packages', null]];

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Compliance Packages</h1>
    <p class="page-subtitle">Manage your standards and compliance frameworks</p>
  </div>
  <div class="page-actions">
    <?php if ($packages): ?>
    <form method="POST" action="/compliance/clear-all" onsubmit="return confirm('Delete ALL compliance packages and their controls? This cannot be undone.')">
      <?= Security::csrfField() ?>
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash3-fill"></i> Clear All</button>
    </form>
    <?php endif; ?>
    <a href="/compliance/import" class="btn btn-ghost"><i class="bi bi-cloud-upload"></i> Import</a>
    <a href="/compliance/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create Package</a>
  </div>
</div>

<!-- Package cards -->
<div class="package-grid">
<?php foreach ($packages as $pkg):
  $total     = max(1, (int)$pkg['control_count']);
  $compliant = (int)$pkg['compliant_count'];
  $partial   = (int)$pkg['partial_count'];
  $pct       = round(($compliant / $total) * 100);
  $color     = $pct >= 80 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626');
?>
  <div class="package-card">
    <div class="package-card-header">
      <div class="package-badge" style="background:<?= categoryColor($pkg['standard_category']) ?>20;border-color:<?= categoryColor($pkg['standard_category']) ?>40">
        <i class="bi bi-<?= categoryIcon($pkg['standard_category']) ?>" style="color:<?= categoryColor($pkg['standard_category']) ?>"></i>
      </div>
      <div class="package-meta">
        <div class="package-code"><?= Security::h($pkg['standard_code']) ?></div>
        <div class="package-version">v<?= Security::h($pkg['version'] ?? '1.0') ?></div>
      </div>
      <?php if ($pkg['is_paid']): ?>
        <span class="badge badge-gold"><i class="bi bi-star-fill"></i> Premium</span>
      <?php endif; ?>
    </div>

    <h3 class="package-name"><?= Security::h($pkg['name']) ?></h3>
    <p class="package-std"><?= Security::h($pkg['standard_name']) ?></p>

    <div class="package-progress">
      <div class="package-progress-header">
        <span>Compliance</span>
        <strong style="color:<?= $color ?>"><?= $pct ?>%</strong>
      </div>
      <div class="progress-track">
        <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
      </div>
      <div class="package-progress-sub">
        <span class="dot-stat green"><?= $compliant ?> Compliant</span>
        <span class="dot-stat yellow"><?= $partial ?> Partial</span>
        <span class="dot-stat red"><?= $pkg['non_compliant_count'] ?> Non-compliant</span>
      </div>
    </div>

    <div class="package-stats">
      <div class="package-stat">
        <i class="bi bi-list-check"></i>
        <span><?= $pkg['control_count'] ?> controls</span>
      </div>
      <div class="package-stat">
        <i class="bi bi-calendar3"></i>
        <span><?= date('M Y', strtotime($pkg['imported_at'])) ?></span>
      </div>
    </div>

    <div class="package-actions">
      <a href="/compliance/<?= $pkg['id'] ?>" class="btn btn-primary btn-sm btn-full">
        <i class="bi bi-arrow-right-circle-fill"></i> View Package
      </a>
    </div>
  </div>
<?php endforeach; ?>

<?php if (!$packages): ?>
  <div class="empty-state card col-span-3">
    <div class="empty-icon"><i class="bi bi-shield-x"></i></div>
    <h3>No compliance packages</h3>
    <p>Create a custom package and add controls manually, or import an existing standard.</p>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <a href="/compliance/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create Package</a>
      <a href="/compliance/import" class="btn btn-ghost"><i class="bi bi-cloud-upload"></i> Import Standard</a>
    </div>
  </div>
<?php endif; ?>
</div>

<?php
function categoryColor(string $cat): string {
  return match(strtolower($cat)) {
    'cybersecurity' => '#ef4444',
    'information security' => '#4f46e5',
    'ai governance' => '#7c3aed',
    default => '#0284c7'
  };
}
function categoryIcon(string $cat): string {
  return match(strtolower($cat)) {
    'cybersecurity' => 'shield-lock-fill',
    'information security' => 'lock-fill',
    'ai governance' => 'robot',
    default => 'award-fill'
  };
}
?>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
