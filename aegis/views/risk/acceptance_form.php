<?php
$pageTitle    = $pageTitle    ?? 'Issue Acceptance Certificate';
$activeModule = $activeModule ?? 'risk_acceptances';
$breadcrumbs  = $breadcrumbs  ?? [['Risk Register', '/risk'], ['Issue Acceptance', null]];

// Score helpers (mirrors risk/view.php)
$score = (int)($risk['inherent_score'] ?? 0);
function acceptFormScoreLevel(int $s): string {
    return RiskScore::scoreLabel($s); // canonical bands — see src/RiskScore.php
}
function acceptFormScoreColor(int $s): string {
    return $s > 14 ? '#ef4444' : ($s > 9 ? '#f97316' : ($s > 4 ? '#f59e0b' : '#22c55e'));
}
function acceptFormScoreBg(int $s): string {
    return $s > 14 ? '#fef2f2' : ($s > 9 ? '#fff7ed' : ($s > 4 ? '#fffbeb' : '#f0fdf4'));
}

$scoreLevel = acceptFormScoreLevel($score);
$scoreColor = acceptFormScoreColor($score);
$scoreBg    = acceptFormScoreBg($score);

// Treatment strategies
$strategies = [];
if (!empty($risk['treatment_strategies'])) {
    $raw = $risk['treatment_strategies'];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $strategies = is_array($decoded) ? $decoded : (strlen($raw) ? [$raw] : []);
    } elseif (is_array($raw)) {
        $strategies = $raw;
    }
}
$strategyLabels = [
    'mitigate' => ['label' => 'Mitigate', 'icon' => 'bi-shield-fill-check',   'color' => '#2563eb'],
    'accept'   => ['label' => 'Accept',   'icon' => 'bi-check-circle-fill',    'color' => '#b45309'],
    'transfer' => ['label' => 'Transfer', 'icon' => 'bi-arrow-left-right',     'color' => 'var(--secondary)'],
    'avoid'    => ['label' => 'Avoid',    'icon' => 'bi-x-octagon-fill',       'color' => '#dc2626'],
];

// Pre-fill values (fresh form or renew)
$prefillReason     = Security::h($prefill['acceptance_reason'] ?? $_POST['acceptance_reason'] ?? '');
$prefillConditions = Security::h($prefill['conditions']        ?? $_POST['conditions']        ?? '');
$prefillRenewal    = isset($prefill['renewal_required']) ? $prefill['renewal_required'] : (!isset($_POST['renewal_required']) || !empty($_POST['renewal_required']));

// renewed_from hidden value
$renewedFromId = isset($renewFrom['id']) ? (int)$renewFrom['id'] : null;

// Is this a renewal?
$isRenewal = ($renewedFromId !== null);
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">
      <i class="bi bi-patch-check-fill" style="color:var(--primary);margin-right:6px;"></i>
      <?= $isRenewal ? 'Renew Acceptance Certificate' : 'Issue Acceptance Certificate' ?>
    </h1>
    <p class="page-subtitle">
      <?= $isRenewal
          ? 'Renew the formal acceptance of this risk for a new validity period.'
          : 'Formally document that an accountable person accepts responsibility for this risk.' ?>
    </p>
  </div>
  <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Risk</a>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Two-column layout -->
<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

  <!-- LEFT: form column -->
  <div>

    <!-- Risk context banner -->
    <div class="card" style="margin-bottom:20px;border-left:4px solid <?= $scoreColor ?>;">
      <div class="card-body" style="padding:16px 20px;">
        <div style="display:flex;align-items:flex-start;gap:16px;">
          <div style="flex-shrink:0;width:44px;height:44px;border-radius:10px;background:<?= $scoreColor ?>18;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:20px;color:<?= $scoreColor ?>;"></i>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:15px;margin-bottom:4px;"><?= Security::h($risk['title']) ?></div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
              <?php if (!empty($risk['risk_id'])): ?>
                <span class="mono text-sm text-muted"><?= Security::h($risk['risk_id']) ?></span>
              <?php endif; ?>
              <?php if (!empty($risk['category_name'])): ?>
                <span style="font-size:12px;color:var(--text-muted);"><?= Security::h($risk['category_name']) ?></span>
              <?php endif; ?>
              <!-- Score badge -->
              <span style="font-size:12px;font-weight:700;padding:2px 10px;border-radius:20px;background:<?= $scoreColor ?>18;color:<?= $scoreColor ?>;border:1px solid <?= $scoreColor ?>40;">
                Score <?= $score ?> &mdash; <?= $scoreLevel ?>
              </span>
            </div>
            <?php if (!empty($strategies)): ?>
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:12px;color:var(--text-muted);">Treatment:</span>
                <?php foreach ($strategies as $strat):
                  $sm = $strategyLabels[$strat] ?? ['label' => ucfirst($strat), 'icon' => 'bi-circle', 'color' => '#71717a'];
                ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:<?= $sm['color'] ?>18;color:<?= $sm['color'] ?>;border:1px solid <?= $sm['color'] ?>40;">
                    <i class="bi <?= $sm['icon'] ?>"></i> <?= Security::h($sm['label']) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($existingActive): ?>
    <!-- Warning: active acceptance already exists -->
    <div class="alert-box" style="background:var(--warning-subtle);border-color:var(--warning);color:var(--warning);margin-bottom:20px;">
      <i class="bi bi-exclamation-triangle-fill" style="color:var(--warning);margin-right:6px;"></i>
      <strong>Active acceptance already exists.</strong>
      An acceptance certificate was issued by <strong><?= Security::h($existingActive['acceptor_name']) ?></strong>
      on <?= date('M j, Y', strtotime($existingActive['created_at'])) ?>,
      valid until <?= date('M j, Y', strtotime($existingActive['valid_until'])) ?>.
      Submitting this form will <strong>supersede</strong> the existing certificate.
    </div>
    <?php endif; ?>

    <!-- Acceptance form -->
    <form method="POST" action="/risk/<?= (int)$risk['id'] ?>/accept">
      <?= Security::csrfField() ?>
      <?php if ($renewedFromId): ?>
        <input type="hidden" name="renewed_from" value="<?= $renewedFromId ?>">
      <?php endif; ?>

      <!-- Acceptance Reason -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-pen-fill"></i> Acceptance Details</h3>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label required">Acceptance Reason</label>
            <textarea name="acceptance_reason" class="form-control" rows="5" required
                      placeholder="Explain why this risk is being formally accepted — e.g. business justification, cost-benefit analysis, compensating controls already in place, management decision…"><?= $prefillReason ?></textarea>
            <div class="form-hint">Why is this risk being formally accepted at its current level?</div>
          </div>

          <div class="form-group">
            <label class="form-label">Conditions of Acceptance <span class="text-muted text-sm">(optional)</span></label>
            <textarea name="conditions" class="form-control" rows="3"
                      placeholder="e.g. Subject to quarterly management review; Only valid while backup controls are in place; Requires re-evaluation if threat landscape changes…"><?= $prefillConditions ?></textarea>
            <div class="form-hint">What conditions or caveats apply to this acceptance?</div>
          </div>

        </div>
      </div>

      <!-- Validity & Renewal -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-calendar-check"></i> Validity Period</h3>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label required">Valid Until</label>
            <input type="date" name="valid_until" class="form-control"
                   value="<?= Security::h($_POST['valid_until'] ?? '') ?>"
                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                   required>
            <div class="form-hint">The date this acceptance certificate expires. Must be a future date.</div>
          </div>

          <div class="form-group">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:14px 16px;border:2px solid var(--border);border-radius:10px;background:var(--surface-alt);" id="renewal-label">
              <input type="checkbox" name="renewal_required" value="1"
                     <?= $prefillRenewal ? 'checked' : '' ?>
                     style="margin-top:2px;flex-shrink:0;"
                     data-change="onRenewalChange">
              <div>
                <div style="font-weight:600;font-size:13px;"><i class="bi bi-arrow-clockwise"></i> Renewal Required</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                  When checked, this acceptance must be explicitly renewed before it expires. A reminder will be triggered.
                </div>
              </div>
            </label>
          </div>

        </div>
      </div>

      <!-- Submit -->
      <div style="display:flex;gap:12px;justify-content:flex-end;">
        <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-patch-check-fill"></i>
          <?= $isRenewal ? 'Renew Acceptance Certificate' : 'Issue Acceptance Certificate' ?>
        </button>
      </div>

    </form>
  </div><!-- /left column -->

  <!-- RIGHT: guidance sidebar -->
  <div>

    <!-- What this means -->
    <div class="card" style="margin-bottom:16px;border-left:4px solid var(--primary);">
      <div class="card-header">
        <h3 class="card-title" style="font-size:13px;"><i class="bi bi-info-circle-fill" style="color:var(--primary);"></i> What This Means</h3>
      </div>
      <div class="card-body" style="padding:16px;">
        <p style="font-size:13px;line-height:1.6;color:var(--text);margin:0 0 12px;">
          Issuing an acceptance certificate <strong>formally documents</strong> that an accountable person accepts responsibility for this risk at its current level.
        </p>
        <p style="font-size:13px;line-height:1.6;color:var(--text);margin:0 0 12px;">
          <strong>It does NOT close the risk</strong> — the risk remains open and continues to be monitored in the register.
        </p>
        <p style="font-size:13px;line-height:1.6;color:var(--text);margin:0;">
          The certificate is time-limited. When it expires, the risk must either be re-accepted, mitigated, or treated otherwise.
        </p>
      </div>
    </div>

    <!-- Best practices -->
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header">
        <h3 class="card-title" style="font-size:13px;"><i class="bi bi-lightbulb-fill" style="color:var(--warning);"></i> Best Practices</h3>
      </div>
      <div class="card-body" style="padding:16px;">

        <div style="margin-bottom:14px;">
          <div style="font-size:12px;font-weight:700;color:var(--success);margin-bottom:4px;">
            <i class="bi bi-check-circle-fill"></i> When to Accept
          </div>
          <ul style="font-size:12px;line-height:1.6;color:var(--text-muted);margin:0;padding-left:16px;">
            <li>Cost of mitigation exceeds potential impact</li>
            <li>Risk is within the organisation's risk appetite</li>
            <li>Adequate compensating controls are already in place</li>
            <li>Residual risk after mitigation is still unacceptably expensive</li>
          </ul>
        </div>

        <div style="margin-bottom:14px;">
          <div style="font-size:12px;font-weight:700;color:var(--info);margin-bottom:4px;">
            <i class="bi bi-shield-fill-check"></i> When to Mitigate Instead
          </div>
          <ul style="font-size:12px;line-height:1.6;color:var(--text-muted);margin:0;padding-left:16px;">
            <li>Risk score is Critical (>14) without offsetting controls</li>
            <li>Regulatory or contractual requirements mandate treatment</li>
            <li>Risk is trending upward over time</li>
          </ul>
        </div>

        <div>
          <div style="font-size:12px;font-weight:700;color:var(--secondary);margin-bottom:4px;">
            <i class="bi bi-bookmark-fill"></i> Setting Good Conditions
          </div>
          <ul style="font-size:12px;line-height:1.6;color:var(--text-muted);margin:0;padding-left:16px;">
            <li>Be specific: tie conditions to reviewable events</li>
            <li>Reference the controls that justify the acceptance</li>
            <li>Set a realistic validity period (e.g. 6–12 months)</li>
            <li>Require renewal for high or critical risks</li>
          </ul>
        </div>

      </div>
    </div>

    <!-- Score at time of acceptance (informational) -->
    <div class="card" style="border-left:4px solid <?= $scoreColor ?>;">
      <div class="card-body" style="padding:16px;">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:8px;">Score Captured At Issuance</div>
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="font-size:32px;font-weight:800;color:<?= $scoreColor ?>;line-height:1;"><?= $score ?></div>
          <div>
            <div style="font-size:14px;font-weight:700;color:<?= $scoreColor ?>;"><?= $scoreLevel ?></div>
            <div style="font-size:11px;color:var(--text-muted);">Inherent risk score</div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /right column -->

</div><!-- /grid -->

<style nonce="<?= Security::nonce() ?>">
  @media (max-width: 900px) {
    div[style*="grid-template-columns:1fr 340px"] {
      grid-template-columns: 1fr !important;
    }
  }
  .alert-box {
    padding: 12px 16px;
    border-radius: 8px;
    border-left: 4px solid currentColor;
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: 13px;
    line-height: 1.5;
  }
</style>

<script nonce="<?= Security::nonce() ?>">
function onRenewalChange() {
  document.getElementById('renewal-label').style.borderColor = this.checked ? 'var(--primary)' : 'var(--border)';
}
// Highlight renewal checkbox border on load
(function() {
  var cb = document.querySelector('input[name="renewal_required"]');
  var lbl = document.getElementById('renewal-label');
  if (cb && lbl) {
    lbl.style.borderColor = cb.checked ? 'var(--primary)' : 'var(--border)';
  }
})();
</script>
