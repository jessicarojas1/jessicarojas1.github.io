<?php
// Board Pack view
// Variables provided by ReportController::board():
//   $riskSummary, $topRisks, $compliance, $riskTrend, $incidentSummary,
//   $upcomingReviews, $appetiteBreaches, $treatmentBacklog, $kriHealth,
//   $reportDate, $orgName, $asOf

$rs = $riskSummary ?? [];
$totalRisks       = (int)($rs['total']          ?? 0);
$criticalRisks    = (int)($rs['critical']        ?? 0);
$highRisks        = (int)($rs['high']            ?? 0);
$acceptedRisks    = (int)($rs['accepted']        ?? 0);
$overdueReview    = (int)($rs['overdue_review']  ?? 0);

$compliance       = $compliance       ?? [];
$topRisks         = $topRisks         ?? [];
$riskTrend        = $riskTrend        ?? [];
$incidentSummary  = $incidentSummary  ?? [];
$upcomingReviews  = $upcomingReviews  ?? [];
$appetiteBreaches = $appetiteBreaches ?? [];
$treatmentBacklog = $treatmentBacklog ?? [];
$kriHealth        = $kriHealth        ?? [];

$tb = $treatmentBacklog;
$tbTotal    = (int)($tb['total']       ?? 0);
$tbPlanned  = (int)($tb['planned']     ?? 0);
$tbInProg   = (int)($tb['in_progress'] ?? 0);
$tbOverdue  = (int)($tb['overdue']     ?? 0);

$is = $incidentSummary;
$incTotal    = (int)($is['total']        ?? 0);
$incOpen     = (int)($is['open']         ?? 0);
$incHighSev  = (int)($is['high_severity']?? 0);
$incLast30   = (int)($is['last_30_days'] ?? 0);

// Compliance average
$complianceAvg = 0;
if (count($compliance) > 0) {
    $complianceAvg = (int)round(array_sum(array_column($compliance, 'pct')) / count($compliance));
}

// Trend chart data (12-week)
$trendWeeks  = [];
$trendScores = [];
foreach ($riskTrend as $t) {
    $trendWeeks[]  = date('M d', strtotime($t['week'] ?? ''));
    $trendScores[] = (float)($t['avg_score'] ?? 0);
}

$nonce = Security::nonce();
?>
<style nonce="<?= $nonce ?>">
/* ── Board Pack Styles ───────────────────────────────────────────────────── */
.bp-cover {
  background: linear-gradient(135deg, #010409 0%, #0a1f0e 55%, #010b07 100%);
  color: #fff;
  border-radius: 16px;
  padding: 48px 40px 40px;
  margin-bottom: 32px;
  position: relative;
  overflow: hidden;
}
.bp-cover::before {
  content: '';
  position: absolute;
  inset: 0;
  background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.bp-cover-inner { position: relative; z-index: 1; }
.bp-confidential {
  display: inline-block;
  border: 2px solid rgba(255,255,255,.4);
  color: rgba(255,255,255,.8);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .2em;
  text-transform: uppercase;
  padding: 3px 12px;
  border-radius: 4px;
  margin-bottom: 20px;
}
.bp-cover h1 {
  font-size: 36px;
  font-weight: 800;
  margin: 0 0 8px;
  line-height: 1.1;
}
.bp-cover .bp-subtitle {
  font-size: 16px;
  opacity: .75;
  margin: 0 0 24px;
}
.bp-cover .bp-meta {
  font-size: 13px;
  opacity: .6;
  display: flex;
  gap: 24px;
  flex-wrap: wrap;
}
.bp-cover .bp-logo {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 28px;
}
.bp-cover .bp-logo-icon {
  width: 48px; height: 48px;
  background: rgba(255,255,255,.15);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 24px;
}
.bp-cover .bp-logo-text { font-size: 22px; font-weight: 800; letter-spacing: -.01em; }
.bp-cover .bp-logo-sub  { font-size: 11px; opacity: .6; letter-spacing: .08em; text-transform: uppercase; }

/* Section headers */
.bp-section {
  margin-bottom: 32px;
  page-break-inside: avoid;
}
.bp-section-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  background: var(--primary);
  color: #fff;
  border-radius: 10px 10px 0 0;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: .03em;
  text-transform: uppercase;
}
.bp-section-header i { font-size: 16px; }
.bp-section-body {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-top: none;
  border-radius: 0 0 10px 10px;
  padding: 20px;
  color: var(--text);
}

/* Summary stat cards */
.bp-stat-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 12px;
  margin-bottom: 32px;
}
@media (max-width: 1100px) { .bp-stat-grid { grid-template-columns: repeat(3,1fr); } }
@media (max-width: 640px)  { .bp-stat-grid { grid-template-columns: repeat(2,1fr); } }

.bp-stat {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 20px 16px;
  text-align: center;
  box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.bp-stat .stat-val {
  font-size: 32px;
  font-weight: 800;
  line-height: 1;
  margin: 8px 0 6px;
  display: block;
}
.bp-stat .stat-lbl {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: var(--text-muted);
}
.bp-stat i { font-size: 22px; }

/* Score badge */
.score-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 32px; height: 32px;
  border-radius: 8px;
  font-weight: 800;
  font-size: 13px;
  padding: 0 6px;
}

/* RAG dot */
.rag-dot {
  display: inline-block;
  width: 10px; height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

/* Progress bar */
.cp-bar-wrap {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.cp-bar-label { font-size: 13px; font-weight: 600; min-width: 160px; }
.cp-bar-track {
  flex: 1;
  height: 12px;
  background: var(--bg-secondary);
  border-radius: 99px;
  overflow: hidden;
}
.cp-bar-fill {
  height: 100%;
  border-radius: 99px;
  transition: width .4s ease;
}
.cp-bar-pct { font-size: 13px; font-weight: 700; min-width: 38px; text-align: right; }

/* BP print button */
.bp-print-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--primary);
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 9px 18px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
}
.bp-print-btn:hover { background: var(--primary); }

/* Page footer (print only) */
.bp-footer {
  display: none;
  text-align: center;
  font-size: 11px;
  color: #a1a1aa;
  border-top: 1px solid #e4e4e7;
  padding-top: 12px;
  margin-top: 40px;
}

/* ── Dark mode overrides ─────────────────────────────────────────────────── */
html[data-theme="dark"] .bp-stat {
  background: var(--card-bg);
  border-color: var(--border);
}
html[data-theme="dark"] .bp-stat .stat-lbl { color: var(--text-muted); }
html[data-theme="dark"] .bp-section-body {
  background: var(--card-bg);
  border-color: var(--border);
  color: var(--text);
}
html[data-theme="dark"] .bp-section-body table thead tr {
  background: var(--bg-secondary) !important;
}
html[data-theme="dark"] .bp-section-body table thead th {
  background: var(--bg-secondary) !important;
  color: var(--text-muted) !important;
  border-color: var(--border) !important;
}
html[data-theme="dark"] .bp-section-body table tr {
  border-color: var(--border) !important;
}
html[data-theme="dark"] .bp-section-body table td { color: var(--text); }
html[data-theme="dark"] .cp-bar-track { background: var(--bg-secondary); }
html[data-theme="dark"] .cp-bar-label { color: var(--text); }
html[data-theme="dark"] .cp-bar-label div:last-child { color: var(--text-muted); }
/* Incident / Treatment mini-stat cards */
html[data-theme="dark"] .bp-section-body > div > div[style*="background:#f8fafc"] {
  background: var(--bg-secondary) !important;
  border-color: var(--border) !important;
}
html[data-theme="dark"] a[style*="color:#1e293b"] { color: var(--text) !important; }

/* ── Print ───────────────────────────────────────────────────────────────── */
@media print {
  .sidebar, .topbar, .bottom-nav, .page-actions,
  .alert-panel, .alert-overlay, .sidebar-toggle,
  .btn-logout, .alert-bell, .bp-print-btn { display: none !important; }
  .main-content  { margin: 0 !important; padding: 0 !important; }
  .page-content  { padding: 0 !important; }
  .page-header   { display: none !important; }
  body           { font-size: 11px; background: #fff; }
  .bp-cover      { border-radius: 0; margin: 0 0 24px; }
  .bp-section    { page-break-before: always; }
  .bp-section:first-of-type { page-break-before: auto; }
  .bp-stat-grid  { grid-template-columns: repeat(6,1fr); gap: 8px; }
  .bp-stat       { box-shadow: none; border: 1px solid #d4d4d8; padding: 12px 8px; }
  .bp-stat .stat-val { font-size: 24px; }
  .card          { box-shadow: none !important; border: 1px solid #e4e4e7 !important; }
  .bp-footer     { display: block !important; }
  canvas         { max-width: 100% !important; }
  a              { color: inherit !important; text-decoration: none !important; }
}
</style>

<!-- Page header (screen only) -->
<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-briefcase-fill" style="margin-right:8px;color:var(--primary);"></i><?= Security::h($pageTitle) ?></h1>
    <p class="page-subtitle"><?= Security::h($orgName) ?> &mdash; <?= Security::h($reportDate) ?></p>
  </div>
  <div class="page-actions">
    <a href="/report/risk-detail" class="btn btn-ghost btn-sm"><i class="bi bi-table"></i> Risk Register Report</a>
    <button class="bp-print-btn" data-print><i class="bi bi-printer"></i> Print / Export PDF</button>
  </div>
</div>

<!-- ── Cover ─────────────────────────────────────────────────────────────── -->
<div class="bp-cover">
  <div class="bp-cover-inner">
    <div class="bp-logo">
      <div class="bp-logo-icon"><i class="bi bi-shield-fill-check"></i></div>
      <div>
        <div class="bp-logo-text">AEGIS GRC</div>
        <div class="bp-logo-sub">Governance · Risk · Compliance</div>
      </div>
    </div>
    <div class="bp-confidential">Confidential</div>
    <h1>Board Risk Report</h1>
    <p class="bp-subtitle"><?= Security::h($orgName) ?></p>
    <div class="bp-meta">
      <span><i class="bi bi-calendar3"></i> As of <?= Security::h($reportDate) ?></span>
      <span><i class="bi bi-clock"></i> Generated <?= date('g:i A T') ?></span>
    </div>
  </div>
</div>

<!-- ── Executive Summary Cards ────────────────────────────────────────────── -->
<div class="bp-stat-grid">

  <div class="bp-stat" style="border-top:4px solid var(--primary);">
    <i class="bi bi-list-check" style="color:var(--primary);"></i>
    <span class="stat-val" style="color:var(--primary);"><?= $totalRisks ?></span>
    <div class="stat-lbl">Total Risks</div>
  </div>

  <div class="bp-stat" style="border-top:4px solid #dc2626;">
    <i class="bi bi-exclamation-octagon-fill" style="color:#dc2626;"></i>
    <span class="stat-val" style="color:#dc2626;"><?= $criticalRisks ?></span>
    <div class="stat-lbl">Critical</div>
  </div>

  <div class="bp-stat" style="border-top:4px solid #f97316;">
    <i class="bi bi-exclamation-triangle-fill" style="color:#f97316;"></i>
    <span class="stat-val" style="color:#f97316;"><?= $highRisks ?></span>
    <div class="stat-lbl">High</div>
  </div>

  <div class="bp-stat" style="border-top:4px solid var(--secondary);">
    <i class="bi bi-shield-slash-fill" style="color:var(--secondary);"></i>
    <span class="stat-val" style="color:var(--secondary);"><?= count($appetiteBreaches) ?></span>
    <div class="stat-lbl">Appetite Breaches</div>
  </div>

  <div class="bp-stat" style="border-top:4px solid #d97706;">
    <i class="bi bi-tools" style="color:#d97706;"></i>
    <span class="stat-val" style="color:#d97706;"><?= $tbOverdue ?></span>
    <div class="stat-lbl">Overdue Treatments</div>
  </div>

  <div class="bp-stat" style="border-top:4px solid #059669;">
    <i class="bi bi-check2-circle" style="color:#059669;"></i>
    <span class="stat-val" style="color:#059669;"><?= $complianceAvg ?>%</span>
    <div class="stat-lbl">Compliance Avg</div>
  </div>

</div>

<!-- ── Risk Landscape ─────────────────────────────────────────────────────── -->
<div class="bp-section">
  <div class="bp-section-header"><i class="bi bi-diagram-3-fill"></i> Risk Landscape — Top <?= count($topRisks) ?> Open Risks</div>
  <div class="bp-section-body" style="padding:0;">
    <?php if ($topRisks): ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:var(--bg-secondary);">
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Risk ID</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Title</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Category</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Score</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Owner</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Strategy</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Review Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topRisks as $r):
          $sc = (int)($r['inherent_score'] ?? 0);
          if ($sc > 20)       { $sc_bg = '#fef2f2'; $sc_cl = '#dc2626'; $level = 'CRITICAL'; }
          elseif ($sc > 14)   { $sc_bg = '#fff7ed'; $sc_cl = '#ea580c'; $level = 'CRITICAL'; }
          elseif ($sc >= 10)  { $sc_bg = '#fffbeb'; $sc_cl = '#d97706'; $level = 'HIGH'; }
          else                { $sc_bg = '#f0fdf4'; $sc_cl = '#16a34a'; $level = 'MEDIUM'; }

          $strategy = Security::h($r['treatment_strategy'] ?? $r['strategy'] ?? '—');
          $reviewDt = $r['review_date'] ? date('j M Y', strtotime($r['review_date'])) : '—';
          $isOverdue = $r['review_date'] && strtotime($r['review_date']) < time();
        ?>
        <tr style="border-bottom:1px solid #f4f4f5;">
          <td style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--primary);white-space:nowrap;"><?= Security::h($r['risk_id'] ?? '#' . $r['id']) ?></td>
          <td style="padding:10px 12px;font-weight:500;max-width:220px;">
            <a href="/risk/<?= (int)$r['id'] ?>" style="color:var(--text);text-decoration:none;"><?= Security::h($r['title']) ?></a>
          </td>
          <td style="padding:10px 12px;color:var(--text-muted);font-size:12px;"><?= Security::h($r['category_name'] ?? '—') ?></td>
          <td style="padding:10px 12px;text-align:center;">
            <span class="score-badge" style="background:<?= $sc_bg ?>;color:<?= $sc_cl ?>;"><?= $sc ?></span>
          </td>
          <td style="padding:10px 12px;font-size:12px;white-space:nowrap;"><?= Security::h($r['owner_name'] ?? '—') ?></td>
          <td style="padding:10px 12px;">
            <?php if ($strategy && $strategy !== '—'): ?>
            <span style="display:inline-block;background:rgba(55,65,81,.08);color:var(--secondary);font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;"><?= $strategy ?></span>
            <?php else: ?>
            <span style="color:#a1a1aa;font-size:12px;">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px;font-size:12px;white-space:nowrap;color:<?= $isOverdue ? '#dc2626' : '#52525b' ?>;">
            <?= $reviewDt ?>
            <?php if ($isOverdue): ?><i class="bi bi-exclamation-circle-fill" style="color:#dc2626;margin-left:4px;"></i><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="text-align:center;color:#a1a1aa;padding:24px;">No critical or high risks found.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ── Risk Score Trend ────────────────────────────────────────────────────── -->
<div class="bp-section">
  <div class="bp-section-header"><i class="bi bi-graph-up-arrow"></i> Risk Score Trend — Last 12 Weeks</div>
  <div class="bp-section-body">
    <?php if ($riskTrend): ?>
    <canvas id="bpTrendChart" height="280" style="max-width:100%;display:block;"></canvas>
    <?php else: ?>
    <p style="text-align:center;color:#a1a1aa;padding:32px 0;">No trend data available. Risk score history will appear here once recorded.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ── Compliance Status ───────────────────────────────────────────────────── -->
<div class="bp-section">
  <div class="bp-section-header"><i class="bi bi-bar-chart-steps"></i> Compliance Status by Framework</div>
  <div class="bp-section-body">
    <?php if ($compliance): foreach ($compliance as $cp):
      $pct = min(100, max(0, (int)($cp['pct'] ?? 0)));
      if ($pct >= 80)      { $bar_cl = '#059669'; }
      elseif ($pct >= 60)  { $bar_cl = '#d97706'; }
      else                  { $bar_cl = '#dc2626'; }
    ?>
    <div class="cp-bar-wrap">
      <div class="cp-bar-label">
        <div style="font-weight:600;"><?= Security::h($cp['name']) ?></div>
        <?php if (!empty($cp['standard'])): ?>
        <div style="font-size:11px;color:#a1a1aa;"><?= Security::h($cp['standard']) ?></div>
        <?php endif; ?>
      </div>
      <div class="cp-bar-track">
        <div class="cp-bar-fill" style="width:<?= $pct ?>%;background:<?= $bar_cl ?>;"></div>
      </div>
      <div class="cp-bar-pct" style="color:<?= $bar_cl ?>;"><?= $pct ?>%</div>
      <div style="font-size:11px;color:#a1a1aa;min-width:100px;text-align:right;">
        <?= (int)($cp['compliant'] ?? 0) ?>/<?= (int)($cp['total_controls'] ?? 0) ?> controls
      </div>
    </div>
    <?php endforeach; else: ?>
    <p style="text-align:center;color:#a1a1aa;padding:24px;">No compliance packages configured.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ── Risk Appetite ──────────────────────────────────────────────────────── -->
<div class="bp-section">
  <div class="bp-section-header"><i class="bi bi-shield-slash-fill"></i> Risk Appetite Breaches</div>
  <div class="bp-section-body" style="padding:0;">
    <?php if ($appetiteBreaches): ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:var(--bg-secondary);">
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Risk</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Category</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Score</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Max Appetite</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Breach</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Appetite Statement</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($appetiteBreaches as $ab):
          $breach = (int)($ab['inherent_score'] ?? 0) - (int)($ab['max_score'] ?? 0);
        ?>
        <tr style="border-bottom:1px solid #f4f4f5;">
          <td style="padding:10px 12px;font-weight:500;"><?= Security::h($ab['title']) ?></td>
          <td style="padding:10px 12px;color:var(--text-muted);font-size:12px;"><?= Security::h($ab['category_name'] ?? '—') ?></td>
          <td style="padding:10px 12px;text-align:center;">
            <span class="score-badge" style="background:#fef2f2;color:#dc2626;"><?= (int)($ab['inherent_score'] ?? 0) ?></span>
          </td>
          <td style="padding:10px 12px;text-align:center;font-weight:600;color:var(--text-muted);"><?= (int)($ab['max_score'] ?? 0) ?></td>
          <td style="padding:10px 12px;text-align:center;">
            <span style="display:inline-block;background:#fef2f2;color:#dc2626;font-weight:700;font-size:12px;padding:2px 10px;border-radius:99px;">+<?= $breach ?></span>
          </td>
          <td style="padding:10px 12px;color:var(--text-muted);font-size:12px;"><?= Security::h($ab['appetite'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="text-align:center;color:#059669;padding:24px;"><i class="bi bi-check-circle-fill"></i> No risk appetite breaches detected.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ── Incident Overview ───────────────────────────────────────────────────── -->
<div class="bp-section">
  <div class="bp-section-header"><i class="bi bi-fire"></i> Incident Overview</div>
  <div class="bp-section-body">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
      <div style="text-align:center;padding:16px;background:var(--bg-secondary);border-radius:10px;border:1px solid var(--border);">
        <div style="font-size:28px;font-weight:800;color:#6366f1;"><?= $incTotal ?></div>
        <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Total Incidents</div>
      </div>
      <div style="text-align:center;padding:16px;background:var(--bg-secondary);border-radius:10px;border:1px solid var(--border);">

        <div style="font-size:28px;font-weight:800;color:#f97316;"><?= $incOpen ?></div>
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">Open</div>
      </div>
      <div style="text-align:center;padding:16px;background:var(--bg-secondary);border-radius:10px;border:1px solid var(--border);">
        <div style="font-size:28px;font-weight:800;color:#dc2626;"><?= $incHighSev ?></div>
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">High Severity</div>
      </div>
      <div style="text-align:center;padding:16px;background:var(--bg-secondary);border-radius:10px;border:1px solid var(--border);">
        <div style="font-size:28px;font-weight:800;color:#d97706;"><?= $incLast30 ?></div>
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">Last 30 Days</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Treatment Backlog ───────────────────────────────────────────────────── -->
<div class="bp-section">
  <div class="bp-section-header"><i class="bi bi-tools"></i> Treatment Action Backlog</div>
  <div class="bp-section-body">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
      <div style="text-align:center;padding:16px;background:var(--bg-secondary);border-radius:10px;border:1px solid var(--border);">
        <div style="font-size:28px;font-weight:800;color:#6366f1;"><?= $tbTotal ?></div>
        <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Total Actions</div>
      </div>
      <div style="text-align:center;padding:16px;background:var(--bg-secondary);border-radius:10px;border:1px solid var(--border);">
        <div style="font-size:28px;font-weight:800;color:#64748b;"><?= $tbPlanned ?></div>
        <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Planned</div>
      </div>
      <div style="text-align:center;padding:16px;background:var(--bg-secondary);border-radius:10px;border:1px solid var(--border);">

        <div style="font-size:28px;font-weight:800;color:#d97706;"><?= $tbInProg ?></div>
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">In Progress</div>
      </div>
      <div style="text-align:center;padding:16px;background:#f9fafb;border-radius:10px;border:1px solid <?= $tbOverdue > 0 ? '#dc2626' : '#e4e4e7' ?>;">
        <div style="font-size:28px;font-weight:800;color:<?= $tbOverdue > 0 ? '#dc2626' : '#059669' ?>;"><?= $tbOverdue ?></div>
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;">Overdue</div>
      </div>
    </div>
  </div>
</div>

<!-- ── KRI Health ─────────────────────────────────────────────────────────── -->
<div class="bp-section">
  <div class="bp-section-header"><i class="bi bi-activity"></i> Key Risk Indicator (KRI) Health</div>
  <div class="bp-section-body" style="padding:0;">
    <?php if ($kriHealth): ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:var(--bg-secondary);">
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">KRI</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Linked Risk</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Latest Value</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Amber Threshold</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Red Threshold</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">RAG</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Recorded</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($kriHealth as $kri):
          $val     = $kri['latest_value'];
          $amber   = $kri['threshold_amber'];
          $red     = $kri['threshold_red'];
          if ($val === null) {
              $ragColor = '#a1a1aa'; $ragLabel = 'N/A';
          } elseif ($red !== null && (float)$val >= (float)$red) {
              $ragColor = '#dc2626'; $ragLabel = 'RED';
          } elseif ($amber !== null && (float)$val >= (float)$amber) {
              $ragColor = '#d97706'; $ragLabel = 'AMBER';
          } else {
              $ragColor = '#059669'; $ragLabel = 'GREEN';
          }
          $recDate = $kri['latest_date'] ? date('j M Y', strtotime($kri['latest_date'])) : '—';
        ?>
        <tr style="border-bottom:1px solid #f4f4f5;">
          <td style="padding:10px 12px;font-weight:500;"><?= Security::h($kri['title']) ?></td>
          <td style="padding:10px 12px;color:var(--text-muted);font-size:12px;"><?= Security::h($kri['risk_title'] ?? '—') ?></td>
          <td style="padding:10px 12px;text-align:center;font-weight:700;">
            <?= $val !== null ? Security::h((string)$val) . ($kri['unit'] ? ' ' . Security::h($kri['unit']) : '') : '—' ?>
          </td>
          <td style="padding:10px 12px;text-align:center;color:#d97706;font-weight:600;"><?= $amber !== null ? Security::h((string)$amber) : '—' ?></td>
          <td style="padding:10px 12px;text-align:center;color:#dc2626;font-weight:600;"><?= $red !== null ? Security::h((string)$red) : '—' ?></td>
          <td style="padding:10px 12px;text-align:center;">
            <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $ragColor ?>18;color:<?= $ragColor ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;">
              <span class="rag-dot" style="background:<?= $ragColor ?>;"></span><?= $ragLabel ?>
            </span>
          </td>
          <td style="padding:10px 12px;font-size:12px;color:var(--text-muted);"><?= $recDate ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="text-align:center;color:#a1a1aa;padding:24px;">No active KRIs configured.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ── Upcoming Reviews ────────────────────────────────────────────────────── -->
<div class="bp-section">
  <div class="bp-section-header"><i class="bi bi-calendar-check-fill"></i> Upcoming Risk Reviews (Next 30 Days)</div>
  <div class="bp-section-body" style="padding:0;">
    <?php if ($upcomingReviews): ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="background:var(--bg-secondary);">
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Risk ID</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Title</th>
          <th style="padding:10px 12px;text-align:center;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Score</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Owner</th>
          <th style="padding:10px 12px;text-align:left;font-weight:700;color:var(--text-muted);border-bottom:1px solid var(--border);">Review Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($upcomingReviews as $ur):
          $sc2 = (int)($ur['inherent_score'] ?? 0);
          $sc2_cl = $sc2 > 14 ? '#dc2626' : ($sc2 >= 10 ? '#d97706' : '#71717a');
        ?>
        <tr style="border-bottom:1px solid #f4f4f5;">
          <td style="padding:10px 12px;font-size:11px;font-weight:700;color:var(--primary);"><?= Security::h($ur['risk_id'] ?? '—') ?></td>
          <td style="padding:10px 12px;font-weight:500;"><?= Security::h($ur['title']) ?></td>
          <td style="padding:10px 12px;text-align:center;">
            <span class="score-badge" style="background:<?= $sc2_cl ?>18;color:<?= $sc2_cl ?>;"><?= $sc2 ?></span>
          </td>
          <td style="padding:10px 12px;font-size:12px;"><?= Security::h($ur['owner_name'] ?? '—') ?></td>
          <td style="padding:10px 12px;font-size:12px;font-weight:600;color:#059669;"><?= date('j M Y', strtotime($ur['review_date'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="text-align:center;color:#a1a1aa;padding:24px;">No risk reviews due in the next 30 days.</p>
    <?php endif; ?>
  </div>
</div>

<!-- ── Page Footer ─────────────────────────────────────────────────────────── -->
<div class="bp-footer">
  Generated by AEGIS GRC &middot; <?= Security::h($reportDate) ?> &middot; CONFIDENTIAL
</div>

<?php if ($riskTrend): ?>
<script nonce="<?= $nonce ?>">
(function () {
  'use strict';
  var canvas = document.getElementById('bpTrendChart');
  if (!canvas) return;

  var labels = <?= json_encode(array_values($trendWeeks), JSON_UNESCAPED_UNICODE) ?>;
  var scores = <?= json_encode(array_values($trendScores)) ?>;

  var ctx = canvas.getContext('2d');
  var W = canvas.parentElement.clientWidth || 800;
  canvas.width  = W;
  canvas.height = 280;

  var padL = 52, padR = 20, padT = 20, padB = 60;
  var chartW = W - padL - padR;
  var chartH = canvas.height - padT - padB;

  var maxVal = Math.max.apply(null, scores.concat([1]));
  var minVal = Math.min.apply(null, scores.concat([0]));
  var range  = maxVal - minVal || 1;

  function xPos(i) { return padL + (i / Math.max(labels.length - 1, 1)) * chartW; }
  function yPos(v) { return padT + chartH - ((v - minVal) / range) * chartH; }

  // Grid lines
  ctx.strokeStyle = 'rgba(0,0,0,.05)';
  ctx.lineWidth = 1;
  var steps = 5;
  for (var s = 0; s <= steps; s++) {
    var yg = padT + (s / steps) * chartH;
    ctx.beginPath(); ctx.moveTo(padL, yg); ctx.lineTo(padL + chartW, yg); ctx.stroke();
    var label = (maxVal - (s / steps) * range).toFixed(1);
    ctx.fillStyle = '#a1a1aa';
    ctx.font = '11px Inter, sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText(label, padL - 6, yg + 4);
  }

  // Gradient fill
  var grad = ctx.createLinearGradient(0, padT, 0, padT + chartH);
  grad.addColorStop(0, 'rgba(99,102,241,.18)');
  grad.addColorStop(1, 'rgba(99,102,241,0)');

  ctx.beginPath();
  ctx.moveTo(xPos(0), yPos(scores[0]));
  for (var i = 1; i < scores.length; i++) {
    var x0 = xPos(i-1), y0 = yPos(scores[i-1]);
    var x1 = xPos(i),   y1 = yPos(scores[i]);
    var cpx = (x0 + x1) / 2;
    ctx.bezierCurveTo(cpx, y0, cpx, y1, x1, y1);
  }
  ctx.lineTo(xPos(scores.length - 1), padT + chartH);
  ctx.lineTo(xPos(0), padT + chartH);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  // Line
  ctx.beginPath();
  var primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#16a34a';
  ctx.strokeStyle = primaryColor;
  ctx.lineWidth = 2.5;
  ctx.lineJoin = 'round';
  ctx.lineCap  = 'round';
  ctx.moveTo(xPos(0), yPos(scores[0]));
  for (var j = 1; j < scores.length; j++) {
    var ax = xPos(j-1), ay = yPos(scores[j-1]);
    var bx = xPos(j),   by = yPos(scores[j]);
    var cpx2 = (ax + bx) / 2;
    ctx.bezierCurveTo(cpx2, ay, cpx2, by, bx, by);
  }
  ctx.stroke();

  // Points
  for (var k = 0; k < scores.length; k++) {
    ctx.beginPath();
    ctx.arc(xPos(k), yPos(scores[k]), 4, 0, Math.PI * 2);
    ctx.fillStyle = primaryColor;
    ctx.fill();
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = 2;
    ctx.stroke();
  }

  // X-axis labels
  ctx.fillStyle = '#a1a1aa';
  ctx.font = '10px Inter, sans-serif';
  ctx.textAlign = 'center';
  for (var l = 0; l < labels.length; l++) {
    ctx.save();
    ctx.translate(xPos(l), padT + chartH + 14);
    ctx.rotate(-Math.PI / 5);
    ctx.fillText(labels[l], 0, 0);
    ctx.restore();
  }
})();
</script>
<?php endif; ?>
