<?php
$breadcrumbs = $breadcrumbs ?? [['Risks', '/risk'], ['Dashboard', null]];
ob_start();

// Helper: risk level label from score (canonical bands — see src/RiskScore.php)
function dashRiskLevel(int $score): string {
    return RiskScore::scoreLabel($score);
}
// Helper: hex color for level
function dashLevelColor(int $score): string {
    if ($score > 14) return 'var(--danger)';
    if ($score > 9)  return 'var(--orange)';
    if ($score > 4)  return 'var(--warning)';
    return 'var(--success)';
}
// Helper: cell background for heat-map score
function dashCellBg(int $score): string {
    if ($score > 14) return '#fee2e2';
    if ($score > 9)  return '#ffedd5';
    if ($score > 4)  return '#fefce8';
    return 'var(--success-subtle)';
}
function dashCellBorder(int $score): string {
    if ($score > 14) return 'var(--danger-border)';
    if ($score > 9)  return '#fdba74';
    if ($score > 4)  return '#fde68a';
    return 'var(--success-border)';
}
function dashCellText(int $score): string {
    if ($score > 14) return 'var(--danger)';
    if ($score > 9)  return 'var(--danger)';
    if ($score > 4)  return 'var(--warning)';
    return 'var(--primary-dark)';
}

$uncontrolledCount = count($uncontrolled ?? []);
$actionsOverdue    = (int)($actionBacklog['overdue'] ?? 0);

// Prepare trend data as JSON for canvas chart
$trendJson = '[]';
if (!empty($trendData)) {
    $pts = [];
    foreach ($trendData as $row) {
        $pts[] = [
            'week'  => $row['week'],
            'avg'   => round((float)$row['avg_score'], 2),
            'max'   => (int)$row['max_score'],
            'count' => (int)$row['risk_count'],
        ];
    }
    $trendJson = json_encode($pts, JSON_HEX_TAG | JSON_HEX_AMP);
}
?>

<style nonce="<?= Security::nonce() ?>">
/* ── Dashboard layout ─────────────────────────────── */
.rdash-kpi-strip {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 6px;
    margin-bottom: 20px;
    scrollbar-width: thin;
}
.rdash-kpi {
    flex: 0 0 auto;
    min-width: 110px;
    background: var(--bg-secondary, #f9fafb);
    border: 1px solid var(--border, #e4e4e7);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    cursor: default;
    transition: box-shadow .15s;
}
.rdash-kpi:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.rdash-kpi .kpi-icon { font-size: 20px; margin-bottom: 2px; }
.rdash-kpi .kpi-num  { font-size: 26px; font-weight: 800; line-height: 1; }
.rdash-kpi .kpi-lbl  { font-size: 11px; font-weight: 600; color: var(--text-muted, #71717a); text-transform: uppercase; letter-spacing: .04em; }

/* ── Two-column layout ────────────────────────────── */
.rdash-cols {
    display: grid;
    grid-template-columns: 60% 1fr;
    gap: 20px;
    margin-bottom: 20px;
}
@media (max-width: 900px) {
    .rdash-cols { grid-template-columns: 1fr; }
}
.rdash-left  { display: flex; flex-direction: column; gap: 20px; }
.rdash-right { display: flex; flex-direction: column; gap: 20px; }

/* ── Bottom 3-col row ─────────────────────────────── */
.rdash-bottom {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}
@media (max-width: 900px) {
    .rdash-bottom { grid-template-columns: 1fr; }
}

/* ── Card helpers ─────────────────────────────────── */
.rdash-card-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-muted, #71717a);
    text-transform: uppercase;
    letter-spacing: .05em;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 14px;
}
.rdash-card-title i { font-size: 16px; }

/* ── Heat map ─────────────────────────────────────── */
.rdash-heatmap-wrap {
    overflow-x: auto;
    position: relative;
}
.rdash-heatmap {
    display: grid;
    grid-template-columns: 28px repeat(5, 60px);
    grid-template-rows: repeat(5, 60px) 28px;
    gap: 3px;
    width: max-content;
    margin: 0 auto;
    position: relative;
}
.rdash-hm-cell {
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    position: relative;
    transition: transform .1s, box-shadow .1s;
    text-decoration: none;
}
.rdash-hm-cell:hover { transform: scale(1.08); box-shadow: 0 4px 12px rgba(0,0,0,.15); z-index: 5; }
.rdash-hm-axis-lbl {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted, #71717a);
}
.rdash-hm-axis-title {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted, #71717a);
    text-transform: uppercase;
    letter-spacing: .04em;
}

/* ── Tooltip ──────────────────────────────────────── */
.rdash-tooltip {
    display: none;
    position: absolute;
    bottom: calc(100% + 6px);
    left: 50%;
    transform: translateX(-50%);
    background: #111111;
    color: #f9fafb;
    font-size: 11px;
    font-weight: 500;
    padding: 5px 8px;
    border-radius: 6px;
    white-space: nowrap;
    z-index: 100;
    pointer-events: none;
    max-width: 200px;
    white-space: normal;
    text-align: center;
    line-height: 1.4;
}
.rdash-hm-cell:hover .rdash-tooltip { display: block; }

/* ── Score badge ──────────────────────────────────── */
.rdash-score-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 22px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    padding: 0 8px;
}

/* ── Top risks table ──────────────────────────────── */
.rdash-compact-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.rdash-compact-table th {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-muted, #71717a);
    padding: 0 8px 8px;
    border-bottom: 1px solid var(--border, #e4e4e7);
    white-space: nowrap;
}
.rdash-compact-table td {
    padding: 7px 8px;
    border-bottom: 1px solid var(--border, #e4e4e7);
    vertical-align: middle;
}
.rdash-compact-table tr:last-child td { border-bottom: none; }
.rdash-compact-table a.tlink {
    color: var(--primary, var(--primary));
    text-decoration: none;
    font-weight: 500;
}
.rdash-compact-table a.tlink:hover { text-decoration: underline; }

/* ── Upcoming reviews ─────────────────────────────── */
.rdash-review-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 9px 0;
    border-bottom: 1px solid var(--border, #e4e4e7);
}
.rdash-review-item:last-child { border-bottom: none; }
.rdash-review-date {
    flex: 0 0 44px;
    text-align: center;
    background: var(--bg-secondary, #f9fafb);
    border-radius: 8px;
    padding: 4px 2px;
    font-size: 11px;
    font-weight: 700;
}
.rdash-review-date .rday { font-size: 18px; font-weight: 800; line-height: 1; }
.rdash-review-date.overdue { background: var(--danger-subtle); color: var(--danger); }

/* ── Appetite / uncontrolled list ─────────────────── */
.rdash-list-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid var(--border, #e4e4e7);
    font-size: 13px;
}
.rdash-list-item:last-child { border-bottom: none; }

/* ── Action backlog donut ─────────────────────────── */
.rdash-backlog-bars {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 4px;
}
.rdash-bar-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
}
.rdash-bar-track {
    flex: 1;
    height: 10px;
    background: var(--border, #e4e4e7);
    border-radius: 20px;
    overflow: hidden;
}
.rdash-bar-fill {
    height: 100%;
    border-radius: 20px;
    transition: width .4s ease;
}
.rdash-bar-label { min-width: 80px; font-weight: 600; }
.rdash-bar-count { min-width: 24px; text-align: right; font-weight: 700; }

/* ── Recent changes strip ─────────────────────────── */
.rdash-changes-strip {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    padding-bottom: 6px;
    scrollbar-width: thin;
}
.rdash-change-card {
    flex: 0 0 auto;
    min-width: 180px;
    max-width: 220px;
    background: var(--bg-secondary, #f9fafb);
    border: 1px solid var(--border, #e4e4e7);
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 12px;
}
.rdash-change-card .rcc-id   { font-size: 11px; color: var(--text-muted, #71717a); font-weight: 600; margin-bottom: 2px; }
.rdash-change-card .rcc-title{ font-weight: 600; font-size: 12px; margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rdash-change-card .rcc-score{ display: flex; align-items: center; gap: 4px; margin-bottom: 4px; }
.rdash-change-card .rcc-meta { color: var(--text-muted, #71717a); font-size: 11px; }

/* ── Canvas chart ─────────────────────────────────── */
#rdash-trend-canvas {
    width: 100%;
    height: 400px;
    display: block;
}

/* ── Empty states ─────────────────────────────────── */
.rdash-empty {
    text-align: center;
    padding: 28px 16px;
    color: var(--text-muted, #71717a);
    font-size: 13px;
}
.rdash-empty i { font-size: 28px; display: block; margin-bottom: 8px; }
.rdash-empty-good {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    background: var(--success-subtle);
    border: 1px solid var(--success-border);
    border-radius: 8px;
    color: var(--primary-dark);
    font-size: 13px;
    font-weight: 600;
}
</style>

<!-- ════════════════════════════════════════════════════
     PAGE HEADER
     ════════════════════════════════════════════════════ -->
<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-graph-up-arrow" style="color:var(--primary,var(--primary))"></i> Risk Dashboard</h1>
    <p class="page-subtitle">Portfolio-level risk intelligence and exposure overview</p>
  </div>
  <div class="page-actions">
    <a href="/risk" class="btn btn-ghost"><i class="bi bi-list-ul"></i> Risk Register</a>
    <?php if (Auth::can('risk.create')): ?>
    <a href="/risk/create" class="btn btn-danger"><i class="bi bi-plus-lg"></i> Log Risk</a>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     KPI STRIP
     ════════════════════════════════════════════════════ -->
<div class="rdash-kpi-strip">

  <!-- Total -->
  <div class="rdash-kpi" style="border-top:3px solid var(--primary)">
    <span class="kpi-icon" style="color:var(--primary)"><i class="bi bi-shield-fill-exclamation"></i></span>
    <span class="kpi-num" style="color:var(--primary)"><?= (int)($summary['total'] ?? 0) ?></span>
    <span class="kpi-lbl">Total Risks</span>
  </div>

  <!-- Critical -->
  <div class="rdash-kpi" style="border-top:3px solid var(--danger)">
    <span class="kpi-icon" style="color:var(--danger)"><i class="bi bi-exclamation-octagon-fill"></i></span>
    <span class="kpi-num" style="color:var(--danger)"><?= (int)($summary['critical'] ?? 0) ?></span>
    <span class="kpi-lbl">Critical</span>
  </div>

  <!-- Open -->
  <div class="rdash-kpi" style="border-top:3px solid var(--info)">
    <span class="kpi-icon" style="color:var(--info)"><i class="bi bi-circle-fill"></i></span>
    <span class="kpi-num" style="color:var(--info)"><?= (int)($summary['open'] ?? 0) ?></span>
    <span class="kpi-lbl">Open</span>
  </div>

  <!-- In Review -->
  <div class="rdash-kpi" style="border-top:3px solid var(--orange)">
    <span class="kpi-icon" style="color:var(--orange)"><i class="bi bi-eye-fill"></i></span>
    <span class="kpi-num" style="color:var(--orange)"><?= (int)($summary['in_review'] ?? 0) ?></span>
    <span class="kpi-lbl">In Review</span>
  </div>

  <!-- Overdue Reviews -->
  <div class="rdash-kpi" style="border-top:3px solid var(--danger)">
    <span class="kpi-icon" style="color:var(--danger)"><i class="bi bi-alarm-fill"></i></span>
    <span class="kpi-num" style="color:var(--danger)">
      <?php if (($summary['overdue_reviews'] ?? 0) > 0): ?>
        <i class="bi bi-exclamation-triangle-fill" style="font-size:18px;vertical-align:middle"></i>
      <?php endif; ?>
      <?= (int)($summary['overdue_reviews'] ?? 0) ?>
    </span>
    <span class="kpi-lbl">Overdue Reviews</span>
  </div>

  <!-- Approved -->
  <div class="rdash-kpi" style="border-top:3px solid var(--success)">
    <span class="kpi-icon" style="color:var(--success)"><i class="bi bi-patch-check-fill"></i></span>
    <span class="kpi-num" style="color:var(--success)"><?= (int)($summary['approved'] ?? 0) ?></span>
    <span class="kpi-lbl">Approved</span>
  </div>

  <!-- Actions Overdue -->
  <div class="rdash-kpi" style="border-top:3px solid var(--danger)">
    <span class="kpi-icon" style="color:var(--danger)"><i class="bi bi-lightning-fill"></i></span>
    <span class="kpi-num" style="color:<?= $actionsOverdue > 0 ? 'var(--danger)' : 'inherit' ?>"><?= $actionsOverdue ?></span>
    <span class="kpi-lbl">Actions Overdue</span>
  </div>

  <!-- No Controls -->
  <div class="rdash-kpi" style="border-top:3px solid var(--warning)">
    <span class="kpi-icon" style="color:var(--warning)"><i class="bi bi-shield-x"></i></span>
    <span class="kpi-num" style="color:<?= $uncontrolledCount > 0 ? 'var(--warning)' : 'inherit' ?>"><?= $uncontrolledCount ?></span>
    <span class="kpi-lbl">No Controls</span>
  </div>

</div><!-- /kpi-strip -->


<!-- ════════════════════════════════════════════════════
     TWO-COLUMN BODY
     ════════════════════════════════════════════════════ -->
<div class="rdash-cols">

  <!-- ─── LEFT COLUMN ─────────────────────────────── -->
  <div class="rdash-left">

    <!-- a) Portfolio Trend -->
    <div class="card">
      <div class="rdash-card-title">
        <i class="bi bi-graph-up" style="color:var(--primary)"></i>
        Portfolio Risk Trend
        <span style="margin-left:auto;font-size:11px;font-weight:500;color:var(--text-muted)">12-week rolling avg score</span>
      </div>
      <?php if (empty($trendData)): ?>
        <div class="rdash-empty"><i class="bi bi-bar-chart-line"></i>No history yet — risk scores will appear here once data accumulates.</div>
      <?php else: ?>
        <canvas id="rdash-trend-canvas"></canvas>
      <?php endif; ?>
    </div>

    <!-- b) Heat Map -->
    <div class="card">
      <div class="rdash-card-title">
        <i class="bi bi-grid-3x3-gap-fill" style="color:var(--orange)"></i>
        Risk Heat Map
        <span style="margin-left:auto;font-size:11px;font-weight:500;color:var(--text-muted)">Likelihood × Impact (click cell to filter)</span>
      </div>

      <div class="rdash-heatmap-wrap">
        <!-- Y-axis title (rotated via writing-mode) -->
        <div style="display:flex;align-items:flex-start;gap:6px">
          <div style="writing-mode:vertical-rl;transform:rotate(180deg);font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;text-align:center;padding:0 2px;margin-top:8px;align-self:center">
            Likelihood
          </div>

          <div>
            <div class="rdash-heatmap">
              <?php
              // Rows: likelihood 5 (top) → 1 (bottom), Cols: impact 1→5
              for ($lik = 5; $lik >= 1; $lik--):
                // Left axis label
                ?>
                <div class="rdash-hm-axis-lbl"><?= $lik ?></div>
                <?php
                for ($imp = 1; $imp <= 5; $imp++):
                  $score    = $lik * $imp;
                  $cellData = $heatMap[$lik][$imp] ?? ['count' => 0, 'labels' => ''];
                  $cnt      = (int)$cellData['count'];
                  $labels   = trim((string)$cellData['labels']);
                  $bg       = dashCellBg($score);
                  $border   = dashCellBorder($score);
                  $txt      = dashCellText($score);
                  $tooltipTxt = $cnt > 0
                    ? 'L'.$lik.'×I'.$imp.'='.$score.' | '.$cnt.' risk'.($cnt===1?'':'s').($labels ? ': '.htmlspecialchars($labels, ENT_QUOTES, 'UTF-8') : '')
                    : 'L'.$lik.'×I'.$imp.'='.$score.' — no risks';
                  ?>
                  <a href="/risk?likelihood=<?= $lik ?>&amp;impact=<?= $imp ?>"
                     class="rdash-hm-cell"
                     style="background:<?= $bg ?>;border:2px solid <?= $border ?>;color:<?= $txt ?>;font-size:<?= $cnt > 99 ? '11' : '13' ?>px"
                     title="<?= htmlspecialchars($tooltipTxt, ENT_QUOTES, 'UTF-8') ?>">
                    <?= $cnt > 0 ? $cnt : '<span style="opacity:.35;font-weight:400">·</span>' ?>
                    <span class="rdash-tooltip"><?= Security::h($tooltipTxt) ?></span>
                  </a>
                <?php endfor; ?>
              <?php endfor; ?>

              <!-- Bottom axis: empty corner + col labels -->
              <div></div><!-- corner -->
              <?php for ($imp = 1; $imp <= 5; $imp++): ?>
                <div class="rdash-hm-axis-lbl"><?= $imp ?></div>
              <?php endfor; ?>
            </div>

            <!-- X-axis title -->
            <div style="text-align:center;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-top:6px;padding-left:28px">
              Impact →
            </div>
          </div><!-- /grid+x-title -->
        </div><!-- /flex row with y-label -->

        <!-- Legend -->
        <div style="display:flex;gap:12px;margin-top:14px;flex-wrap:wrap;font-size:11px;font-weight:600">
          <span style="display:flex;align-items:center;gap:4px"><span style="width:14px;height:14px;border-radius:3px;background:var(--success-subtle);border:1.5px solid var(--success-border);display:inline-block"></span>Low (≤4)</span>
          <span style="display:flex;align-items:center;gap:4px"><span style="width:14px;height:14px;border-radius:3px;background:#fefce8;border:1.5px solid #fde68a;display:inline-block"></span>Medium (5–9)</span>
          <span style="display:flex;align-items:center;gap:4px"><span style="width:14px;height:14px;border-radius:3px;background:#ffedd5;border:1.5px solid #fdba74;display:inline-block"></span>High (10–14)</span>
          <span style="display:flex;align-items:center;gap:4px"><span style="width:14px;height:14px;border-radius:3px;background:var(--danger-subtle);border:1.5px solid var(--danger-border);display:inline-block"></span>Critical (&gt;14)</span>
        </div>
      </div><!-- /heatmap-wrap -->
    </div><!-- /heat map card -->

  </div><!-- /left -->

  <!-- ─── RIGHT COLUMN ────────────────────────────── -->
  <div class="rdash-right">

    <!-- c) Top 10 Risks -->
    <div class="card">
      <div class="rdash-card-title">
        <i class="bi bi-trophy-fill" style="color:var(--danger)"></i>
        Top 10 Risks by Score
      </div>
      <?php if (empty($topRisks)): ?>
        <div class="rdash-empty"><i class="bi bi-shield-check" style="color:var(--success)"></i>No risks recorded yet.</div>
      <?php else: ?>
        <table class="rdash-compact-table">
          <thead>
            <tr>
              <th>Risk ID</th>
              <th style="width:100%">Title</th>
              <th>Score</th>
              <th>Owner</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($topRisks, 0, 10) as $tr):
              $sc  = (int)$tr['inherent_score'];
              $rsc = (int)($tr['residual_score'] ?? $sc);
              $col = dashLevelColor($sc);
            ?>
            <tr>
              <td>
                <a href="/risk/<?= (int)$tr['id'] ?>" class="tlink mono" style="font-size:11px;white-space:nowrap">
                  <?= Security::h($tr['risk_id'] ?? '—') ?>
                </a>
              </td>
              <td>
                <a href="/risk/<?= (int)$tr['id'] ?>" class="tlink" style="font-size:12px">
                  <?= Security::h($tr['title']) ?>
                </a>
                <?php if (!empty($tr['category_name'])): ?>
                  <span style="display:block;font-size:10px;color:var(--text-muted);margin-top:1px">
                    <?php if (!empty($tr['category_color'])): ?>
                      <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?= Security::h($tr['category_color']) ?>;vertical-align:middle;margin-right:2px"></span>
                    <?php endif; ?>
                    <?= Security::h($tr['category_name']) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td style="white-space:nowrap">
                <span class="rdash-score-badge" style="background:<?= $col ?>20;color:<?= $col ?>;border:1.5px solid <?= $col ?>50">
                  <?= $sc ?>
                </span>
                <?php if ($rsc < $sc): ?>
                  <span style="font-size:10px;color:var(--text-muted)">→ <?= $rsc ?></span>
                <?php endif; ?>
              </td>
              <td style="font-size:11px;white-space:nowrap;color:var(--text-muted)"><?= Security::h($tr['owner_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($topRisks) > 10): ?>
          <div style="padding-top:8px;font-size:12px;color:var(--text-muted)">
            <a href="/risk" style="color:var(--primary)">View all <?= count($topRisks) ?> risks →</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div><!-- /top risks -->

    <!-- d) Upcoming Reviews -->
    <div class="card">
      <div class="rdash-card-title">
        <i class="bi bi-calendar-event-fill" style="color:var(--primary)"></i>
        Upcoming Reviews
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted)">Next 45 days</span>
      </div>
      <?php if (empty($upcomingReviews)): ?>
        <div class="rdash-empty"><i class="bi bi-calendar-check"></i>No reviews scheduled in the next 45 days.</div>
      <?php else: ?>
        <?php foreach ($upcomingReviews as $rv):
          $reviewTs   = strtotime((string)$rv['review_date']);
          $daysAway   = (int)round(($reviewTs - time()) / 86400);
          $overdue    = $daysAway < 0;
          $sc         = (int)$rv['inherent_score'];
          $col        = dashLevelColor($sc);
          $dayLabel   = $overdue
            ? 'Overdue ' . abs($daysAway) . 'd'
            : ($daysAway === 0 ? 'Today' : 'In ' . $daysAway . 'd');
          $dayMon     = date('M', $reviewTs);
          $dayNum     = date('j', $reviewTs);
        ?>
        <div class="rdash-review-item">
          <div class="rdash-review-date <?= $overdue ? 'overdue' : '' ?>">
            <div class="rday"><?= $dayNum ?></div>
            <div><?= $dayMon ?></div>
          </div>
          <div style="flex:1;min-width:0">
            <a href="/risk/<?= (int)$rv['id'] ?>" style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <?= Security::h($rv['title']) ?>
            </a>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
              <span style="color:<?= $overdue ? '#b91c1c' : 'var(--text-muted)' ?>;font-weight:<?= $overdue ? '700' : '400' ?>">
                <?= Security::h($dayLabel) ?>
              </span>
              · <?= Security::h($rv['owner_name'] ?? 'Unassigned') ?>
            </div>
          </div>
          <span class="rdash-score-badge" style="background:<?= $col ?>20;color:<?= $col ?>;border:1.5px solid <?= $col ?>50;flex-shrink:0">
            <?= $sc ?>
          </span>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div><!-- /upcoming reviews -->

  </div><!-- /right -->

</div><!-- /rdash-cols -->


<!-- ════════════════════════════════════════════════════
     BOTTOM ROW (3 cols)
     ════════════════════════════════════════════════════ -->
<div class="rdash-bottom">

  <!-- e) Exceeding Risk Appetite -->
  <div class="card">
    <div class="rdash-card-title">
      <i class="bi bi-speedometer2" style="color:var(--danger)"></i>
      Exceeding Risk Appetite
    </div>
    <?php if (empty($exceedingAppetite)): ?>
      <div class="rdash-empty-good">
        <i class="bi bi-shield-fill-check" style="font-size:20px"></i>
        All risks are within appetite thresholds.
      </div>
    <?php else: ?>
      <?php foreach ($exceedingAppetite as $ea):
        $sc  = (int)$ea['inherent_score'];
        $col = dashLevelColor($sc);
        $apt = isset($ea['appetite']) ? (int)$ea['appetite'] : null;
      ?>
      <div class="rdash-list-item">
        <span class="rdash-score-badge" style="background:<?= $col ?>20;color:<?= $col ?>;border:1.5px solid <?= $col ?>50;flex-shrink:0">
          <?= $sc ?>
        </span>
        <div style="flex:1;min-width:0">
          <a href="/risk/<?= (int)$ea['id'] ?>" style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= Security::h($ea['title']) ?>
          </a>
          <div style="font-size:10px;color:var(--text-muted)">
            <?= Security::h($ea['risk_id'] ?? '') ?>
            <?php if ($apt !== null): ?>
              · appetite max <?= $apt ?>
            <?php endif; ?>
            <?php if (!empty($ea['category_name'])): ?>
              · <?= Security::h($ea['category_name']) ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- f) Uncontrolled Risks -->
  <div class="card">
    <div class="rdash-card-title">
      <i class="bi bi-shield-x" style="color:var(--warning)"></i>
      Uncontrolled Risks
      <?php if ($uncontrolledCount > 0): ?>
        <span style="margin-left:auto;background:#fef3c7;color:var(--warning);font-size:11px;padding:2px 8px;border-radius:20px;border:1px solid #fde68a"><?= $uncontrolledCount ?> risks</span>
      <?php endif; ?>
    </div>
    <?php if (empty($uncontrolled)): ?>
      <div class="rdash-empty-good">
        <i class="bi bi-shield-fill-check" style="font-size:20px"></i>
        All risks have linked controls.
      </div>
    <?php else: ?>
      <?php foreach ($uncontrolled as $uc):
        $sc  = (int)$uc['inherent_score'];
        $col = dashLevelColor($sc);
      ?>
      <div class="rdash-list-item">
        <span class="rdash-score-badge" style="background:<?= $col ?>20;color:<?= $col ?>;border:1.5px solid <?= $col ?>50;flex-shrink:0">
          <?= $sc ?>
        </span>
        <div style="flex:1;min-width:0">
          <a href="/risk/<?= (int)$uc['id'] ?>" style="font-size:12px;font-weight:600;color:var(--primary);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= Security::h($uc['title']) ?>
          </a>
          <?php if (!empty($uc['category_name'])): ?>
            <div style="font-size:10px;color:var(--text-muted)"><?= Security::h($uc['category_name']) ?></div>
          <?php endif; ?>
        </div>
        <?php if (Auth::can('risk.edit')): ?>
        <a href="/risk/<?= (int)$uc['id'] ?>" style="flex-shrink:0;font-size:11px;font-weight:600;color:var(--primary);text-decoration:none;white-space:nowrap;padding:3px 8px;border-radius:6px;border:1px solid #c7d2fe;background:rgba(11,97,4,.06)">
          <i class="bi bi-link-45deg"></i> Link Controls
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- g) Action Backlog -->
  <div class="card">
    <div class="rdash-card-title">
      <i class="bi bi-lightning-fill" style="color:var(--primary)"></i>
      Action Backlog
    </div>
    <?php
    $abPlanned    = (int)($actionBacklog['planned']     ?? 0);
    $abInProgress = (int)($actionBacklog['in_progress'] ?? 0);
    $abCompleted  = (int)($actionBacklog['completed']   ?? 0);
    $abOverdue    = (int)($actionBacklog['overdue']     ?? 0);
    $abTotal      = $abPlanned + $abInProgress + $abCompleted + $abOverdue;
    $abTotal      = max($abTotal, 1); // avoid div-by-zero
    $bars = [
        ['label' => 'Planned',     'count' => $abPlanned,    'color' => 'var(--primary)', 'bg' => 'rgba(11,97,4,.06)'],
        ['label' => 'In Progress', 'count' => $abInProgress, 'color' => 'var(--orange)', 'bg' => 'var(--warning-subtle)'],
        ['label' => 'Completed',   'count' => $abCompleted,  'color' => 'var(--success)', 'bg' => 'var(--success-subtle)'],
        ['label' => 'Overdue',     'count' => $abOverdue,    'color' => 'var(--danger)', 'bg' => 'var(--danger-subtle)'],
    ];
    // Donut via conic-gradient
    $conicParts = [];
    $prev = 0;
    foreach ($bars as $b) {
        $pct = $abTotal > 0 ? round($b['count'] / $abTotal * 100, 1) : 0;
        if ($pct > 0) {
            $conicParts[] = $b['color'] . ' ' . $prev . '% ' . ($prev + $pct) . '%';
            $prev += $pct;
        }
    }
    $conicGradient = empty($conicParts) ? '#e4e4e7 0% 100%' : implode(', ', $conicParts);
    ?>

    <!-- Donut -->
    <div style="display:flex;align-items:center;justify-content:center;margin-bottom:20px">
      <div style="position:relative;width:120px;height:120px;flex-shrink:0">
        <div style="width:120px;height:120px;border-radius:50%;background:conic-gradient(<?= $conicGradient ?>)"></div>
        <div style="position:absolute;inset:18px;border-radius:50%;background:white;display:flex;flex-direction:column;align-items:center;justify-content:center">
          <span style="font-size:22px;font-weight:800;line-height:1"><?= ($abTotal === 1 && $abPlanned === 0 && $abInProgress === 0 && $abCompleted === 0 && $abOverdue === 0) ? 0 : ($abPlanned + $abInProgress + $abCompleted + $abOverdue) ?></span>
          <span style="font-size:10px;color:var(--text-muted);font-weight:600">actions</span>
        </div>
      </div>
    </div>

    <!-- Bars -->
    <div class="rdash-backlog-bars">
      <?php foreach ($bars as $b):
        $pct = $abTotal > 0 ? round($b['count'] / $abTotal * 100, 1) : 0;
      ?>
      <div class="rdash-bar-row">
        <span class="rdash-bar-label" style="color:<?= $b['color'] ?>;font-size:12px"><?= $b['label'] ?></span>
        <div class="rdash-bar-track">
          <div class="rdash-bar-fill" style="width:<?= $pct ?>%;background:<?= $b['color'] ?>"></div>
        </div>
        <span class="rdash-bar-count" style="font-size:12px;color:<?= $b['color'] ?>"><?= $b['count'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($abOverdue > 0): ?>
    <div style="margin-top:12px;display:flex;align-items:center;gap:6px;padding:8px 10px;background:var(--danger-subtle);border:1px solid var(--danger-border);border-radius:8px;font-size:12px;font-weight:600;color:var(--danger)">
      <i class="bi bi-exclamation-circle-fill"></i>
      <?= $abOverdue ?> action<?= $abOverdue !== 1 ? 's' : '' ?> overdue — immediate attention required.
    </div>
    <?php endif; ?>
  </div>

</div><!-- /rdash-bottom -->


<!-- ════════════════════════════════════════════════════
     RECENT CHANGES STRIP
     ════════════════════════════════════════════════════ -->
<?php if (!empty($recentChanges)): ?>
<div class="card" style="margin-bottom:20px">
  <div class="rdash-card-title">
    <i class="bi bi-clock-history" style="color:var(--primary)"></i>
    Recent Score Changes
  </div>
  <div class="rdash-changes-strip">
    <?php foreach ($recentChanges as $rc):
      $rcScore  = (int)($rc['score'] ?? 0);
      $rcCol    = dashLevelColor($rcScore);
      $rcTs     = strtotime((string)($rc['created_at'] ?? ''));
      $rcDiff   = time() - $rcTs;
      if ($rcDiff < 60)           $rcAgo = 'just now';
      elseif ($rcDiff < 3600)     $rcAgo = floor($rcDiff/60).'m ago';
      elseif ($rcDiff < 86400)    $rcAgo = floor($rcDiff/3600).'h ago';
      elseif ($rcDiff < 604800)   $rcAgo = floor($rcDiff/86400).'d ago';
      else                        $rcAgo = date('M j', $rcTs);
    ?>
    <div class="rdash-change-card">
      <div class="rcc-id"><?= Security::h($rc['risk_id'] ?? '—') ?></div>
      <div class="rcc-title" title="<?= htmlspecialchars($rc['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <?= Security::h($rc['title'] ?? '—') ?>
      </div>
      <div class="rcc-score">
        <span class="rdash-score-badge" style="background:<?= $rcCol ?>20;color:<?= $rcCol ?>;border:1.5px solid <?= $rcCol ?>50;font-size:13px">
          <?= $rcScore ?>
        </span>
        <?php if (!empty($rc['note'])): ?>
          <span style="font-size:10px;color:var(--text-muted);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($rc['note'], ENT_QUOTES, 'UTF-8') ?>">
            <?= Security::h($rc['note']) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="rcc-meta">
        <i class="bi bi-person-fill"></i> <?= Security::h($rc['changed_by_name'] ?? '—') ?>
        · <?= Security::h($rcAgo) ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════
     CANVAS TREND CHART (vanilla JS)
     ════════════════════════════════════════════════════ -->
<?php if (!empty($trendData)): ?>
<script nonce="<?= Security::nonce() ?>">
(function() {
  var data = <?= $trendJson ?>;
  if (!data || !data.length) return;

  var canvas = document.getElementById('rdash-trend-canvas');
  if (!canvas) return;

  // Responsive: match CSS width
  function draw() {
    var W = canvas.parentElement.clientWidth || 600;
    var H = 400;
    canvas.width  = W;
    canvas.height = H;

    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, W, H);

    var PAD_L = 44, PAD_R = 24, PAD_T = 20, PAD_B = 44;
    var chartW = W - PAD_L - PAD_R;
    var chartH = H - PAD_T - PAD_B;
    var maxY = 25;

    // ── Background bands (risk zones) ──────────────────
    function yPos(val) { return PAD_T + chartH * (1 - val / maxY); }
    function xPos(i)   { return PAD_L + (data.length > 1 ? i / (data.length - 1) : 0.5) * chartW; }

    var bands = [
      { from: 0,  to: 4,  color: 'rgba(34,197,94,0.10)'  },
      { from: 4,  to: 9,  color: 'rgba(245,158,11,0.10)' },
      { from: 9,  to: 14, color: 'rgba(249,115,22,0.10)' },
      { from: 14, to: 25, color: 'rgba(239,68,68,0.10)'  },
    ];
    bands.forEach(function(b) {
      var y1 = yPos(b.to);
      var y2 = yPos(b.from);
      ctx.fillStyle = b.color;
      ctx.fillRect(PAD_L, y1, chartW, y2 - y1);
    });

    // ── Grid lines ──────────────────────────────────────
    ctx.strokeStyle = 'rgba(100,116,139,0.12)';
    ctx.lineWidth = 1;
    [0, 5, 10, 15, 20, 25].forEach(function(v) {
      var y = yPos(v);
      ctx.beginPath();
      ctx.moveTo(PAD_L, y);
      ctx.lineTo(PAD_L + chartW, y);
      ctx.stroke();
      // Y label
      ctx.fillStyle = '#a1a1aa';
      ctx.font = '10px Inter, sans-serif';
      ctx.textAlign = 'right';
      ctx.fillText(v, PAD_L - 6, y + 4);
    });

    // ── Fill area under line ────────────────────────────
    if (data.length > 1) {
      ctx.beginPath();
      ctx.moveTo(xPos(0), yPos(data[0].avg));
      for (var i = 1; i < data.length; i++) {
        ctx.lineTo(xPos(i), yPos(data[i].avg));
      }
      ctx.lineTo(xPos(data.length - 1), yPos(0));
      ctx.lineTo(xPos(0), yPos(0));
      ctx.closePath();
      var grad = ctx.createLinearGradient(0, PAD_T, 0, H - PAD_B);
      grad.addColorStop(0,   'rgba(99,102,241,0.25)');
      grad.addColorStop(1,   'rgba(99,102,241,0.02)');
      ctx.fillStyle = grad;
      ctx.fill();
    }

    // ── Line ────────────────────────────────────────────
    ctx.beginPath();
    ctx.lineWidth = 2.5;
    ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || 'var(--primary)';
    ctx.lineJoin = 'round';
    data.forEach(function(pt, i) {
      if (i === 0) ctx.moveTo(xPos(i), yPos(pt.avg));
      else         ctx.lineTo(xPos(i), yPos(pt.avg));
    });
    ctx.stroke();

    // ── Circles + tooltips (hover via mousemove) ────────
    var dotR = 5;
    data.forEach(function(pt, i) {
      var cx = xPos(i), cy = yPos(pt.avg);
      var lvlColor = pt.avg > 14 ? 'var(--danger)' : (pt.avg > 9 ? 'var(--orange)' : (pt.avg > 4 ? 'var(--warning)' : 'var(--success)'));
      ctx.beginPath();
      ctx.arc(cx, cy, dotR, 0, Math.PI * 2);
      ctx.fillStyle   = '#ffffff';
      ctx.fill();
      ctx.strokeStyle = lvlColor;
      ctx.lineWidth   = 2.5;
      ctx.stroke();
    });

    // ── X axis labels (week Mon DD) ─────────────────────
    ctx.fillStyle  = '#a1a1aa';
    ctx.font       = '10px Inter, sans-serif';
    ctx.textAlign  = 'center';
    var step = data.length > 8 ? Math.ceil(data.length / 8) : 1;
    data.forEach(function(pt, i) {
      if (i % step !== 0 && i !== data.length - 1) return;
      var d = new Date(pt.week);
      // Format: Mon DD
      var lbl = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getUTCDay()]
                + ' ' + d.getUTCDate();
      ctx.fillText(lbl, xPos(i), H - PAD_B + 16);
    });

    // ── Hover tooltip ───────────────────────────────────
    canvas._data = data;
    canvas._xPos = xPos;
    canvas._yPos = yPos;
    canvas._dotR = dotR;
  }

  draw();
  window.addEventListener('resize', draw);

  // Tooltip overlay
  var tooltip = document.createElement('div');
  tooltip.style.cssText = 'position:absolute;pointer-events:none;display:none;background:#111111;color:#f9fafb;font-size:11px;padding:6px 10px;border-radius:7px;z-index:50;line-height:1.5;white-space:nowrap;font-family:Inter,sans-serif';
  canvas.parentElement.style.position = 'relative';
  canvas.parentElement.appendChild(tooltip);

  canvas.addEventListener('mousemove', function(e) {
    var rect = canvas.getBoundingClientRect();
    var mx   = (e.clientX - rect.left) * (canvas.width / rect.width);
    var my   = (e.clientY - rect.top)  * (canvas.height / rect.height);
    var data2 = canvas._data;
    var found = false;
    if (!data2) return;
    for (var i = 0; i < data2.length; i++) {
      var cx = canvas._xPos(i);
      var cy = canvas._yPos(data2[i].avg);
      if (Math.abs(mx - cx) < 18 && Math.abs(my - cy) < 18) {
        var d = new Date(data2[i].week);
        var mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getUTCMonth()];
        tooltip.innerHTML = '<strong>Week of ' + mon + ' ' + d.getUTCDate() + '</strong><br>'
          + 'Avg Score: <strong>' + data2[i].avg + '</strong><br>'
          + 'Max Score: ' + data2[i].max + '<br>'
          + 'Risk Count: ' + data2[i].count;
        tooltip.style.display = 'block';
        // position relative to canvas parent
        var parentRect = canvas.parentElement.getBoundingClientRect();
        var tx = e.clientX - parentRect.left + 12;
        var ty = e.clientY - parentRect.top  - 60;
        tooltip.style.left = tx + 'px';
        tooltip.style.top  = ty + 'px';
        found = true;
        break;
      }
    }
    if (!found) tooltip.style.display = 'none';
  });
  canvas.addEventListener('mouseleave', function() { tooltip.style.display = 'none'; });
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
