<?php
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_error']);
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-plus-circle-fill" style="margin-right:8px;"></i>New Key Risk Indicator</h1>
    <p class="page-subtitle">Define a measurable metric with RAG thresholds</p>
  </div>
  <div class="page-actions">
    <a href="/kris" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to KRI Dashboard</a>
  </div>
</div>

<?php if ($flashError): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($flashError) ?></div>
<?php endif; ?>

<form method="POST" action="/kris/create" id="kriForm">
  <?= Security::csrfField() ?>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;">

    <!-- Card 1: KRI Definition -->
    <div style="display:flex;flex-direction:column;gap:20px;">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-speedometer2"></i> KRI Definition</h3>
        </div>
        <div class="card-body">

          <!-- Title -->
          <div class="form-group">
            <label class="form-label">Title <span style="color:var(--danger);">*</span></label>
            <input type="text" name="title" class="form-control" required
                   placeholder="e.g. Overdue High-Risk Items, SLA Breach Rate"
                   value="<?= Security::h($_POST['title'] ?? '') ?>">
          </div>

          <!-- Description -->
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"
                      placeholder="Describe what this KRI measures and why it matters..."><?= Security::h($_POST['description'] ?? '') ?></textarea>
          </div>

          <!-- Unit + Direction -->
          <div class="form-row" style="display:flex;gap:16px;">
            <div class="form-group" style="flex:1;">
              <label class="form-label">Unit</label>
              <input type="text" name="unit" class="form-control"
                     placeholder='e.g. count, %, days, $'
                     value="<?= Security::h($_POST['unit'] ?? 'count') ?>">
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Label shown next to values</div>
            </div>
            <div class="form-group" style="flex:1;">
              <label class="form-label">Direction</label>
              <select name="direction" class="form-control" id="directionSelect">
                <option value="higher_worse" <?= (($_POST['direction'] ?? 'higher_worse') === 'higher_worse') ? 'selected' : '' ?>>
                  Higher is worse risk (e.g. incident count)
                </option>
                <option value="lower_worse" <?= (($_POST['direction'] ?? '') === 'lower_worse') ? 'selected' : '' ?>>
                  Lower is worse risk (e.g. uptime %)
                </option>
              </select>
            </div>
          </div>

          <!-- Frequency -->
          <div class="form-group">
            <label class="form-label">Measurement Frequency</label>
            <select name="frequency" class="form-control">
              <?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','quarterly'=>'Quarterly'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= (($_POST['frequency'] ?? 'monthly') === $val) ? 'selected' : '' ?>>
                  <?= $label ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Owner -->
          <div class="form-group">
            <label class="form-label">Owner</label>
            <select name="owner_id" class="form-control">
              <option value="">Unassigned</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"
                  <?= ((int)($_POST['owner_id'] ?? Auth::id()) === (int)$u['id']) ? 'selected' : '' ?>>
                  <?= Security::h($u['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Linked Risk -->
          <div class="form-group">
            <label class="form-label">Linked Risk <span style="font-size:11px;font-weight:400;color:var(--text-muted);">(optional)</span></label>
            <select name="linked_risk_id" class="form-control">
              <option value="">None</option>
              <?php foreach ($risks as $r): ?>
                <option value="<?= (int)$r['id'] ?>"
                  <?= ((int)($_POST['linked_risk_id'] ?? 0) === (int)$r['id']) ? 'selected' : '' ?>>
                  <?= Security::h($r['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

        </div>
      </div>
    </div>

    <!-- Card 2: Thresholds -->
    <div style="display:flex;flex-direction:column;gap:20px;">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-sliders"></i> RAG Thresholds</h3>
        </div>
        <div class="card-body">

          <div style="background:var(--bg-subtle);border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:12px;color:var(--text-muted);line-height:1.5;">
            <strong style="color:var(--text-primary);">How thresholds work:</strong><br>
            For <em>Higher is worse</em>: value &le; Green threshold = Green; &le; Amber = Amber; otherwise Red.<br>
            For <em>Lower is worse</em>: value &ge; Green = Green; &ge; Amber = Amber; otherwise Red.
          </div>

          <!-- Green threshold -->
          <div class="form-group">
            <label class="form-label" style="color:var(--primary);font-weight:700;">
              <i class="bi bi-circle-fill" style="font-size:10px;"></i> Green Threshold
            </label>
            <input type="number" name="threshold_green" id="tGreen" class="form-control" step="any" required
                   placeholder="e.g. 5"
                   value="<?= Security::h((string)($_POST['threshold_green'] ?? '')) ?>"
                   style="border-color:#16a34a44;background:var(--success-subtle);"
                   data-input="updatePreview">
            <div style="font-size:11px;color:var(--primary);margin-top:3px;" id="greenHint">On-track level</div>
          </div>

          <!-- Amber threshold -->
          <div class="form-group">
            <label class="form-label" style="color:var(--warning);font-weight:700;">
              <i class="bi bi-circle-fill" style="font-size:10px;"></i> Amber Threshold
            </label>
            <input type="number" name="threshold_amber" id="tAmber" class="form-control" step="any" required
                   placeholder="e.g. 10"
                   value="<?= Security::h((string)($_POST['threshold_amber'] ?? '')) ?>"
                   style="border-color:#d9770644;background:var(--warning-subtle);"
                   data-input="updatePreview">
            <div style="font-size:11px;color:var(--warning);margin-top:3px;" id="amberHint">Caution level</div>
          </div>

          <!-- Red threshold -->
          <div class="form-group">
            <label class="form-label" style="color:var(--danger);font-weight:700;">
              <i class="bi bi-circle-fill" style="font-size:10px;"></i> Red Threshold
            </label>
            <input type="number" name="threshold_red" id="tRed" class="form-control" step="any" required
                   placeholder="e.g. 20"
                   value="<?= Security::h((string)($_POST['threshold_red'] ?? '')) ?>"
                   style="border-color:#dc262644;background:var(--danger-subtle);"
                   data-input="updatePreview">
            <div style="font-size:11px;color:var(--danger);margin-top:3px;" id="redHint">Danger level</div>
          </div>

          <!-- Live preview bar -->
          <div style="margin-top:20px;">
            <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;">Live Preview</div>
            <div id="previewBar" style="height:18px;border-radius:6px;overflow:hidden;display:flex;background:#e4e4e7;transition:all .3s;">
              <div id="previewGreen" style="background:var(--primary);width:33.3%;transition:width .3s;"></div>
              <div id="previewAmber" style="background:var(--warning);width:33.3%;transition:width .3s;"></div>
              <div id="previewRed"   style="background:var(--danger);width:33.4%;transition:width .3s;"></div>
            </div>
            <div id="previewLabels" style="display:flex;justify-content:space-between;font-size:10px;margin-top:4px;">
              <span style="color:var(--primary);font-weight:600;" id="previewGreenLabel">Green</span>
              <span style="color:var(--warning);font-weight:600;" id="previewAmberLabel">Amber</span>
              <span style="color:var(--danger);font-weight:600;" id="previewRedLabel">Red</span>
            </div>
          </div>

        </div>
      </div>

      <!-- Submit -->
      <div style="display:flex;gap:12px;">
        <button type="submit" class="btn btn-primary" style="flex:1;">
          <i class="bi bi-plus-lg"></i> Create KRI
        </button>
        <a href="/kris" class="btn btn-ghost">Cancel</a>
      </div>
    </div>

  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
(function() {
  var dirSel = document.getElementById('directionSelect');

  function updatePreview() {
    var g = parseFloat(document.getElementById('tGreen').value) || 0;
    var a = parseFloat(document.getElementById('tAmber').value) || 0;
    var r = parseFloat(document.getElementById('tRed').value)   || 0;
    var dir = dirSel.value;

    var total = Math.max(dir === 'higher_worse' ? r : g, 0.0001);
    var gPct, aPct, rPct;

    if (dir === 'higher_worse') {
      gPct = Math.min(100, Math.max(0, (g / total) * 100));
      aPct = Math.min(100 - gPct, Math.max(0, ((a - g) / total) * 100));
      rPct = Math.max(0, 100 - gPct - aPct);
      document.getElementById('greenHint').textContent = 'Green: value ≤ ' + g;
      document.getElementById('amberHint').textContent = 'Amber: ' + g + ' < value ≤ ' + a;
      document.getElementById('redHint').textContent   = 'Red: value > ' + a;
    } else {
      rPct = Math.min(100, Math.max(0, (r / total) * 100));
      aPct = Math.min(100 - rPct, Math.max(0, ((a - r) / total) * 100));
      gPct = Math.max(0, 100 - rPct - aPct);
      document.getElementById('greenHint').textContent = 'Green: value ≥ ' + g;
      document.getElementById('amberHint').textContent = 'Amber: ' + r + ' ≤ value < ' + a;
      document.getElementById('redHint').textContent   = 'Red: value < ' + r;
    }

    if (dir === 'higher_worse') {
      document.getElementById('previewGreen').style.width = gPct + '%';
      document.getElementById('previewAmber').style.width = aPct + '%';
      document.getElementById('previewRed').style.width   = rPct + '%';
    } else {
      // For lower_worse: show red | amber | green left-to-right
      document.getElementById('previewGreen').style.width = rPct + '%';
      document.getElementById('previewAmber').style.width = aPct + '%';
      document.getElementById('previewRed').style.width   = gPct + '%';
      // Swap colors
      document.getElementById('previewGreen').style.background = 'var(--danger)';
      document.getElementById('previewAmber').style.background = 'var(--warning)';
      document.getElementById('previewRed').style.background   = 'var(--primary)';
    }

    if (dir === 'higher_worse') {
      document.getElementById('previewGreen').style.background = 'var(--primary)';
      document.getElementById('previewAmber').style.background = 'var(--warning)';
      document.getElementById('previewRed').style.background   = 'var(--danger)';
    }

    document.getElementById('previewGreenLabel').textContent = 'Green (' + g + ')';
    document.getElementById('previewAmberLabel').textContent = 'Amber (' + a + ')';
    document.getElementById('previewRedLabel').textContent   = 'Red (' + r + ')';
  }

  window.updatePreview = updatePreview;
  dirSel.addEventListener('change', updatePreview);
  updatePreview();
})();
</script>
