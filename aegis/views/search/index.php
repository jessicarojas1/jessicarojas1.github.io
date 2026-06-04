<?php
/**
 * views/search/index.php — Global search results page.
 *
 * Variables provided by SearchController::index():
 *   $q          string  — sanitised query
 *   $results    array   — keyed by type, each value is array of result rows
 *   $totalHits  int     — total result count across all types
 *   $tooShort   bool    — true when strlen($q) < 2
 */

// Type metadata: icon class, badge CSS class
$typeMeta = [
    'risk'     => ['icon' => 'bi-exclamation-triangle-fill', 'badge' => 'badge-danger',    'label' => 'Risks'],
    'policy'   => ['icon' => 'bi-file-earmark-text-fill',    'badge' => 'badge-blue',      'label' => 'Policies'],
    'audit'    => ['icon' => 'bi-clipboard2-check-fill',     'badge' => 'badge-indigo',    'label' => 'Audits'],
    'incident' => ['icon' => 'bi-fire',                      'badge' => 'badge-warning',   'label' => 'Incidents'],
    'vendor'   => ['icon' => 'bi-building',                  'badge' => 'badge-secondary', 'label' => 'Vendors'],
    'control'  => ['icon' => 'bi-shield-check',              'badge' => 'badge-success',   'label' => 'Controls'],
    'asset'    => ['icon' => 'bi-server',                    'badge' => 'badge-gray',      'label' => 'Assets'],
];

// "View all" base URLs per type
$typeListUrl = [
    'risk'     => '/risk',
    'policy'   => '/policy',
    'audit'    => '/audit',
    'incident' => '/incident',
    'vendor'   => '/vendor',
    'control'  => '/compliance',
    'asset'    => '/assets',
];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Search Results</h1>
    <?php if ($q !== ''): ?>
      <p class="page-subtitle">
        <?= (int)$totalHits ?> result<?= $totalHits !== 1 ? 's' : '' ?> for
        "<strong><?= Security::h($q) ?></strong>"
      </p>
    <?php else: ?>
      <p class="page-subtitle">Enter a keyword to search across all modules.</p>
    <?php endif; ?>
  </div>
</div>

<!-- Search bar -->
<div class="card" style="margin-bottom:24px">
  <div class="card-body">
    <form method="GET" action="/search" style="display:flex;gap:12px;align-items:center">
      <input
        type="search"
        name="q"
        value="<?= Security::h($q) ?>"
        placeholder="Search risks, policies, audits…"
        class="form-control"
        autofocus
        style="flex:1"
      >
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-search"></i> Search
      </button>
    </form>
  </div>
</div>

<?php if ($tooShort): ?>
  <!-- Query too short -->
  <div class="empty-state">
    <i class="bi bi-search"></i>
    <h3>Query too short</h3>
    <p>Please enter at least 2 characters to search.</p>
  </div>

<?php elseif ($q !== '' && empty($results)): ?>
  <!-- No results -->
  <div class="empty-state">
    <i class="bi bi-binoculars"></i>
    <h3>No results found</h3>
    <p>No results found for "<strong><?= Security::h($q) ?></strong>". Try a different keyword.</p>
  </div>

<?php elseif (!empty($results)): ?>
  <!-- Results grouped by type -->
  <?php foreach ($typeMeta as $type => $meta): ?>
    <?php if (empty($results[$type])) continue; ?>
    <?php $typeRows = $results[$type]; $count = count($typeRows); ?>

    <div style="margin-bottom:28px">
      <!-- Section heading -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <h2 style="font-size:1.05rem;font-weight:600;margin:0;display:flex;align-items:center;gap:8px">
          <i class="bi <?= $meta['icon'] ?>"></i>
          <?= $meta['label'] ?> (<?= $count ?>)
        </h2>
        <?php if ($count >= 10): ?>
          <a href="<?= Security::h($typeListUrl[$type] ?? '#') ?>" class="btn btn-ghost btn-sm">
            View all <i class="bi bi-arrow-right"></i>
          </a>
        <?php endif; ?>
      </div>

      <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ($typeRows as $row): ?>
          <div class="card" style="margin-bottom:0">
            <div class="card-body" style="padding:14px 18px;display:flex;align-items:center;gap:16px">
              <!-- Title + subtitle -->
              <div style="flex:1;min-width:0">
                <a
                  href="<?= Security::h($row['url']) ?>"
                  class="text-link fw-500"
                  style="font-size:0.95rem"
                >
                  <?= Security::h($row['label']) ?>
                </a>
                <?php if (!empty($row['sub'])): ?>
                  <div class="text-muted text-sm" style="margin-top:2px">
                    <?= Security::h((string)$row['sub']) ?>
                  </div>
                <?php endif; ?>
              </div>

              <!-- Score badge (if applicable) -->
              <?php if (isset($row['score_num']) && $row['score_num'] !== null): ?>
                <span
                  class="badge"
                  style="background:#f3f4f6;color:var(--text);font-variant-numeric:tabular-nums"
                  title="Score"
                >
                  Score <?= (int)$row['score_num'] ?>
                </span>
              <?php endif; ?>

              <!-- Type badge -->
              <span class="badge <?= $meta['badge'] ?>">
                <?= ucfirst($type) ?>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($count >= 10): ?>
        <div style="text-align:center;margin-top:10px">
          <a href="<?= Security::h($typeListUrl[$type] ?? '#') ?>" class="text-link text-sm">
            View all <?= strtolower($meta['label']) ?> &rarr;
          </a>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<?php endif; ?>
