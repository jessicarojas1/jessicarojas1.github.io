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
  <div class="page-actions" id="pageActions">
    <?php if ($packages && Auth::can('compliance.assess')): ?>
    <!-- Delete selected (hidden until checkboxes selected) -->
    <form method="POST" action="/compliance/delete-selected" id="deleteSelectedForm"
          data-confirm="Delete selected package(s) and all their controls? This cannot be undone.">
      <?= Security::csrfField() ?>
      <div id="selectedHiddenInputs"></div>
      <button type="submit" class="btn btn-danger" id="deleteSelectedBtn" style="display:none">
        <i class="bi bi-trash3-fill"></i> <span id="deleteSelectedLabel">Clear 0 packages</span>
      </button>
    </form>
    <!-- Clear all -->
    <form method="POST" action="/compliance/clear-all" data-confirm="Delete ALL compliance packages and their controls? This cannot be undone.">
      <?= Security::csrfField() ?>
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash3-fill"></i> Clear All</button>
    </form>
    <?php endif; ?>
    <a href="/compliance/import" class="btn btn-ghost"><i class="bi bi-cloud-upload"></i> Import</a>
    <a href="/compliance/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create Package</a>
  </div>
</div>

<!-- Package cards -->
<form id="pkgSelectForm">
<div class="package-grid">
<?php foreach ($packages as $pkg):
  $total     = max(1, (int)$pkg['control_count']);
  $compliant = (int)$pkg['compliant_count'];
  $partial   = (int)$pkg['partial_count'];
  $pct       = round(($compliant / $total) * 100);
  $color     = $pct >= 80 ? 'var(--success)' : ($pct >= 50 ? 'var(--warning)' : 'var(--danger)');
?>
  <div class="package-card" id="pkgcard-<?= $pkg['id'] ?>">
    <?php if (Auth::can('compliance.assess')): ?>
    <label class="pkg-select-label">
      <input type="checkbox" class="pkg-checkbox" value="<?= $pkg['id'] ?>"
             data-change="updateSelection" aria-label="Select <?= Security::h($pkg['name']) ?>">
      <span class="pkg-select-indicator"><i class="bi bi-check-lg"></i></span>
    </label>
    <?php endif; ?>

    <div class="package-card-header">
      <div class="package-badge" style="background:<?= categoryColor($pkg['standard_category']) ?>20;border-color:<?= categoryColor($pkg['standard_category']) ?>40">
        <i class="bi bi-<?= categoryIcon($pkg['standard_category']) ?>" style="color:<?= categoryColor($pkg['standard_category']) ?>"></i>
      </div>
      <div class="package-meta">
        <div class="package-code"><?= Security::h($pkg['standard_code']) ?></div>
        <div class="package-version">v<?= Security::h($pkg['version'] ?? '1.0') ?></div>
      </div>
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
</form>

<style nonce="<?= Security::nonce() ?>">
.package-card { position: relative; }
.pkg-select-label {
  position: absolute; top: 12px; right: 12px; z-index: 2;
  cursor: pointer; display: flex; align-items: center;
}
.pkg-select-label input[type=checkbox] { display: none; }
.pkg-select-indicator {
  width: 22px; height: 22px; border-radius: 6px;
  border: 2px solid var(--border);
  background: var(--surface);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; color: transparent;
  transition: all .15s;
}
.pkg-select-label input:checked + .pkg-select-indicator {
  background: var(--danger); border-color: var(--danger); color: #fff;
}
.package-card.selected {
  outline: 2px solid var(--danger);
  outline-offset: 2px;
}
</style>

<script nonce="<?= Security::nonce() ?>">
function updateSelection() {
  const checked = document.querySelectorAll('.pkg-checkbox:checked');
  const btn = document.getElementById('deleteSelectedBtn');
  const inputsDiv = document.getElementById('selectedHiddenInputs');

  const n = checked.length;
  document.getElementById('deleteSelectedLabel').textContent =
    'Clear ' + n + (n === 1 ? ' package' : ' packages');
  btn.style.display = n > 0 ? '' : 'none';

  inputsDiv.innerHTML = '';
  checked.forEach(cb => {
    const card = document.getElementById('pkgcard-' + cb.value);
    if (card) card.classList.add('selected');
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'package_ids[]'; inp.value = cb.value;
    inputsDiv.appendChild(inp);
  });

  // Remove selected class from unchecked
  document.querySelectorAll('.pkg-checkbox:not(:checked)').forEach(cb => {
    const card = document.getElementById('pkgcard-' + cb.value);
    if (card) card.classList.remove('selected');
  });
}
</script>

<?php
function categoryColor(string $cat): string {
  return match(strtolower($cat)) {
    'cybersecurity' => 'var(--danger)',
    'information security' => 'var(--primary)',
    'ai governance' => 'var(--secondary)',
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
