<?php
// ── Data fetching ────────────────────────────────────────────────────────────
// This view can be called from RiskController::roadmap() with pre-fetched data,
// or will fetch its own data when required directly.

if (!isset($roadmapRisks)) {
    Auth::requireAuth();

    $filterStatus   = Security::sanitizeInput($_GET['status']   ?? '');
    $filterOwner    = Security::sanitizeInput($_GET['owner_id'] ?? '');
    $filterLevel    = Security::sanitizeInput($_GET['level']    ?? '');

    $where  = ["(r.treatment_description IS NOT NULL OR r.review_date IS NOT NULL)"];
    $params = [];

    $validStatuses = ['open','accepted','mitigated','closed','transferred','in_progress'];
    if ($filterStatus && in_array($filterStatus, $validStatuses, true)) {
        $where[]  = 'r.status = ?';
        $params[] = $filterStatus;
    }
    if ($filterOwner && (int)$filterOwner > 0) {
        $where[]  = 'r.owner_id = ?';
        $params[] = (int)$filterOwner;
    }
    if ($filterLevel) {
        switch ($filterLevel) {
            case 'critical': $where[] = '(r.likelihood * r.impact) >= 20'; break;
            case 'high':     $where[] = '(r.likelihood * r.impact) BETWEEN 15 AND 19'; break;
            case 'medium':   $where[] = '(r.likelihood * r.impact) BETWEEN 6 AND 14'; break;
            case 'low':      $where[] = '(r.likelihood * r.impact) < 6'; break;
        }
    }

    $whereSQL = implode(' AND ', $where);

    $roadmapRisks = Database::fetchAll(
        "SELECT r.id, r.title, r.risk_id, r.likelihood, r.impact, r.status,
                r.treatment_description AS treatment_plan,
                r.status                AS treatment_status,
                r.review_date           AS due_date,
                u.name AS owner_name, u.id AS owner_id
         FROM risks r
         LEFT JOIN users u ON u.id = r.owner_id
         WHERE {$whereSQL}
         ORDER BY (r.likelihood * r.impact) DESC, r.review_date ASC NULLS LAST",
        $params
    );

    $allUsers = Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
} else {
    $filterStatus = Security::sanitizeInput($_GET['status']   ?? '');
    $filterOwner  = Security::sanitizeInput($_GET['owner_id'] ?? '');
    $filterLevel  = Security::sanitizeInput($_GET['level']    ?? '');
    $allUsers     = $allUsers ?? Database::fetchAll("SELECT id, name FROM users WHERE is_active = TRUE ORDER BY name");
}

// ── Helper functions ─────────────────────────────────────────────────────────
function roadmapLevel(int $score): string {
    if ($score >= 20) return 'critical';
    if ($score >= 15) return 'high';
    if ($score >= 6)  return 'medium';
    return 'low';
}

function roadmapLevelLabel(string $level): string {
    return ['critical'=>'Critical','high'=>'High','medium'=>'Medium','low'=>'Low'][$level] ?? ucfirst($level);
}

$today    = date('Y-m-d');
$todayTs  = strtotime($today);
$displayWindowDays = 90; // days shown in timeline

// Group risks by computed level
$grouped = ['critical'=>[],'high'=>[],'medium'=>[],'low'=>[]];
foreach ($roadmapRisks as $r) {
    $score = (int)$r['likelihood'] * (int)$r['impact'];
    $level = roadmapLevel($score);
    $r['_score'] = $score;
    $grouped[$level][] = $r;
}

$levelConfig = [
    'critical' => ['label'=>'Critical','border'=>'var(--danger)','bg'=>'rgba(239,68,68,.07)','badge_bg'=>'var(--danger)','badge_fg'=>'#fff'],
    'high'     => ['label'=>'High',    'border'=>'#f97316','bg'=>'rgba(249,115,22,.07)','badge_bg'=>'#f97316','badge_fg'=>'#fff'],
    'medium'   => ['label'=>'Medium',  'border'=>'var(--warning)','bg'=>'rgba(245,158,11,.07)','badge_bg'=>'var(--warning)','badge_fg'=>'#fff'],
    'low'      => ['label'=>'Low',     'border'=>'var(--success)','bg'=>'rgba(34,197,94,.07)','badge_bg'=>'var(--success)','badge_fg'=>'#fff'],
];

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Treatment Roadmap</h1>
    <p class="page-subtitle">Gantt-style timeline of active risk treatment plans and due dates</p>
  </div>
  <div class="page-actions">
    <a href="/risk" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Risk Register</a>
    <a href="/risk/matrix" class="btn btn-ghost"><i class="bi bi-grid-3x3-gap-fill"></i> Matrix View</a>
  </div>
</div>

<?php
$_roadmapActiveFilters = (int)!empty($filterStatus) + (int)!empty($filterOwner) + (int)!empty($filterLevel);
?>
<div class="filter-toolbar" style="margin-bottom:16px">
  <form method="GET" action="/risk/roadmap">
    <div class="filter-popover-wrap">
      <button type="button" class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#roadmapFilterPopover">
        <i class="bi bi-funnel-fill"></i> Filters
        <?php if ($_roadmapActiveFilters > 0): ?>
          <span class="filter-active-count"><?= $_roadmapActiveFilters ?></span>
        <?php endif; ?>
      </button>
      <div id="roadmapFilterPopover" class="filter-popover">
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-control form-control-sm">
            <option value="">All Statuses</option>
            <?php foreach (['open'=>'Open','in_progress'=>'In Progress','mitigated'=>'Mitigated','accepted'=>'Accepted','closed'=>'Closed'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= $filterStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Owner</label>
          <select name="owner_id" class="form-control form-control-sm">
            <option value="">All Owners</option>
            <?php foreach ($allUsers as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= ((int)$filterOwner === (int)$u['id']) ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Risk Level</label>
          <select name="level" class="form-control form-control-sm">
            <option value="">All Risk Levels</option>
            <?php foreach (['critical'=>'Critical','high'=>'High','medium'=>'Medium','low'=>'Low'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= $filterLevel === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-popover-footer">
          <a href="/risk/roadmap" class="btn btn-ghost btn-sm">Clear</a>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check"></i> Apply</button>
        </div>
      </div>
    </div>
  </form>
  <span style="font-size:12px;color:var(--text-muted);">
    Today: <strong><?= date('M j, Y') ?></strong> · Window: <?= $displayWindowDays ?> days
  </span>
</div>

<!-- Timeline legend -->
<div style="display:flex;gap:20px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
  <span style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">Legend:</span>
  <span style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <span style="display:inline-block;width:32px;height:10px;border-radius:4px;background:var(--danger);"></span>Past Due
  </span>
  <span style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <span style="display:inline-block;width:32px;height:10px;border-radius:4px;background:#f97316;"></span>Due ≤30 days
  </span>
  <span style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <span style="display:inline-block;width:32px;height:10px;border-radius:4px;background:var(--primary);"></span>On track
  </span>
  <span style="display:flex;align-items:center;gap:6px;font-size:12px;">
    <span style="display:inline-block;width:32px;height:10px;border-radius:4px;background:#d1d5db;"></span>No due date
  </span>
</div>

<?php
$totalVisible = 0;
foreach ($grouped as $level => $risks):
  if (empty($risks)) continue;
  $cfg = $levelConfig[$level];
  $totalVisible += count($risks);
?>
<!-- ── <?= $cfg['label'] ?> group ─────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;border-left:4px solid <?= $cfg['border'] ?>;">
  <div class="card-header" style="background:<?= $cfg['bg'] ?>;border-bottom:1px solid <?= $cfg['border'] ?>22;">
    <h3 class="card-title" style="color:<?= $cfg['border'] ?>;">
      <?= $cfg['label'] ?> Risk &mdash; <?= count($risks) ?> item<?= count($risks) !== 1 ? 's' : '' ?>
    </h3>
  </div>
  <div class="card-body p0">
    <?php foreach ($risks as $r):
      $dueDate    = $r['due_date'] ?? null;
      $dueTs      = $dueDate ? strtotime($dueDate) : null;
      $daysUntil  = $dueTs ? (int)(($dueTs - $todayTs) / 86400) : null;

      // Bar color
      if ($dueTs === null) {
          $barColor = '#d1d5db';
          $barWidth = 0;
          $barLeft  = 0;
      } elseif ($daysUntil < 0) {
          // Past due — fill from 0 to today marker (full bar, red)
          $barColor = 'var(--danger)';
          $barWidth = 100;
          $barLeft  = 0;
      } elseif ($daysUntil <= 30) {
          $barColor = '#f97316';
          $barWidth = min(100, round($daysUntil / $displayWindowDays * 100));
          $barLeft  = 0;
      } else {
          $barColor = 'var(--primary)';
          $barWidth = min(100, round($daysUntil / $displayWindowDays * 100));
          $barLeft  = 0;
      }

      // Status badge
      $treatStatus = $r['treatment_status'] ?? $r['status'] ?? 'open';
      $treatStatusColors = [
          'open'        => ['var(--danger-subtle)','var(--danger)'],
          'in_progress' => ['var(--info-subtle)','#2563eb'],
          'mitigated'   => ['var(--success-subtle)','var(--primary)'],
          'accepted'    => ['var(--warning-subtle)','var(--warning)'],
          'closed'      => ['#f9fafb','#71717a'],
          'transferred' => ['rgba(55,65,81,.05)','var(--secondary)'],
      ];
      [$tsBg, $tsFg] = $treatStatusColors[$treatStatus] ?? ['#f4f4f5','#71717a'];
    ?>
      <div style="display:grid;grid-template-columns:320px 1fr;gap:0;border-bottom:1px solid var(--border-light);">

        <!-- Left: meta -->
        <div style="padding:14px 16px;border-right:1px solid var(--border-light);">
          <div style="font-weight:600;font-size:13px;margin-bottom:4px;">
            <a href="/risk/<?= (int)$r['id'] ?>" style="color:var(--text);text-decoration:none;" class="table-link">
              <?= Security::h($r['title']) ?>
            </a>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:11px;">
            <?php if (!empty($r['risk_id'])): ?>
              <span style="font-family:monospace;color:var(--text-muted);"><?= Security::h($r['risk_id']) ?></span>
            <?php endif; ?>

            <!-- Score pill -->
            <span style="background:<?= $cfg['badge_bg'] ?>;color:<?= $cfg['badge_fg'] ?>;padding:1px 8px;border-radius:99px;font-weight:700;">
              <?= $r['_score'] ?>
            </span>

            <!-- Status badge -->
            <span style="background:<?= $tsBg ?>;color:<?= $tsFg ?>;padding:1px 8px;border-radius:99px;font-weight:600;">
              <?= ucfirst(str_replace('_',' ', $treatStatus)) ?>
            </span>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px;display:flex;align-items:center;gap:8px;">
            <i class="bi bi-person"></i> <?= Security::h($r['owner_name'] ?? 'Unassigned') ?>
            <?php if ($dueDate): ?>
              · <i class="bi bi-calendar-event"></i>
              <span style="color:<?= $daysUntil !== null && $daysUntil < 0 ? 'var(--danger)' : ($daysUntil !== null && $daysUntil <= 30 ? '#f97316' : 'var(--text-muted)') ?>;font-weight:600;">
                <?php if ($daysUntil !== null && $daysUntil < 0): ?>
                  Overdue by <?= abs($daysUntil) ?>d
                <?php elseif ($daysUntil !== null): ?>
                  <?= date('M j, Y', $dueTs) ?> (<?= $daysUntil ?>d)
                <?php endif; ?>
              </span>
            <?php else: ?>
              · <span style="color:var(--text-light);">No due date</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right: timeline bar -->
        <div style="padding:14px 16px;display:flex;align-items:center;">
          <div style="position:relative;width:100%;height:20px;background:var(--bg-secondary);border-radius:10px;overflow:hidden;">
            <?php if ($barWidth > 0): ?>
              <div style="
                position:absolute;
                top:0; bottom:0;
                left:<?= $barLeft ?>%;
                width:<?= $barWidth ?>%;
                background:<?= $barColor ?>;
                border-radius:10px;
                transition:width .3s ease;
              "></div>
            <?php endif; ?>
            <!-- Today marker -->
            <div style="position:absolute;top:0;bottom:0;left:0;width:2px;background:rgba(0,0,0,.3);" title="Today"></div>
          </div>
          <!-- Window labels -->
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-light);margin-top:4px;position:absolute;right:0;width:calc(100% - 352px);padding:0 16px;" aria-hidden="true">
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if ($totalVisible === 0): ?>
  <div class="card">
    <div class="card-body" style="text-align:center;padding:48px;">
      <i class="bi bi-calendar-x" style="font-size:40px;color:var(--text-light);display:block;margin-bottom:12px;"></i>
      <p style="color:var(--text-muted);font-size:14px;margin:0;">No risks with treatment plans or due dates match your filters.</p>
      <a href="/risk" style="margin-top:12px;display:inline-block;" class="btn btn-ghost btn-sm">View All Risks</a>
    </div>
  </div>
<?php endif; ?>

<style>
/* Timeline responsive fix */
@media (max-width: 768px) {
  .roadmap-row { grid-template-columns: 1fr !important; }
  .roadmap-bar-col { display: none; }
}
</style>

<?php
$content      = ob_get_clean();
$pageTitle    = 'Risk Treatment Roadmap';
$activeModule = 'risk_roadmap';
$breadcrumbs  = [['Risk', '/risk'], ['Treatment Roadmap', null]];
require AEGIS_ROOT . '/views/layout.php';
?>
