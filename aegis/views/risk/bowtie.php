<?php
// Variables provided by BowTieController::view():
// $risk, $causes, $consequences, $leftBarriers, $rightBarriers, $availableControls
// $pageTitle, $activeModule, $breadcrumbs already set by controller

$score = (int)($risk['inherent_score'] ?? 0);
$scoreLevel = $score > 14 ? 'Critical' : ($score > 9 ? 'High' : ($score > 4 ? 'Medium' : 'Low'));
$scoreBg    = $score > 14 ? 'var(--danger-subtle)' : ($score > 9 ? '#fff7ed' : ($score > 4 ? 'var(--warning-subtle)' : 'var(--success-subtle)'));
$scoreColor = $score > 14 ? 'var(--danger)' : ($score > 9 ? '#ea580c' : ($score > 4 ? 'var(--warning)' : 'var(--primary)'));

$causeTypeMeta = [
    'threat'          => ['label' => 'Threat',        'color' => 'var(--danger)', 'bg' => 'var(--danger-subtle)', 'icon' => 'bi-exclamation-octagon-fill'],
    'vulnerability'   => ['label' => 'Vulnerability', 'color' => 'var(--warning)', 'bg' => 'var(--warning-subtle)', 'icon' => 'bi-shield-slash-fill'],
    'hazard'          => ['label' => 'Hazard',         'color' => 'var(--secondary)', 'bg' => 'rgba(55,65,81,.06)', 'icon' => 'bi-biohazard'],
    'event'           => ['label' => 'Event',          'color' => '#0891b2', 'bg' => '#ecfeff', 'icon' => 'bi-lightning-fill'],
];

$consequenceTypeMeta = [
    'financial'      => ['label' => 'Financial',     'color' => 'var(--danger)', 'bg' => 'var(--danger-subtle)', 'icon' => 'bi-cash-coin'],
    'operational'    => ['label' => 'Operational',   'color' => 'var(--warning)', 'bg' => 'var(--warning-subtle)', 'icon' => 'bi-gear-fill'],
    'reputational'   => ['label' => 'Reputational',  'color' => 'var(--secondary)', 'bg' => 'rgba(55,65,81,.06)', 'icon' => 'bi-star-fill'],
    'legal'          => ['label' => 'Legal',          'color' => '#0891b2', 'bg' => '#ecfeff', 'icon' => 'bi-balance-scale'],
    'safety'         => ['label' => 'Safety',         'color' => 'var(--primary)', 'bg' => 'var(--success-subtle)', 'icon' => 'bi-heart-pulse-fill'],
    'impact'         => ['label' => 'Impact',         'color' => '#71717a', 'bg' => '#f4f4f5', 'icon' => 'bi-arrow-down-circle-fill'],
];

$severityMeta = [
    'low'      => ['label' => 'Low',      'color' => 'var(--primary)', 'bg' => 'var(--success-subtle)'],
    'medium'   => ['label' => 'Medium',   'color' => 'var(--warning)', 'bg' => 'var(--warning-subtle)'],
    'high'     => ['label' => 'High',     'color' => '#ea580c', 'bg' => '#fff7ed'],
    'critical' => ['label' => 'Critical', 'color' => 'var(--danger)', 'bg' => 'var(--danger-subtle)'],
];

$effectivenessMeta = [
    'degraded'    => ['label' => 'Degraded',    'color' => 'var(--danger)', 'ring' => 'var(--danger-border)'],
    'partial'     => ['label' => 'Partial',     'color' => 'var(--warning)', 'ring' => 'var(--warning-border)'],
    'substantial' => ['label' => 'Substantial', 'color' => 'var(--moderate)', 'ring' => 'var(--moderate-border)'],
    'full'        => ['label' => 'Full',        'color' => 'var(--primary)', 'ring' => 'var(--success-border)'],
];

$barrierTypeMeta = [
    'control'    => ['label' => 'Control',    'icon' => 'bi-shield-fill-check'],
    'procedure'  => ['label' => 'Procedure',  'icon' => 'bi-file-earmark-text-fill'],
    'training'   => ['label' => 'Training',   'icon' => 'bi-person-fill-check'],
    'technology' => ['label' => 'Technology', 'icon' => 'bi-cpu-fill'],
    'monitoring' => ['label' => 'Monitoring', 'icon' => 'bi-activity'],
];

$likelihoodMeta = [
    'low'    => ['label' => 'Low',    'color' => 'var(--primary)'],
    'medium' => ['label' => 'Medium', 'color' => 'var(--warning)'],
    'high'   => ['label' => 'High',   'color' => 'var(--danger)'],
];

$isEmpty = empty($causes) && empty($consequences) && empty($leftBarriers) && empty($rightBarriers);
?>
<style nonce="<?= Security::nonce() ?>">
/* ── Bow-Tie Page Styles ─────────────────────────────────────────────────── */
.bt-page { padding: 0; }

/* Diagram container */
.bt-diagram-wrap {
    overflow-x: auto;
    padding: 24px 0 12px;
}
.bt-diagram {
    display: flex;
    align-items: stretch;
    min-width: 900px;
    gap: 0;
    position: relative;
}

/* Column headers */
.bt-col-header {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    padding: 5px 10px 6px;
    border-radius: 6px;
    margin-bottom: 10px;
    text-align: center;
}

/* Causes / Consequences columns */
.bt-causes-col, .bt-consequences-col {
    width: 210px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 0;
}
.bt-causes-col {
    align-items: flex-start;
    padding-right: 0;
}
.bt-consequences-col {
    align-items: flex-end;
    padding-left: 0;
}

/* Individual cause/consequence cards */
.bt-item-card {
    position: relative;
    background: var(--card-bg);
    border: 1.5px solid var(--border, #e4e4e7);
    border-radius: 8px;
    padding: 9px 11px;
    margin-bottom: 8px;
    font-size: 12.5px;
    line-height: 1.4;
    width: 100%;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    transition: box-shadow .15s;
}
.bt-item-card:hover { box-shadow: 0 3px 8px rgba(0,0,0,.1); }
.bt-item-card-desc { font-weight: 500; color: var(--text, #111111); margin-bottom: 4px; }
.bt-item-badge {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
}
.bt-item-delete {
    position: absolute;
    top: 5px;
    right: 6px;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-muted);
    padding: 2px 4px;
    border-radius: 4px;
    font-size: 11px;
    line-height: 1;
    transition: color .12s, background .12s;
}
.bt-item-delete:hover { color: var(--danger); background: var(--danger)18; }

/* Arrow connector zones */
.bt-arrow-zone {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
    flex: 1;
}

/* Horizontal arrow line */
.bt-arrow-line {
    width: 100%;
    height: 2px;
    background: linear-gradient(90deg, #d4d4d8 0%, #a1a1aa 100%);
    position: relative;
}
.bt-arrow-line::after {
    content: '';
    position: absolute;
    right: -1px;
    top: -4px;
    border-left: 8px solid #a1a1aa;
    border-top: 5px solid transparent;
    border-bottom: 5px solid transparent;
}

/* Barriers columns */
.bt-barriers-col {
    width: 170px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 0;
    padding: 0 6px;
}

/* Barrier pill */
.bt-barrier-pill {
    position: relative;
    background: var(--card-bg);
    border-radius: 8px;
    padding: 7px 10px;
    margin-bottom: 7px;
    font-size: 11.5px;
    line-height: 1.35;
    box-shadow: 0 1px 4px rgba(0,0,0,.07);
    border: 2px solid var(--ring-color, #e4e4e7);
    transition: box-shadow .15s;
}
.bt-barrier-pill:hover { box-shadow: 0 3px 8px rgba(0,0,0,.1); }
.bt-barrier-label {
    font-weight: 600;
    color: var(--text, #111111);
    padding-right: 20px;
}
.bt-barrier-meta {
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.bt-barrier-eff {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
}

/* Central event box */
.bt-event-col {
    width: 186px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 2;
}
.bt-event-box {
    background: #111111;
    color: #fff;
    border-radius: 12px;
    padding: 18px 16px;
    text-align: center;
    box-shadow: 0 4px 16px rgba(30,41,59,.35);
    width: 100%;
    position: relative;
}
.bt-event-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--text-muted);
    margin-bottom: 8px;
}
.bt-event-title {
    font-size: 13px;
    font-weight: 700;
    line-height: 1.35;
    margin-bottom: 10px;
    word-break: break-word;
}
.bt-event-score {
    display: inline-block;
    font-size: 13px;
    font-weight: 800;
    padding: 4px 12px;
    border-radius: 20px;
}
.bt-event-arrows-left, .bt-event-arrows-right {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 0; height: 0;
}
.bt-event-arrows-left  { left: -16px; }
.bt-event-arrows-right { right: -16px; }

/* Bow-tie funnel lines — decorative */
.bt-funnel-left, .bt-funnel-right {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    min-width: 40px;
}
.bt-funnel-line {
    position: absolute;
    height: 2px;
    background: var(--border);
    width: 100%;
    top: 50%;
    transform: translateY(-50%);
}

/* Empty state */
.bt-empty {
    background: var(--bg-secondary);
    border: 2px dashed var(--border);
    border-radius: 12px;
    padding: 32px 24px;
    text-align: center;
    color: var(--text-muted);
    margin: 20px 0;
}
.bt-empty h3 { color: var(--text); margin-bottom: 8px; font-size: 16px; }
.bt-empty p  { font-size: 13.5px; line-height: 1.6; max-width: 520px; margin: 0 auto 6px; }

/* Tabs */
.bt-tabs-nav {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border, #e4e4e7);
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.bt-tab-btn {
    background: none;
    border: none;
    padding: 10px 18px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
    border-radius: 6px 6px 0 0;
    white-space: nowrap;
}
.bt-tab-btn:hover  { color: var(--primary, var(--primary)); background: var(--bg-secondary); }
.bt-tab-btn.active { color: var(--primary, var(--primary)); border-bottom-color: var(--primary, var(--primary)); background: transparent; }

.bt-tab-panel { display: none; }
.bt-tab-panel.active { display: block; }

/* Connectors */
.bt-connector-row {
    display: flex;
    align-items: center;
    position: relative;
    padding: 4px 0;
}
.bt-connector-line {
    flex: 1;
    height: 1.5px;
    background: var(--border);
}

/* Responsive */
@media (max-width: 900px) {
    .bt-diagram { min-width: 700px; }
    .bt-causes-col, .bt-consequences-col { width: 170px; }
    .bt-barriers-col { width: 130px; }
    .bt-event-col { width: 154px; }
}

/* Diagram section backgrounds */
.bt-section-causes    { background: rgba(239,68,68,.06); }
.bt-section-barriers  { background: rgba(59,130,246,.06); }
.bt-section-event     { background: transparent; }
.bt-section-recovery  { background: rgba(22,163,74,.06); }
.bt-section-conseq    { background: rgba(128,128,128,.06); }
</style>

<?php if (!empty($_SESSION['flash_success'])): ?>
<div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
<?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
<div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
<?php unset($_SESSION['flash_error']); endif; ?>

<!-- Page Header -->
<div class="page-header" style="flex-wrap:wrap;gap:12px">
  <div style="min-width:0;flex:1">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
      <span class="mono text-sm text-muted"><?= Security::h($risk['risk_id'] ?? '') ?></span>
      <span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;background:<?= $scoreBg ?>;color:<?= $scoreColor ?>;border:1px solid <?= $scoreColor ?>40">
        <?= Security::h($scoreLevel) ?> &middot; <?= $score ?>
      </span>
    </div>
    <h1 class="page-title" style="margin:0"><?= Security::h($risk['title']) ?> — Bow-Tie Analysis</h1>
    <p class="page-subtitle" style="margin-top:4px">Visual cause-barrier-consequence diagram for this risk event</p>
  </div>
  <div class="page-actions" style="flex-shrink:0">
    <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost btn-sm">
      <i class="bi bi-arrow-left"></i> Back to Risk
    </a>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     BOW-TIE VISUAL DIAGRAM
════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-diagram-3-fill"></i> Bow-Tie Diagram</h3>
    <span style="font-size:12px;color:var(--text-muted)">Left = causes &amp; preventive controls &nbsp;|&nbsp; Right = consequences &amp; recovery controls</span>
  </div>
  <div class="card-body" style="padding:16px">

  <?php if ($isEmpty): ?>
  <div class="bt-empty">
    <i class="bi bi-diagram-2" style="font-size:36px;color:#d4d4d8;display:block;margin-bottom:12px"></i>
    <h3>No Bow-Tie Elements Yet</h3>
    <p>
      A <strong>bow-tie diagram</strong> maps the causes (left side) and consequences (right side) of a risk event,
      with controls/barriers in between. Preventive barriers stop the risk from occurring; recovery barriers
      limit the impact after the event.
    </p>
    <p>Use the <strong>Add Items</strong> panel below to build out this risk's bow-tie.</p>
  </div>
  <?php else: ?>

  <div class="bt-diagram-wrap">
    <div class="bt-diagram">

      <!-- ── (A) Causes Column ───────────────────────────── -->
      <div class="bt-causes-col">
        <div class="bt-col-header" style="color:var(--danger);background:var(--danger)18;border:1px solid #dc262640">
          <i class="bi bi-exclamation-triangle-fill"></i> Threat / Cause
        </div>
        <?php if (empty($causes)): ?>
          <div style="font-size:12px;color:var(--text-muted);font-style:italic;padding:8px 0">No causes added</div>
        <?php else: foreach ($causes as $cause):
          $ct = $causeTypeMeta[$cause['cause_type']] ?? $causeTypeMeta['threat'];
          $lh = $likelihoodMeta[$cause['likelihood_contribution']] ?? $likelihoodMeta['medium'];
        ?>
          <div class="bt-item-card">
            <?php if (Auth::can('risk.write')): ?>
            <form method="POST" action="/risk-bowtie/cause/<?= (int)$cause['id'] ?>/remove" style="display:inline">
              <?= Security::csrfField() ?>
              <button type="submit" class="bt-item-delete" title="Remove" data-confirm-click="Remove this cause?">
                <i class="bi bi-x-lg"></i>
              </button>
            </form>
            <?php endif; ?>
            <div class="bt-item-card-desc" style="padding-right:18px"><?= Security::h($cause['description']) ?></div>
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">
              <span class="bt-item-badge" style="background:<?= Security::h($ct['bg']) ?>;color:<?= Security::h($ct['color']) ?>">
                <i class="bi <?= Security::h($ct['icon']) ?>"></i> <?= Security::h($ct['label']) ?>
              </span>
              <span class="bt-item-badge" style="background:<?= Security::h($lh['color']) ?>18;color:<?= Security::h($lh['color']) ?>">
                <?= Security::h($lh['label']) ?> likelihood
              </span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ── (B) Left Barriers ───────────────────────────── -->
      <div class="bt-barriers-col">
        <div class="bt-col-header" style="color:var(--moderate);background:var(--moderate-subtle);border:1px solid var(--moderate-border)">
          <i class="bi bi-shield-fill-check"></i> Preventive Controls
        </div>
        <?php if (empty($leftBarriers)): ?>
          <div style="font-size:11px;color:var(--text-muted);font-style:italic;padding:8px 4px">No barriers</div>
        <?php else: foreach ($leftBarriers as $bar):
          $eff  = $effectivenessMeta[$bar['effectiveness']] ?? $effectivenessMeta['partial'];
          $btyp = $barrierTypeMeta[$bar['barrier_type']] ?? $barrierTypeMeta['control'];
        ?>
          <div class="bt-barrier-pill" style="--ring-color:<?= Security::h($eff['ring']) ?>">
            <?php if (Auth::can('risk.write')): ?>
            <form method="POST" action="/risk-bowtie/barrier/<?= (int)$bar['id'] ?>/remove" style="display:inline">
              <?= Security::csrfField() ?>
              <button type="submit" class="bt-item-delete" title="Remove" data-confirm-click="Remove this barrier?">
                <i class="bi bi-x-lg"></i>
              </button>
            </form>
            <?php endif; ?>
            <div class="bt-barrier-label" style="padding-right:18px"><?= Security::h($bar['description']) ?></div>
            <div class="bt-barrier-meta">
              <span class="bt-barrier-eff" style="background:<?= Security::h($eff['color']) ?>18;color:<?= Security::h($eff['color']) ?>">
                <?= Security::h($eff['label']) ?>
              </span>
              <span style="font-size:10px;color:var(--text-muted)">
                <i class="bi <?= Security::h($btyp['icon']) ?>"></i> <?= Security::h($btyp['label']) ?>
              </span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ── (C) Funnel Left ─────────────────────────────── -->
      <div class="bt-funnel-left">
        <div style="position:relative;width:100%;display:flex;align-items:center">
          <div style="flex:1;height:2px;background:linear-gradient(90deg,#d4d4d8,#71717a)"></div>
          <div style="width:0;height:0;border-left:8px solid #71717a;border-top:5px solid transparent;border-bottom:5px solid transparent"></div>
        </div>
      </div>

      <!-- ── (D) Central Event Box ───────────────────────── -->
      <div class="bt-event-col">
        <div class="bt-event-box">
          <div class="bt-event-label"><i class="bi bi-radioactive"></i> RISK EVENT</div>
          <div class="bt-event-title"><?= Security::h($risk['title']) ?></div>
          <span class="bt-event-score" style="background:<?= Security::h($scoreBg) ?>;color:<?= Security::h($scoreColor) ?>">
            <?= Security::h($scoreLevel) ?> &middot; <?= $score ?>
          </span>
          <?php if ($risk['risk_id']): ?>
          <div style="margin-top:8px;font-size:10px;color:var(--text-muted);font-family:monospace"><?= Security::h($risk['risk_id']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── (E) Funnel Right ────────────────────────────── -->
      <div class="bt-funnel-right">
        <div style="position:relative;width:100%;display:flex;align-items:center">
          <div style="flex:1;height:2px;background:linear-gradient(90deg,#71717a,#d4d4d8)"></div>
          <div style="width:0;height:0;border-left:8px solid #d4d4d8;border-top:5px solid transparent;border-bottom:5px solid transparent"></div>
        </div>
      </div>

      <!-- ── (F) Right Barriers ──────────────────────────── -->
      <div class="bt-barriers-col">
        <div class="bt-col-header" style="color:var(--primary);background:var(--primary)18;border:1px solid #16a34a40">
          <i class="bi bi-arrow-counterclockwise"></i> Recovery Controls
        </div>
        <?php if (empty($rightBarriers)): ?>
          <div style="font-size:11px;color:var(--text-muted);font-style:italic;padding:8px 4px">No barriers</div>
        <?php else: foreach ($rightBarriers as $bar):
          $eff  = $effectivenessMeta[$bar['effectiveness']] ?? $effectivenessMeta['partial'];
          $btyp = $barrierTypeMeta[$bar['barrier_type']] ?? $barrierTypeMeta['control'];
        ?>
          <div class="bt-barrier-pill" style="--ring-color:<?= Security::h($eff['ring']) ?>">
            <?php if (Auth::can('risk.write')): ?>
            <form method="POST" action="/risk-bowtie/barrier/<?= (int)$bar['id'] ?>/remove" style="display:inline">
              <?= Security::csrfField() ?>
              <button type="submit" class="bt-item-delete" title="Remove" data-confirm-click="Remove this barrier?">
                <i class="bi bi-x-lg"></i>
              </button>
            </form>
            <?php endif; ?>
            <div class="bt-barrier-label" style="padding-right:18px"><?= Security::h($bar['description']) ?></div>
            <div class="bt-barrier-meta">
              <span class="bt-barrier-eff" style="background:<?= Security::h($eff['color']) ?>18;color:<?= Security::h($eff['color']) ?>">
                <?= Security::h($eff['label']) ?>
              </span>
              <span style="font-size:10px;color:var(--text-muted)">
                <i class="bi <?= Security::h($btyp['icon']) ?>"></i> <?= Security::h($btyp['label']) ?>
              </span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- ── (G) Consequences Column ────────────────────── -->
      <div class="bt-consequences-col">
        <div class="bt-col-header" style="color:var(--secondary);background:rgba(55,65,81,.06);border:1px solid var(--border)">
          <i class="bi bi-arrow-down-circle-fill"></i> Consequence / Impact
        </div>
        <?php if (empty($consequences)): ?>
          <div style="font-size:12px;color:var(--text-muted);font-style:italic;padding:8px 0">No consequences added</div>
        <?php else: foreach ($consequences as $con):
          $cty = $consequenceTypeMeta[$con['consequence_type']] ?? $consequenceTypeMeta['impact'];
          $sev = $severityMeta[$con['severity']] ?? $severityMeta['medium'];
        ?>
          <div class="bt-item-card">
            <?php if (Auth::can('risk.write')): ?>
            <form method="POST" action="/risk-bowtie/consequence/<?= (int)$con['id'] ?>/remove" style="display:inline">
              <?= Security::csrfField() ?>
              <button type="submit" class="bt-item-delete" title="Remove" data-confirm-click="Remove this consequence?">
                <i class="bi bi-x-lg"></i>
              </button>
            </form>
            <?php endif; ?>
            <div class="bt-item-card-desc" style="padding-right:18px"><?= Security::h($con['description']) ?></div>
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">
              <span class="bt-item-badge" style="background:<?= Security::h($cty['bg']) ?>;color:<?= Security::h($cty['color']) ?>">
                <i class="bi <?= Security::h($cty['icon']) ?>"></i> <?= Security::h($cty['label']) ?>
              </span>
              <span class="bt-item-badge" style="background:<?= Security::h($sev['bg']) ?>;color:<?= Security::h($sev['color']) ?>">
                <?= Security::h($sev['label']) ?>
              </span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

    </div><!-- .bt-diagram -->
  </div><!-- .bt-diagram-wrap -->

  <?php endif; // $isEmpty ?>

  <!-- Legend -->
  <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:16px;padding-top:12px;border-top:1px solid var(--border);font-size:11.5px;color:var(--text-muted)">
    <span style="font-weight:600;color:var(--text)">Barrier effectiveness:</span>
    <?php foreach ($effectivenessMeta as $key => $em): ?>
    <span>
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= Security::h($em['color']) ?>;margin-right:4px;vertical-align:middle"></span>
      <?= Security::h($em['label']) ?>
    </span>
    <?php endforeach; ?>
  </div>

  </div><!-- .card-body -->
</div>

<!-- ═══════════════════════════════════════════════════════════
     ADD ITEMS PANEL
════════════════════════════════════════════════════════════ -->
<?php if (Auth::can('risk.write')): ?>
<div class="card">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-plus-circle-fill"></i> Add Items</h3>
  </div>
  <div class="card-body">

    <!-- Tab Navigation -->
    <div class="bt-tabs-nav" role="tablist">
      <button class="bt-tab-btn active" data-click="btTabBtn" data-arg="causes" role="tab" aria-selected="true">
        <i class="bi bi-exclamation-triangle-fill" style="color:var(--danger)"></i>
        Causes
        <?php if (!empty($causes)): ?>
          <span style="background:var(--danger)18;color:var(--danger);font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px"><?= count($causes) ?></span>
        <?php endif; ?>
      </button>
      <button class="bt-tab-btn" data-click="btTabBtn" data-arg="left-barriers" role="tab" aria-selected="false">
        <i class="bi bi-shield-fill-check" style="color:var(--moderate)"></i>
        Left Barriers
        <?php if (!empty($leftBarriers)): ?>
          <span style="background:var(--moderate-subtle);color:var(--moderate);font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px"><?= count($leftBarriers) ?></span>
        <?php endif; ?>
      </button>
      <button class="bt-tab-btn" data-click="btTabBtn" data-arg="right-barriers" role="tab" aria-selected="false">
        <i class="bi bi-arrow-counterclockwise" style="color:var(--primary)"></i>
        Right Barriers
        <?php if (!empty($rightBarriers)): ?>
          <span style="background:var(--primary)18;color:var(--primary);font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px"><?= count($rightBarriers) ?></span>
        <?php endif; ?>
      </button>
      <button class="bt-tab-btn" data-click="btTabBtn" data-arg="consequences" role="tab" aria-selected="false">
        <i class="bi bi-arrow-down-circle-fill" style="color:var(--secondary)"></i>
        Consequences
        <?php if (!empty($consequences)): ?>
          <span style="background:var(--secondary)18;color:var(--secondary);font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px"><?= count($consequences) ?></span>
        <?php endif; ?>
      </button>
    </div>

    <!-- ── Tab: Causes ──────────────────────────────────────────── -->
    <div class="bt-tab-panel active" id="bt-panel-causes">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
        Add <strong>causes</strong> — threats, vulnerabilities, hazards, or events that could trigger this risk.
      </p>
      <form method="POST" action="/risk/<?= (int)$risk['id'] ?>/bowtie/add-cause">
        <?= Security::csrfField() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end">
          <div class="form-group" style="margin:0">
            <label class="form-label required">Description</label>
            <textarea name="description" class="form-control" rows="2" required
                      placeholder="Describe the cause or threat source…"></textarea>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Cause Type</label>
            <select name="cause_type" class="form-control">
              <option value="threat">Threat</option>
              <option value="vulnerability">Vulnerability</option>
              <option value="hazard">Hazard</option>
              <option value="event">Event</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Likelihood Contribution</label>
            <select name="likelihood_contribution" class="form-control">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
            </select>
          </div>
          <div>
            <button type="submit" class="btn btn-primary" style="height:38px">
              <i class="bi bi-plus-lg"></i> Add Cause
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- ── Tab: Left Barriers (Preventive) ─────────────────────── -->
    <div class="bt-tab-panel" id="bt-panel-left-barriers">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
        Add <strong>preventive barriers</strong> — controls that stop the risk event from occurring.
      </p>
      <form method="POST" action="/risk/<?= (int)$risk['id'] ?>/bowtie/add-barrier">
        <?= Security::csrfField() ?>
        <input type="hidden" name="side" value="left">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:12px;align-items:end">
          <div class="form-group" style="margin:0">
            <label class="form-label required">Description</label>
            <textarea name="description" class="form-control" rows="2" required
                      placeholder="Describe this preventive control…"></textarea>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Barrier Type</label>
            <select name="barrier_type" class="form-control">
              <option value="control">Control</option>
              <option value="procedure">Procedure</option>
              <option value="training">Training</option>
              <option value="technology">Technology</option>
              <option value="monitoring">Monitoring</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Effectiveness</label>
            <select name="effectiveness" class="form-control">
              <option value="degraded">Degraded</option>
              <option value="partial" selected>Partial</option>
              <option value="substantial">Substantial</option>
              <option value="full">Full</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Link to Control <span class="text-muted" style="font-size:11px">(optional)</span></label>
            <select name="control_implementation_id" class="form-control">
              <option value="">— none —</option>
              <?php foreach ($availableControls as $ctrl): ?>
              <option value="<?= (int)$ctrl['id'] ?>">
                <?= Security::h($ctrl['code']) ?> — <?= Security::h(mb_substr($ctrl['title'], 0, 40)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <button type="submit" class="btn btn-primary" style="height:38px">
              <i class="bi bi-plus-lg"></i> Add
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- ── Tab: Right Barriers (Recovery) ──────────────────────── -->
    <div class="bt-tab-panel" id="bt-panel-right-barriers">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
        Add <strong>recovery barriers</strong> — controls that limit damage after the risk event occurs.
      </p>
      <form method="POST" action="/risk/<?= (int)$risk['id'] ?>/bowtie/add-barrier">
        <?= Security::csrfField() ?>
        <input type="hidden" name="side" value="right">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:12px;align-items:end">
          <div class="form-group" style="margin:0">
            <label class="form-label required">Description</label>
            <textarea name="description" class="form-control" rows="2" required
                      placeholder="Describe this recovery control…"></textarea>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Barrier Type</label>
            <select name="barrier_type" class="form-control">
              <option value="control">Control</option>
              <option value="procedure">Procedure</option>
              <option value="training">Training</option>
              <option value="technology">Technology</option>
              <option value="monitoring">Monitoring</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Effectiveness</label>
            <select name="effectiveness" class="form-control">
              <option value="degraded">Degraded</option>
              <option value="partial" selected>Partial</option>
              <option value="substantial">Substantial</option>
              <option value="full">Full</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Link to Control <span class="text-muted" style="font-size:11px">(optional)</span></label>
            <select name="control_implementation_id" class="form-control">
              <option value="">— none —</option>
              <?php foreach ($availableControls as $ctrl): ?>
              <option value="<?= (int)$ctrl['id'] ?>">
                <?= Security::h($ctrl['code']) ?> — <?= Security::h(mb_substr($ctrl['title'], 0, 40)) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <button type="submit" class="btn btn-primary" style="height:38px">
              <i class="bi bi-plus-lg"></i> Add
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- ── Tab: Consequences ────────────────────────────────────── -->
    <div class="bt-tab-panel" id="bt-panel-consequences">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">
        Add <strong>consequences</strong> — the impacts or outcomes if this risk materialises.
      </p>
      <form method="POST" action="/risk/<?= (int)$risk['id'] ?>/bowtie/add-consequence">
        <?= Security::csrfField() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end">
          <div class="form-group" style="margin:0">
            <label class="form-label required">Description</label>
            <textarea name="description" class="form-control" rows="2" required
                      placeholder="Describe the consequence or impact…"></textarea>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Consequence Type</label>
            <select name="consequence_type" class="form-control">
              <option value="impact">General Impact</option>
              <option value="financial">Financial</option>
              <option value="operational">Operational</option>
              <option value="reputational">Reputational</option>
              <option value="legal">Legal</option>
              <option value="safety">Safety</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Severity</label>
            <select name="severity" class="form-control">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
          </div>
          <div>
            <button type="submit" class="btn btn-primary" style="height:38px">
              <i class="bi bi-plus-lg"></i> Add Consequence
            </button>
          </div>
        </div>
      </form>
    </div>

  </div><!-- .card-body -->
</div>
<?php endif; // Auth::can('risk.write') ?>

<!-- ── Bow-Tie Explainer (if empty) ─────────────────────────────── -->
<?php if ($isEmpty): ?>
<div class="card" style="border-left:4px solid var(--primary)">
  <div class="card-body" style="padding:20px 24px">
    <h4 style="margin:0 0 10px;font-size:14px;color:var(--text)"><i class="bi bi-info-circle-fill" style="color:var(--primary)"></i> About Bow-Tie Analysis</h4>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;font-size:13px;color:var(--text-muted)">
      <div>
        <strong style="color:var(--danger)"><i class="bi bi-exclamation-triangle-fill"></i> Left Side — Causes</strong>
        <p style="margin:6px 0 0">Threats, vulnerabilities, or events that could lead to the central risk event occurring. Each cause may have a different likelihood contribution.</p>
      </div>
      <div>
        <strong style="color:var(--primary)"><i class="bi bi-shield-fill-check"></i> Barriers</strong>
        <p style="margin:6px 0 0"><em>Preventive</em> barriers (left) stop the event from happening. <em>Recovery</em> barriers (right) limit harm after it occurs. Both map to your existing controls.</p>
      </div>
      <div>
        <strong style="color:var(--secondary)"><i class="bi bi-arrow-down-circle-fill"></i> Right Side — Consequences</strong>
        <p style="margin:6px 0 0">The impacts that would materialise if the risk event occurs — financial, operational, reputational, legal, or safety in nature.</p>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script nonce="<?= Security::nonce() ?>">
function btTabBtn(name) {
  var btn = document.querySelector('[data-click="btTabBtn"][data-arg="' + name + '"]');
  btTab(name, btn);
}
function btTab(name, btn) {
    // Hide all panels
    document.querySelectorAll('.bt-tab-panel').forEach(function(p) {
        p.classList.remove('active');
    });
    // Deactivate all tab buttons
    document.querySelectorAll('.bt-tab-btn').forEach(function(b) {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    // Show target panel
    var panel = document.getElementById('bt-panel-' + name);
    if (panel) panel.classList.add('active');
    // Activate clicked button
    if (btn) {
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
    }
}
</script>
