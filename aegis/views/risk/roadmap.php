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
$displayWindowDays = 120; // forward window shown on the shared timeline axis

// ── Shared time axis (today → today + window) ─────────────────────────────────
$axisStart = $todayTs;
$axisEnd   = strtotime("+{$displayWindowDays} days", $todayTs);
$axisSpan  = max(1, $axisEnd - $axisStart);

// Month gridlines/labels that fall inside the window
$monthMarks = [];
$mc = strtotime('+1 month', strtotime(date('Y-m-01', $todayTs)));
while ($mc <= $axisEnd) {
    $monthMarks[] = ['pct' => round(($mc - $axisStart) / $axisSpan * 100, 3), 'label' => date('M Y', $mc)];
    $mc = strtotime('+1 month', $mc);
}

// Position (%) of a timestamp along the shared axis, clamped to [0,100]
$ganttPct = static function (int $ts) use ($axisStart, $axisSpan): float {
    return max(0.0, min(100.0, ($ts - $axisStart) / $axisSpan * 100));
};

// Group risks by computed level
$grouped = ['critical'=>[],'high'=>[],'medium'=>[],'low'=>[]];
foreach ($roadmapRisks as $r) {
    $score = (int)$r['likelihood'] * (int)$r['impact'];
    $level = roadmapLevel($score);
    $r['_score'] = $score;
    $grouped[$level][] = $r;
}
$totalVisible = array_sum(array_map('count', $grouped));

$levelConfig = [
    'critical' => ['label'=>'Critical','border'=>'var(--danger)', 'bg'=>'var(--danger-subtle)', 'badge_bg'=>'var(--danger)', 'badge_fg'=>'#fff'],
    'high'     => ['label'=>'High',    'border'=>'var(--orange)', 'bg'=>'var(--warning-subtle)','badge_bg'=>'var(--orange)', 'badge_fg'=>'#fff'],
    'medium'   => ['label'=>'Medium',  'border'=>'var(--warning)','bg'=>'var(--warning-subtle)','badge_bg'=>'var(--warning)','badge_fg'=>'#fff'],
    'low'      => ['label'=>'Low',     'border'=>'var(--success)','bg'=>'var(--success-subtle)','badge_bg'=>'var(--success)','badge_fg'=>'#fff'],
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
<div class="rm-legend">
  <span class="rm-legend-title">Legend</span>
  <span class="rm-legend-item"><span class="rm-swatch" style="background:var(--danger);"></span>Past due</span>
  <span class="rm-legend-item"><span class="rm-swatch" style="background:var(--orange);"></span>Due &le;30 days</span>
  <span class="rm-legend-item"><span class="rm-swatch" style="background:var(--primary);"></span>On track</span>
  <span class="rm-legend-item"><span class="rm-swatch rm-swatch-empty"></span>No due date</span>
</div>

<?php if ($totalVisible > 0): ?>
<div class="card rm-gantt">
  <!-- Axis header: meta label + month scale -->
  <div class="rm-row rm-axis">
    <div class="rm-meta rm-axis-meta">Risk / Treatment</div>
    <div class="rm-track rm-axis-track">
      <span class="rm-axis-tick rm-axis-today" style="left:0;">Today</span>
      <?php foreach ($monthMarks as $m): ?>
        <span class="rm-axis-tick" style="left:<?= $m['pct'] ?>%;"><?= Security::h($m['label']) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Continuous gridline overlay behind every row -->
  <div class="rm-gridlines" aria-hidden="true">
    <span class="rm-gridline rm-gridline-today" style="left:0;"></span>
    <?php foreach ($monthMarks as $m): ?>
      <span class="rm-gridline" style="left:<?= $m['pct'] ?>%;"></span>
    <?php endforeach; ?>
  </div>

  <?php foreach ($grouped as $level => $risks):
    if (empty($risks)) continue;
    $cfg = $levelConfig[$level];
  ?>
  <div class="rm-group-label" style="color:<?= $cfg['border'] ?>;background:<?= $cfg['bg'] ?>;border-left:3px solid <?= $cfg['border'] ?>;">
    <?= $cfg['label'] ?> Risk &mdash; <?= count($risks) ?> item<?= count($risks) !== 1 ? 's' : '' ?>
  </div>

    <?php foreach ($risks as $r):
      $dueDate    = $r['due_date'] ?? null;
      $dueTs      = $dueDate ? strtotime($dueDate) : null;
      $daysUntil  = $dueTs ? (int) floor(($dueTs - $todayTs) / 86400) : null;

      // Bar geometry on the SHARED axis (today → today + window)
      $hasBar = false; $barWidth = 0.0; $barColor = 'var(--border)';
      $overdue = false; $beyond = false;
      if ($dueTs !== null) {
          if ($daysUntil < 0) {
              // Past due — short red stub pinned at the today line
              $overdue  = true; $hasBar = true;
              $barColor = 'var(--danger)'; $barWidth = 3.0;
          } else {
              $beyond   = ($dueTs > $axisEnd);
              $barColor = $daysUntil <= 30 ? 'var(--orange)' : 'var(--primary)';
              $hasBar   = true; $barWidth = max(2.0, $ganttPct($dueTs));
          }
      }

      $treatStatus = $r['treatment_status'] ?? $r['status'] ?? 'open';
      $treatStatusColors = [
          'open'        => ['var(--danger-subtle)','var(--danger)'],
          'in_progress' => ['var(--info-subtle)','var(--moderate)'],
          'mitigated'   => ['var(--success-subtle)','var(--primary)'],
          'accepted'    => ['var(--warning-subtle)','var(--warning)'],
          'closed'      => ['var(--bg-secondary)','var(--text-muted)'],
          'transferred' => ['var(--bg-secondary)','var(--secondary)'],
      ];
      [$tsBg, $tsFg] = $treatStatusColors[$treatStatus] ?? ['var(--bg-secondary)','var(--text-muted)'];
    ?>
    <div class="rm-row">
      <!-- Left: meta -->
      <div class="rm-meta">
        <div class="rm-title">
          <a href="/risk/<?= (int)$r['id'] ?>" class="table-link"><?= Security::h($r['title']) ?></a>
        </div>
        <div class="rm-tags">
          <?php if (!empty($r['risk_id'])): ?><span class="rm-rid"><?= Security::h($r['risk_id']) ?></span><?php endif; ?>
          <span class="rm-pill" style="background:<?= $cfg['badge_bg'] ?>;color:<?= $cfg['badge_fg'] ?>;"><?= (int)$r['_score'] ?></span>
          <span class="rm-pill" style="background:<?= $tsBg ?>;color:<?= $tsFg ?>;"><?= Security::h(ucfirst(str_replace('_',' ', $treatStatus))) ?></span>
        </div>
        <div class="rm-owner">
          <i class="bi bi-person"></i> <?= Security::h($r['owner_name'] ?? 'Unassigned') ?>
          <?php if ($dueDate): ?>
            · <i class="bi bi-calendar-event"></i>
            <span style="color:<?= $overdue ? 'var(--danger)' : ($daysUntil <= 30 ? 'var(--orange)' : 'var(--text-muted)') ?>;font-weight:600;">
              <?php if ($overdue): ?>Overdue <?= abs($daysUntil) ?>d<?php else: ?><?= date('M j, Y', $dueTs) ?> (<?= $daysUntil ?>d)<?php endif; ?>
            </span>
          <?php else: ?>
            · <span style="color:var(--text-light);">No due date</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right: timeline track on the shared axis -->
      <div class="rm-track">
        <?php if ($hasBar): ?>
          <div class="rm-bar<?= $overdue ? ' rm-bar-overdue' : '' ?>"
               style="width:<?= round($barWidth, 3) ?>%;background:<?= $barColor ?>;">
            <?php if ($beyond): ?><i class="bi bi-chevron-double-right rm-bar-beyond"></i><?php endif; ?>
          </div>
          <?php if (!$overdue): ?>
            <span class="rm-marker" style="left:<?= round($ganttPct($dueTs), 3) ?>%;background:<?= $barColor ?>;" title="Due <?= date('M j, Y', $dueTs) ?>"></span>
          <?php endif; ?>
        <?php else: ?>
          <div class="rm-bar-empty"></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

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
/* ── Treatment Roadmap — shared-axis Gantt ──────────────────────────────────── */
.rm-legend { display:flex; gap:18px; align-items:center; margin-bottom:16px; flex-wrap:wrap; }
.rm-legend-title { font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }
.rm-legend-item { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text); }
.rm-swatch { display:inline-block; width:28px; height:10px; border-radius:4px; }
.rm-swatch-empty { background:var(--border); border:1px dashed var(--text-light); }

.rm-gantt { position:relative; padding:0; overflow:hidden; }

/* Rows share one grid template so the meta column and track always line up */
.rm-row { display:grid; grid-template-columns:300px 1fr; border-bottom:1px solid var(--border-light); }
.rm-row:last-child { border-bottom:none; }

.rm-meta { padding:12px 16px; border-right:1px solid var(--border-light); min-width:0; }
.rm-title { font-weight:600; font-size:13px; margin-bottom:5px; line-height:1.3; }
.rm-title a { color:var(--text); text-decoration:none; }
.rm-tags { display:flex; flex-wrap:wrap; gap:6px; align-items:center; font-size:11px; }
.rm-rid { font-family:monospace; color:var(--text-muted); }
.rm-pill { padding:1px 8px; border-radius:99px; font-weight:700; line-height:1.6; }
.rm-owner { font-size:12px; color:var(--text-muted); margin-top:6px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }

/* Track = the shared axis lane; NO horizontal padding so % positions align with gridlines */
.rm-track { position:relative; min-height:48px; }
.rm-bar {
  position:absolute; left:0; top:50%; transform:translateY(-50%);
  height:14px; border-radius:7px; min-width:4px;
  display:flex; align-items:center; justify-content:flex-end;
  transition:width .3s ease;
}
.rm-bar-overdue { border-radius:0 7px 7px 0; box-shadow:-3px 0 0 0 var(--danger); }
.rm-bar-beyond { color:#fff; font-size:9px; margin-right:3px; }
.rm-marker {
  position:absolute; top:50%; transform:translate(-50%,-50%);
  width:12px; height:12px; border-radius:50%; border:2px solid var(--card-bg);
  box-shadow:0 1px 3px rgba(0,0,0,.25);
}
.rm-bar-empty {
  position:absolute; left:0; right:0; top:50%; transform:translateY(-50%);
  height:2px; border-top:2px dashed var(--border);
}

/* Axis header */
.rm-axis { height:34px; border-bottom:1px solid var(--border); background:var(--bg-secondary); }
.rm-axis-meta { display:flex; align-items:center; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text-muted); padding:0 16px; border-right:1px solid var(--border); }
.rm-axis-track { position:relative; }
.rm-axis-tick { position:absolute; top:50%; transform:translate(-50%,-50%); font-size:11px; font-weight:600; color:var(--text-muted); white-space:nowrap; }
.rm-axis-today { transform:translate(0,-50%); color:var(--text); font-weight:700; }

/* Continuous gridline overlay (offset by the 300px meta column, below the 34px axis header) */
.rm-gridlines { position:absolute; left:300px; right:0; top:34px; bottom:0; pointer-events:none; z-index:0; }
.rm-gridline { position:absolute; top:0; bottom:0; width:1px; background:var(--border-light); }
.rm-gridline-today { width:2px; background:var(--text-light); }

/* Group separator band */
.rm-group-label { font-size:12px; font-weight:700; padding:7px 16px; position:relative; z-index:1; }

/* Keep meta and bars above the gridlines */
.rm-meta, .rm-track { position:relative; z-index:1; }
.rm-meta { background:var(--card-bg); }

@media (max-width: 720px) {
  .rm-row { grid-template-columns:1fr; }
  .rm-meta { border-right:none; }
  .rm-axis, .rm-gridlines { display:none; }
  .rm-track { min-height:36px; padding:0 16px 12px; }
  .rm-bar, .rm-bar-empty { position:relative; left:auto; top:auto; transform:none; }
  .rm-marker { display:none; }
}
</style>

<?php
$content      = ob_get_clean();
$pageTitle    = 'Risk Treatment Roadmap';
$activeModule = 'risk_roadmap';
$breadcrumbs  = [['Risk', '/risk'], ['Treatment Roadmap', null]];
require AEGIS_ROOT . '/views/layout.php';
?>
