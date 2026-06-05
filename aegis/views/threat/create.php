<?php
$breadcrumbs = $breadcrumbs ?? [['Threat Register', '/threat'], ['New Threat', null]];
// $users provided by ThreatController::createForm()
?>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-shield-plus" style="margin-right:8px;color:var(--primary);"></i>New Threat</h1>
    <p class="page-subtitle">Add a threat source to the register</p>
  </div>
  <div class="page-actions">
    <a href="/threats" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Register</a>
  </div>
</div>

<form method="POST" action="/threats/create" id="threatForm">
  <?= Security::csrfField() ?>

  <div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start;">

    <!-- Card 1: Threat Details -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-info-circle"></i> Threat Details</h3>
      </div>
      <div class="card-body">

        <!-- Title -->
        <div class="form-group">
          <label class="form-label required">Title <span style="color:#ef4444;">*</span></label>
          <input type="text" name="title" class="form-control"
                 placeholder="e.g. Phishing Campaign, Ransomware Attack, Regulatory Non-Compliance"
                 value="<?= Security::h($_POST['title'] ?? '') ?>" required>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">A concise, descriptive name for the threat.</div>
        </div>

        <!-- Category + Status -->
        <div class="form-row" style="display:flex;gap:16px;">
          <div class="form-group" style="flex:1;">
            <label class="form-label">Category</label>
            <select name="category" class="form-control">
              <?php
              $catOpts = [
                  'people'     => 'People',
                  'process'    => 'Process',
                  'technology' => 'Technology',
                  'natural'    => 'Natural',
                  'regulatory' => 'Regulatory',
                  'financial'  => 'Financial',
              ];
              foreach ($catOpts as $val => $label):
                  $sel = (($_POST['category'] ?? 'technology') === $val) ? 'selected' : '';
              ?>
                <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
              <?php
              $statusOpts = [
                  'active'    => 'Active',
                  'mitigated' => 'Mitigated',
                  'accepted'  => 'Accepted',
                  'retired'   => 'Retired',
              ];
              foreach ($statusOpts as $val => $label):
                  $sel = (($_POST['status'] ?? 'active') === $val) ? 'selected' : '';
              ?>
                <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Source -->
        <div class="form-group">
          <label class="form-label">Source</label>
          <input type="text" name="source" class="form-control"
                 placeholder="e.g. MITRE ATT&amp;CK, threat intelligence feed, internal assessment"
                 value="<?= Security::h($_POST['source'] ?? '') ?>">
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Reference framework, feed, or origin of this threat.</div>
        </div>

        <!-- Description -->
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="4"
                    placeholder="Detailed description of the threat, how it manifests, and relevant context..."><?= Security::h($_POST['description'] ?? '') ?></textarea>
        </div>

        <!-- Mitigations -->
        <div class="form-group">
          <label class="form-label">Mitigations</label>
          <textarea name="mitigations" class="form-control" rows="3"
                    placeholder="Existing or planned controls to mitigate this threat..."><?= Security::h($_POST['mitigations'] ?? '') ?></textarea>
        </div>

      </div>
    </div>

    <!-- Card 2: Risk Rating -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-speedometer2"></i> Risk Rating</h3>
        </div>
        <div class="card-body">

          <!-- Likelihood -->
          <div class="form-group">
            <label class="form-label">Likelihood</label>
            <select name="likelihood" id="likelihood" class="form-control" data-change="updateScore">
              <?php
              $likelihoodOpts = [
                  1 => '1 — Rare',
                  2 => '2 — Unlikely',
                  3 => '3 — Possible',
                  4 => '4 — Likely',
                  5 => '5 — Almost Certain',
              ];
              foreach ($likelihoodOpts as $val => $label):
                  $sel = ((int)($_POST['likelihood'] ?? 3) === $val) ? 'selected' : '';
              ?>
                <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Impact -->
          <div class="form-group">
            <label class="form-label">Impact</label>
            <select name="impact" id="impact" class="form-control" data-change="updateScore">
              <?php
              $impactOpts = [
                  1 => '1 — Negligible',
                  2 => '2 — Minor',
                  3 => '3 — Moderate',
                  4 => '4 — Major',
                  5 => '5 — Catastrophic',
              ];
              foreach ($impactOpts as $val => $label):
                  $sel = ((int)($_POST['impact'] ?? 3) === $val) ? 'selected' : '';
              ?>
                <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Live score display -->
          <div style="text-align:center;padding:20px 16px;margin:8px 0;border-radius:12px;background:#fafbfc;border:1px solid var(--border-light);">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:8px;">Threat Score (L × I)</div>
            <div id="scoreDisplay" style="font-size:52px;font-weight:800;line-height:1;transition:color .2s;">9</div>
            <div id="scoreLabel" style="font-size:12px;font-weight:600;margin-top:6px;transition:color .2s;">Medium</div>
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

          <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary" style="width:100%;">
              <i class="bi bi-plus-lg"></i> Add Threat
            </button>
            <a href="/threats" class="btn btn-ghost" style="width:100%;margin-top:8px;text-align:center;">Cancel</a>
          </div>

        </div>
      </div>

      <!-- Score guide card -->
      <div class="card">
        <div class="card-header">
          <h4 class="card-title"><i class="bi bi-info-circle"></i> Score Guide</h4>
        </div>
        <div class="card-body" style="font-size:13px;">
          <?php
          $guide = [
              ['1 – 4',  '#f0fdf4', 'var(--primary)', 'Low',      'Minimal risk. Unlikely to occur or negligible impact.'],
              ['5 – 9',  '#fffbeb', 'var(--warning)', 'Medium',   'Moderate risk. Should be monitored and controlled.'],
              ['10 – 16','#fff7ed', '#ea580c', 'High',     'Significant risk. Requires active mitigation.'],
              ['17 – 25','#fef2f2', 'var(--danger)', 'Critical', 'Severe risk. Immediate treatment required.'],
          ];
          foreach ($guide as [$range, $bg, $color, $label, $desc]):
          ?>
            <div style="margin-bottom:10px;display:flex;align-items:flex-start;gap:10px;">
              <span style="background:<?= $bg ?>;color:<?= $color ?>;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap;flex-shrink:0;"><?= $label ?></span>
              <div>
                <div style="font-weight:600;font-size:11px;color:var(--text-muted);">Score <?= $range ?></div>
                <div style="color:var(--text-secondary);font-size:12px;"><?= $desc ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
(function() {
    function scoreColor(s) {
        if (s <= 4)  return 'var(--primary)';
        if (s <= 9)  return 'var(--warning)';
        if (s <= 16) return '#ea580c';
        return 'var(--danger)';
    }
    function scoreLabel(s) {
        if (s <= 4)  return 'Low';
        if (s <= 9)  return 'Medium';
        if (s <= 16) return 'High';
        return 'Critical';
    }
    window.updateScore = function() {
        var l = parseInt(document.getElementById('likelihood').value, 10) || 0;
        var i = parseInt(document.getElementById('impact').value, 10) || 0;
        var s = l * i;
        var c = scoreColor(s);
        var d = document.getElementById('scoreDisplay');
        var lb = document.getElementById('scoreLabel');
        d.textContent = s;
        d.style.color = c;
        lb.textContent = scoreLabel(s);
        lb.style.color = c;
    };
    updateScore();
})();
</script>
