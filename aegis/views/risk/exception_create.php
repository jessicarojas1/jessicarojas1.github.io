<?php
$pageTitle    = $pageTitle    ?? 'Request Risk Exception';
$activeModule = $activeModule ?? 'risk_exceptions';
$breadcrumbs  = $breadcrumbs  ?? [['Risk Register', '/risk'], ['Request Exception', null]];

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Request Risk Exception</h1>
    <p class="page-subtitle">Submit a formal exception or waiver for an identified risk</p>
  </div>
  <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Risk</a>
</div>

<?php if (!empty($_SESSION['exception_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['exception_error']) ?></div>
  <?php unset($_SESSION['exception_error']); ?>
<?php endif; ?>

<!-- Risk context banner -->
<div class="card" style="margin-bottom:20px;border-left:4px solid var(--primary);">
  <div class="card-body" style="padding:16px 20px;display:flex;align-items:center;gap:16px;">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:24px;color:var(--primary);flex-shrink:0;"></i>
    <div>
      <div style="font-weight:600;font-size:14px;">
        <?= Security::h($risk['title']) ?>
      </div>
      <?php if (!empty($risk['risk_id'])): ?>
        <div class="text-sm text-muted mono"><?= Security::h($risk['risk_id']) ?></div>
      <?php endif; ?>
      <?php if (!empty($risk['category_name'])): ?>
        <div class="text-sm text-muted"><?= Security::h($risk['category_name']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="POST" action="/risk/<?= (int)$risk['id'] ?>/exception/create">
  <?= Security::csrfField() ?>

  <div class="card" style="margin-bottom:20px;">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-shield-exclamation"></i> Exception Details</h3>
    </div>
    <div class="card-body">

      <!-- Exception Type -->
      <div class="form-group">
        <label class="form-label required">Exception Type</label>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:6px;">
          <?php
            $types = [
              'accept'   => ['label' => 'Accept Risk',    'icon' => 'bi-check-circle',      'desc' => 'Formally accept the risk as-is'],
              'transfer' => ['label' => 'Transfer Risk',  'icon' => 'bi-arrow-left-right',  'desc' => 'Transfer to a third party (e.g. insurance)'],
              'defer'    => ['label' => 'Defer Risk',     'icon' => 'bi-clock-history',     'desc' => 'Defer action to a future date'],
            ];
            $selectedType = $_POST['exception_type'] ?? 'accept';
            foreach ($types as $val => $cfg):
          ?>
            <label style="
              flex:1;min-width:160px;
              border:2px solid var(--border);
              border-radius:10px;
              padding:14px 16px;
              cursor:pointer;
              transition:border-color .15s, background .15s;
              display:flex;gap:12px;align-items:flex-start;
            " id="type-label-<?= $val ?>">
              <input type="radio" name="exception_type" value="<?= $val ?>"
                     <?= $selectedType === $val ? 'checked' : '' ?>
                     data-change="highlightTypeCards"
                     style="margin-top:3px;flex-shrink:0;">
              <div>
                <div style="font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;">
                  <i class="bi <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:3px;"><?= $cfg['desc'] ?></div>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Rationale -->
      <div class="form-group">
        <label class="form-label required">Rationale</label>
        <textarea name="rationale" class="form-control" rows="5" required
                  placeholder="Explain why this exception is being requested, business justification, and why the risk cannot be mitigated at this time…"><?= Security::h($_POST['rationale'] ?? '') ?></textarea>
      </div>

      <!-- Compensating Controls -->
      <div class="form-group">
        <label class="form-label">Compensating Controls <span class="text-muted text-sm">(optional)</span></label>
        <textarea name="compensating_controls" class="form-control" rows="3"
                  placeholder="Describe any controls that reduce the residual risk while this exception is active…"><?= Security::h($_POST['compensating_controls'] ?? '') ?></textarea>
        <div class="form-hint">Describe any controls that reduce the residual risk</div>
      </div>

      <!-- Expiry Date -->
      <div class="form-group">
        <label class="form-label">Expiry Date <span class="text-muted text-sm">(optional)</span></label>
        <input type="date" name="expiry_date" class="form-control"
               value="<?= Security::h($_POST['expiry_date'] ?? '') ?>"
               min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
        <div class="form-hint">Leave blank for no expiry. If set, must be a future date.</div>
      </div>

      <!-- Acknowledgement -->
      <div class="form-group">
        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:14px 16px;border:2px solid var(--border);border-radius:10px;background:var(--surface-alt);">
          <input type="checkbox" name="residual_risk_acknowledged" value="1"
                 <?= !empty($_POST['residual_risk_acknowledged']) ? 'checked' : '' ?>
                 style="margin-top:2px;flex-shrink:0;">
          <div>
            <div style="font-weight:600;font-size:13px;">I acknowledge this risk will remain open</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
              By checking this box, I confirm that I understand the residual risk and accept responsibility for monitoring it.
            </div>
          </div>
        </label>
      </div>

    </div>
  </div>

  <div style="display:flex;gap:12px;justify-content:flex-end;">
    <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-warning">
      <i class="bi bi-shield-exclamation"></i> Submit Exception Request
    </button>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
function highlightTypeCards() {
  var radios = document.querySelectorAll('input[name="exception_type"]');
  radios.forEach(function(r) {
    var lbl = document.getElementById('type-label-' + r.value);
    if (lbl) {
      lbl.style.borderColor = r.checked ? 'var(--primary)' : 'var(--border)';
      lbl.style.background  = r.checked ? 'var(--primary-light, rgba(11,97,4,.06))' : '';
    }
  });
}
highlightTypeCards();
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
