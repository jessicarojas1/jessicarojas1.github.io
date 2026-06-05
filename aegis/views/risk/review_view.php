<?php
$pageTitle    = Security::h($review['title']);
$activeModule = 'risk';
$breadcrumbs  = [['Risk Register','/risk'],['Risk Reviews','/risk/reviews'],[Security::h($review['title']),null]];
$nonce = Security::nonce();
ob_start();

$statusColors = [
    'planned'     => ['#3b82f6','#eff6ff','#bfdbfe'],
    'in_progress' => ['#f59e0b','#fffbeb','#fde68a'],
    'completed'   => ['#16a34a','#f0fdf4','#bbf7d0'],
    'cancelled'   => ['#71717a','#f9fafb','#e4e4e7'],
];
[$stFg,$stBg,$stBd] = $statusColors[$review['status']] ?? $statusColors['planned'];

$typeLabels = ['periodic'=>'Periodic','triggered'=>'Triggered','ad_hoc'=>'Ad Hoc','board'=>'Board'];
$itemStatusColors = [
    'pending'        => '#71717a',
    'reviewed'       => '#16a34a',
    'escalated'      => '#ef4444',
    'deferred'       => '#f59e0b',
    'not_applicable' => '#a1a1aa',
];
$riskLevelFn = fn(int $s) => $s > 14 ? 'Critical' : ($s > 9 ? 'High' : ($s > 4 ? 'Medium' : 'Low'));
$riskColors  = ['Critical'=>'#ef4444','High'=>'#f97316','Medium'=>'#f59e0b','Low'=>'#22c55e'];

// Group items
$groups = ['pending'=>[],'reviewed'=>[],'escalated'=>[],'deferred'=>[],'not_applicable'=>[]];
foreach ($items as $item) { $groups[$item['status']][] = $item; }
$canAct  = $review['status'] === 'in_progress' && (Auth::can('risk.write') || Auth::id() == $review['lead_reviewer_id']);
$totalPct = $review['total_risks'] > 0 ? round($review['reviewed_count'] / $review['total_risks'] * 100) : 0;
?>

<style nonce="<?= $nonce ?>">
.rv-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px}
.rv-status{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:13px;font-weight:700;border:1px solid}
.rv-kpi-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.rv-kpi{flex:1;min-width:110px;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center}
.rv-kpi .num{font-size:28px;font-weight:800;line-height:1}
.rv-kpi .lbl{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-top:3px}
.progress-bar{height:10px;background:var(--bg-secondary);border-radius:5px;overflow:hidden;margin:6px 0}
.progress-fill{height:100%;background:var(--primary);border-radius:5px;transition:width .3s}
.section-tab{padding:8px 16px;font-size:13px;font-weight:600;border-radius:6px 6px 0 0;cursor:pointer;border:none;background:var(--bg-secondary);color:var(--text-muted)}
.section-tab.active{background:var(--primary);color:#fff}
.risk-item-row td{vertical-align:top;padding:10px 12px;font-size:13px}
.inline-review-form textarea{font-size:12px}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:var(--bg-card);border-radius:12px;padding:28px;max-width:520px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3)}
</style>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
<?php if (!empty($_GET['completed'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Review completed and risk review dates updated.</div>
<?php endif; ?>

<!-- Header -->
<div class="rv-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
      <a href="/risk/reviews" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left"></i></a>
      <h1 class="page-title" style="margin:0"><?= Security::h($review['title']) ?></h1>
      <span class="rv-status" style="color:<?= $stFg ?>;background:<?= $stFg ?>18;border-color:<?= $stFg ?>40">
        <?= ucfirst(str_replace('_',' ',$review['status'])) ?>
      </span>
    </div>
    <div style="color:var(--text-muted);font-size:13px">
      <span><?= $typeLabels[$review['review_type']] ?? $review['review_type'] ?> review</span>
      &nbsp;·&nbsp; Scheduled <?= Security::h($review['scheduled_date']) ?>
      <?php if ($review['lead_reviewer_name']): ?>
        &nbsp;·&nbsp; Lead: <?= Security::h($review['lead_reviewer_name']) ?>
      <?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($review['status'] === 'planned' && Auth::can('risk.write')): ?>
      <form method="POST" action="/risk/reviews/<?= $review['id'] ?>/start">
        <?= Security::csrfField() ?>
        <button class="btn btn-primary"><i class="bi bi-play-fill"></i> Start Review</button>
      </form>
    <?php endif; ?>
    <?php if ($review['status'] === 'in_progress' && Auth::can('risk.write')): ?>
      <button class="btn btn-success" data-show-modal="completeModal">
        <i class="bi bi-check2-circle"></i> Complete Review
      </button>
    <?php endif; ?>
    <?php if (in_array($review['status'],['planned','in_progress']) && Auth::can('risk.write')): ?>
      <form method="POST" action="/risk/reviews/<?= $review['id'] ?>/cancel" data-confirm="Cancel this review session?">
        <?= Security::csrfField() ?>
        <button class="btn btn-ghost"><i class="bi bi-x-circle"></i> Cancel</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($review['status'] === 'completed'): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid #16a34a">
  <div class="card-body" style="display:flex;gap:24px;flex-wrap:wrap">
    <div><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Completed</div><div style="font-weight:600"><?= Security::h($review['completed_date'] ?? '—') ?></div></div>
    <div><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Signed Off By</div><div style="font-weight:600"><?= Security::h($review['sign_off_name'] ?? '—') ?></div></div>
    <?php if ($review['conclusion']): ?>
    <div style="flex:2"><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Conclusion</div><div><?= Security::h($review['conclusion']) ?></div></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Progress + KPIs -->
<div class="rv-kpi-row">
  <div class="rv-kpi"><div class="num"><?= $review['total_risks'] ?></div><div class="lbl">Total Risks</div></div>
  <div class="rv-kpi" style="border-color:#16a34a"><div class="num" style="color:#16a34a"><?= count($groups['reviewed']) + count($groups['escalated']) + count($groups['deferred']) + count($groups['not_applicable']) ?></div><div class="lbl">Reviewed</div></div>
  <div class="rv-kpi" style="border-color:var(--text-muted)"><div class="num" style="color:var(--text-muted)"><?= count($groups['pending']) ?></div><div class="lbl">Pending</div></div>
  <div class="rv-kpi" style="border-color:#ef4444"><div class="num" style="color:#ef4444"><?= count($groups['escalated']) ?></div><div class="lbl">Escalated</div></div>
  <div class="rv-kpi" style="border-color:#f59e0b"><div class="num" style="color:#f59e0b"><?= count($groups['deferred']) ?></div><div class="lbl">Deferred</div></div>
  <div style="flex:3;background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:14px 16px">
    <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:600;margin-bottom:4px">
      <span>Overall Progress</span><span style="color:var(--primary)"><?= $totalPct ?>%</span>
    </div>
    <div class="progress-bar"><div class="progress-fill" style="width:<?= $totalPct ?>%"></div></div>
    <div style="font-size:12px;color:var(--text-muted)"><?= $review['reviewed_count'] ?> of <?= $review['total_risks'] ?> risks reviewed</div>
  </div>
</div>

<!-- Risk Items Table -->
<?php foreach ($groups as $groupStatus => $groupItems): if (empty($groupItems)) continue; ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
    <h3 class="card-title">
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $itemStatusColors[$groupStatus] ?>;margin-right:6px"></span>
      <?= ucfirst(str_replace('_',' ',$groupStatus)) ?>
      <span style="font-size:12px;color:var(--text-muted);font-weight:400;margin-left:6px"><?= count($groupItems) ?> risk<?= count($groupItems)!==1?'s':'' ?></span>
    </h3>
  </div>
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Risk</th><th>Category</th><th>Inherent</th><th>Residual</th><th>Strategies</th>
          <?php if ($groupStatus === 'reviewed' || $groupStatus !== 'pending'): ?><th>Outcome</th><?php endif; ?>
          <?php if ($canAct): ?><th style="width:280px">Review</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($groupItems as $item):
          $iScore  = (int)$item['inherent_score'];
          $rScore  = (int)($item['residual_score'] ?? $iScore);
          $iLevel  = $riskLevelFn($iScore);
          $rLevel  = $riskLevelFn($rScore);
          $iColor  = $riskColors[$iLevel];
          $rColor  = $riskColors[$rLevel];
          $strats  = json_decode($item['treatment_strategies'] ?? '[]', true) ?: [];
          $sColors = ['mitigate'=>'#2563eb','accept'=>'#b45309','transfer'=>'var(--secondary)','avoid'=>'#dc2626'];
        ?>
        <tr class="risk-item-row">
          <td>
            <a href="/risk/<?= $item['risk_id'] ?>" class="fw-500 table-link" style="font-size:13px" target="_blank">
              <?= Security::h($item['title']) ?>
            </a>
            <div style="font-size:11px;color:#a1a1aa;margin-top:2px"><?= Security::h($item['risk_code'] ?? '—') ?></div>
          </td>
          <td>
            <?php if ($item['category_name']): ?>
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px">
                <span style="width:8px;height:8px;border-radius:50%;background:<?= Security::h($item['category_color']??'var(--primary)') ?>"></span>
                <?= Security::h($item['category_name']) ?>
              </span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <span style="display:inline-block;padding:3px 9px;border-radius:20px;background:<?= $iColor ?>20;color:<?= $iColor ?>;font-weight:700;font-size:13px;border:1px solid <?= $iColor ?>40">
              <?= $iScore ?>
            </span>
          </td>
          <td>
            <span style="display:inline-block;padding:3px 9px;border-radius:20px;background:<?= $rColor ?>20;color:<?= $rColor ?>;font-weight:700;font-size:13px;border:1px solid <?= $rColor ?>40">
              <?= $rScore ?>
            </span>
          </td>
          <td>
            <?php foreach ($strats as $st): $sc = $sColors[$st]??'#71717a'; ?>
              <span style="font-size:10px;font-weight:600;padding:2px 6px;border-radius:20px;background:<?= $sc ?>18;color:<?= $sc ?>;border:1px solid <?= $sc ?>30;white-space:nowrap;margin-right:2px"><?= ucfirst($st) ?></span>
            <?php endforeach; if(empty($strats)):?>—<?php endif;?>
          </td>
          <?php if ($groupStatus !== 'pending'): ?>
          <td style="font-size:12px">
            <?php if ($item['score_confirmed']): ?><span style="color:#16a34a">✓ Score confirmed</span><br><?php endif; ?>
            <?php if ($item['treatment_adequate'] === 't' || $item['treatment_adequate'] === true || $item['treatment_adequate'] === 1): ?>
              <span style="color:#16a34a">✓ Treatment adequate</span><br>
            <?php elseif ($item['treatment_adequate'] === 'f' || $item['treatment_adequate'] === false || $item['treatment_adequate'] === 0): ?>
              <span style="color:#ef4444">✗ Treatment inadequate</span><br>
            <?php endif; ?>
            <?php if ($item['reviewer_notes']): ?><div style="color:var(--text-muted);margin-top:4px;font-style:italic"><?= Security::h($item['reviewer_notes']) ?></div><?php endif; ?>
            <?php if ($item['reviewer_name']): ?><div style="color:#a1a1aa;margin-top:4px">— <?= Security::h($item['reviewer_name']) ?></div><?php endif; ?>
          </td>
          <?php endif; ?>
          <?php if ($canAct): ?>
          <td>
            <form method="POST" action="/risk/reviews/<?= $review['id'] ?>/item/<?= $item['risk_id'] ?>/update" class="inline-review-form">
              <?= Security::csrfField() ?>
              <select name="status" class="form-control" style="margin-bottom:5px;font-size:12px">
                <?php foreach (['pending'=>'Pending','reviewed'=>'Reviewed','escalated'=>'Escalated','deferred'=>'Deferred','not_applicable'=>'N/A'] as $sv=>$sl): ?>
                  <option value="<?= $sv ?>" <?= $item['status']===$sv?'selected':'' ?>><?= $sl ?></option>
                <?php endforeach; ?>
              </select>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:5px">
                <select name="new_likelihood" class="form-control" style="font-size:11px">
                  <option value="">L: same</option>
                  <?php for($i=1;$i<=5;$i++): ?>
                    <option value="<?= $i ?>" <?= $item['new_likelihood']==$i?'selected':'' ?>>L: <?= $i ?></option>
                  <?php endfor; ?>
                </select>
                <select name="new_impact" class="form-control" style="font-size:11px">
                  <option value="">I: same</option>
                  <?php for($i=1;$i<=5;$i++): ?>
                    <option value="<?= $i ?>" <?= $item['new_impact']==$i?'selected':'' ?>>I: <?= $i ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div style="display:flex;gap:8px;margin-bottom:5px;font-size:12px">
                <label style="display:flex;align-items:center;gap:4px">
                  <input type="checkbox" name="score_confirmed" value="1" <?= $item['score_confirmed']?'checked':'' ?>>
                  Score OK
                </label>
                <select name="treatment_adequate" class="form-control" style="font-size:11px;width:auto">
                  <option value="">Treatment?</option>
                  <option value="1" <?= $item['treatment_adequate']==='t'||$item['treatment_adequate']===true?'selected':'' ?>>Adequate</option>
                  <option value="0" <?= $item['treatment_adequate']==='f'||$item['treatment_adequate']===false?'selected':'' ?>>Inadequate</option>
                </select>
              </div>
              <textarea name="reviewer_notes" class="form-control" rows="2" style="font-size:11px;margin-bottom:5px" placeholder="Notes..."><?= Security::h($item['reviewer_notes'] ?? '') ?></textarea>
              <button type="submit" class="btn btn-primary btn-sm" style="width:100%"><i class="bi bi-check-lg"></i> Save</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty(array_filter($groups, fn($g)=>!empty($g)))): ?>
<div class="card"><div class="card-body"><div class="empty-state-sm"><i class="bi bi-clipboard-check"></i><p>No risks in this review session.</p></div></div></div>
<?php endif; ?>

<!-- Complete Modal -->
<div class="modal-overlay" id="completeModal">
  <div class="modal-box">
    <h3 style="margin:0 0 12px"><i class="bi bi-check2-circle" style="color:#16a34a"></i> Complete Review</h3>
    <?php if (count($groups['pending']) > 0): ?>
    <div class="alert-box" style="background:var(--danger-subtle);border-color:#dc262640;color:#dc2626;margin-bottom:12px">
      <i class="bi bi-exclamation-triangle-fill"></i> <?= count($groups['pending']) ?> risk(s) still pending review. You can still complete but they will remain pending.
    </div>
    <?php endif; ?>
    <form method="POST" action="/risk/reviews/<?= $review['id'] ?>/complete">
      <?= Security::csrfField() ?>
      <div class="form-group">
        <label class="form-label required">Conclusion / Summary</label>
        <textarea name="conclusion" class="form-control" rows="4" required placeholder="Summarise the outcome of this review session..."></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Sign-off Notes</label>
        <input type="text" name="sign_off_notes" class="form-control" placeholder="Optional sign-off note...">
      </div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle"></i> Complete &amp; Sign Off</button>
        <button type="button" class="btn btn-ghost" data-close-modal="completeModal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= $nonce ?>">
document.getElementById('completeModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
