<?php
$pageTitle    = $pageTitle    ?? 'Risk Review Sessions';
$activeModule = $activeModule ?? 'risk';
$breadcrumbs  = $breadcrumbs  ?? [['Risk Register', '/risk'], ['Review Sessions', null]];

$planned    = (int)($summary['planned']     ?? 0);
$inProgress = (int)($summary['in_progress'] ?? 0);
$completed  = (int)($summary['completed']   ?? 0);
$cancelled  = (int)($summary['cancelled']   ?? 0);

$typeLabels = [
    'periodic'  => 'Periodic',
    'triggered' => 'Triggered',
    'ad_hoc'    => 'Ad Hoc',
    'board'     => 'Board',
];

$typeBadgeColors = [
    'periodic'  => ['#2563eb','#eff6ff'],
    'triggered' => ['#d97706','#fffbeb'],
    'ad_hoc'    => ['#7c3aed','#f5f3ff'],
    'board'     => ['#0891b2','#ecfeff'],
];

$statusConfig = [
    'planned'     => ['fg'=>'#2563eb','bg'=>'#eff6ff','label'=>'Planned'],
    'in_progress' => ['fg'=>'#d97706','bg'=>'#fffbeb','label'=>'In Progress'],
    'completed'   => ['fg'=>'#16a34a','bg'=>'#f0fdf4','label'=>'Completed'],
    'cancelled'   => ['fg'=>'#64748b','bg'=>'#f1f5f9','label'=>'Cancelled'],
];

$filterStatus = $_GET['status'] ?? '';

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Review Sessions</h1>
    <p class="page-subtitle">Structured periodic and triggered risk reviews</p>
  </div>
  <div class="page-actions">
    <a href="/risk" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Risk Register</a>
    <?php if (Auth::can('risk.write')): ?>
    <a href="/risk/reviews/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Schedule Review</a>
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
<?php if (!empty($_GET['completed'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Review completed and sign-off recorded.</div>
<?php endif; ?>

<!-- KPI Summary Row -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">

  <div class="card" style="border-left:4px solid #2563eb;">
    <div class="card-body" style="padding:18px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-calendar-check" style="font-size:20px;color:#2563eb;"></i>
      </div>
      <div>
        <div style="font-size:26px;font-weight:700;line-height:1;color:#2563eb;"><?= $planned ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Planned</div>
      </div>
    </div>
  </div>

  <div class="card" style="border-left:4px solid #d97706;">
    <div class="card-body" style="padding:18px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:#fffbeb;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-play-circle" style="font-size:20px;color:#d97706;"></i>
      </div>
      <div>
        <div style="font-size:26px;font-weight:700;line-height:1;color:#d97706;"><?= $inProgress ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">In Progress</div>
      </div>
    </div>
  </div>

  <div class="card" style="border-left:4px solid #16a34a;">
    <div class="card-body" style="padding:18px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-check-circle-fill" style="font-size:20px;color:#16a34a;"></i>
      </div>
      <div>
        <div style="font-size:26px;font-weight:700;line-height:1;color:#16a34a;"><?= $completed ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Completed</div>
      </div>
    </div>
  </div>

  <div class="card" style="border-left:4px solid #94a3b8;">
    <div class="card-body" style="padding:18px 20px;display:flex;align-items:center;gap:14px;">
      <div style="width:44px;height:44px;border-radius:10px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <i class="bi bi-x-circle" style="font-size:20px;color:#94a3b8;"></i>
      </div>
      <div>
        <div style="font-size:26px;font-weight:700;line-height:1;color:#94a3b8;"><?= $cancelled ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:3px;">Cancelled</div>
      </div>
    </div>
  </div>

</div>

<!-- Filter Bar -->
<div class="filter-bar card">
  <form method="GET" class="filter-form">
    <select name="status" class="form-control form-control-sm" id="reviewsStatusFilter">
      <option value="">All statuses</option>
      <?php foreach ($statusConfig as $sv => $sc): ?>
        <option value="<?= $sv ?>" <?= $filterStatus === $sv ? 'selected' : '' ?>><?= $sc['label'] ?></option>
      <?php endforeach; ?>
    </select>
    <a href="/risk/reviews" class="btn btn-ghost btn-sm">Clear</a>
  </form>
</div>

<!-- Reviews Table -->
<div class="card">
  <div class="card-body p0">
    <?php
    $displayedReviews = $reviews;
    if ($filterStatus) {
        $displayedReviews = array_values(array_filter($reviews, fn($r) => $r['status'] === $filterStatus));
    }
    ?>
    <?php if (empty($displayedReviews)): ?>
      <div class="empty-state" style="padding:3rem;text-align:center;">
        <i class="bi bi-clipboard2-check" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:12px;"></i>
        <p style="color:var(--text-muted);margin:0;">
          <?= $filterStatus ? 'No reviews match this filter.' : 'No review sessions yet.' ?>
          <?php if (!$filterStatus && Auth::can('risk.write')): ?>
            <a href="/risk/reviews/create">Schedule the first one</a>.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Type</th>
          <th>Scheduled Date</th>
          <th>Status</th>
          <th>Lead Reviewer</th>
          <th>Progress</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($displayedReviews as $rev):
          $sc    = $statusConfig[$rev['status']] ?? ['fg'=>'#64748b','bg'=>'#f1f5f9','label'=>ucfirst($rev['status'])];
          [$tFg, $tBg] = $typeBadgeColors[$rev['review_type']] ?? ['#64748b','#f1f5f9'];
          $total    = max(1, (int)$rev['total_risks']);
          $reviewed = (int)$rev['reviewed_count'];
          $pct      = $total > 0 ? round($reviewed / $total * 100) : 0;
        ?>
        <tr>
          <td>
            <a href="/risk/reviews/<?= (int)$rev['id'] ?>" class="table-link fw-500">
              <?= Security::h($rev['title']) ?>
            </a>
          </td>
          <td>
            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:<?= $tBg ?>;color:<?= $tFg ?>;white-space:nowrap;">
              <?= Security::h($typeLabels[$rev['review_type']] ?? ucfirst($rev['review_type'])) ?>
            </span>
          </td>
          <td style="white-space:nowrap;"><?= $rev['scheduled_date'] ? date('M j, Y', strtotime($rev['scheduled_date'])) : '—' ?></td>
          <td>
            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:<?= $sc['bg'] ?>;color:<?= $sc['fg'] ?>;white-space:nowrap;">
              <?= $sc['label'] ?>
            </span>
          </td>
          <td><?= Security::h($rev['lead_reviewer_name'] ?? '—') ?></td>
          <td style="min-width:160px;">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">
              <?= $reviewed ?>/<?= (int)$rev['total_risks'] ?>
              <?php if ($rev['total_risks'] > 0): ?>
                <span style="color:var(--text-muted);">(<?= $pct ?>%)</span>
              <?php endif; ?>
            </div>
            <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
              <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 100 ? '#16a34a' : ($pct > 50 ? '#d97706' : '#6366f1') ?>;border-radius:3px;transition:width .3s;"></div>
            </div>
          </td>
          <td>
            <a href="/risk/reviews/<?= (int)$rev['id'] ?>" class="btn btn-ghost btn-sm" title="View">
              <i class="bi bi-eye"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
var reviewsStatusFilter = document.getElementById('reviewsStatusFilter');
if (reviewsStatusFilter) {
  reviewsStatusFilter.addEventListener('change', function() { this.form.submit(); });
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
