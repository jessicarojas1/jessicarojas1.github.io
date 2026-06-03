<?php
$pageTitle    = 'New Change Request';
$activeModule = 'change';
$breadcrumbs  = [['Change Requests', '/change'], ['New Request', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Change Request</h1>
    <p class="page-subtitle">Submit a change for review and approval</p>
  </div>
  <a href="/change" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<?php if (!empty($_SESSION['change_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['change_error']) ?></div>
  <?php unset($_SESSION['change_error']); ?>
<?php endif; ?>

<form method="POST" action="/change/create" id="changeForm">
  <?= Security::csrfField() ?>

  <div class="two-col-layout" style="align-items:flex-start;gap:1.5rem">

    <!-- Main Details -->
    <div style="flex:3">
      <div class="card" style="margin-bottom:1.5rem">
        <div class="card-header">
          <h2 class="card-title"><i class="bi bi-file-earmark-text"></i> Change Details</h2>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label required">Title</label>
            <input type="text" name="title" class="form-control"
                   placeholder="Brief description of the change" required
                   value="<?= Security::h($_POST['title'] ?? '') ?>">
          </div>

          <div class="form-row">
            <div class="form-group flex-1">
              <label class="form-label required">Change Type</label>
              <select name="change_type" class="form-control" id="changeTypeSelect" required>
                <option value="normal"    <?= ($_POST['change_type'] ?? '') === 'normal'    ? 'selected' : '' ?>>Normal</option>
                <option value="standard"  <?= ($_POST['change_type'] ?? '') === 'standard'  ? 'selected' : '' ?>>Standard</option>
                <option value="emergency" <?= ($_POST['change_type'] ?? '') === 'emergency' ? 'selected' : '' ?>>Emergency</option>
              </select>
            </div>
            <div class="form-group flex-1">
              <label class="form-label required">Risk Level</label>
              <select name="risk_level" class="form-control" required>
                <option value="low"      <?= ($_POST['risk_level'] ?? '') === 'low'      ? 'selected' : '' ?>>Low</option>
                <option value="medium"   <?= ($_POST['risk_level'] ?? 'medium') === 'medium'   ? 'selected' : '' ?>>Medium</option>
                <option value="high"     <?= ($_POST['risk_level'] ?? '') === 'high'     ? 'selected' : '' ?>>High</option>
                <option value="critical" <?= ($_POST['risk_level'] ?? '') === 'critical' ? 'selected' : '' ?>>Critical</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label required">Description</label>
            <textarea name="description" class="form-control" rows="4" required
                      placeholder="Detailed description of what is being changed and why…"><?= Security::h($_POST['description'] ?? '') ?></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Implementation Date</label>
            <input type="datetime-local" name="implementation_date" class="form-control"
                   value="<?= Security::h($_POST['implementation_date'] ?? '') ?>">
          </div>

        </div>
      </div>

      <!-- Analysis & Planning -->
      <div class="card">
        <div class="card-header">
          <h2 class="card-title"><i class="bi bi-clipboard2-data"></i> Analysis &amp; Planning</h2>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label">Impact Analysis</label>
            <textarea name="impact_analysis" class="form-control" rows="4"
                      placeholder="Describe potential impacts on systems, users, processes…"><?= Security::h($_POST['impact_analysis'] ?? '') ?></textarea>
          </div>

          <div class="form-group" id="rollbackGroup">
            <label class="form-label required-emergency">
              Rollback Plan
              <span id="rollbackRequiredNote" class="text-muted text-sm" style="display:none">
                — required for emergency changes
              </span>
            </label>
            <textarea name="rollback_plan" class="form-control" id="rollbackPlan" rows="4"
                      placeholder="Step-by-step procedure to revert this change if it fails…"><?= Security::h($_POST['rollback_plan'] ?? '') ?></textarea>
          </div>

          <div class="form-group">
            <label class="form-label">Testing Plan</label>
            <textarea name="testing_plan" class="form-control" rows="4"
                      placeholder="How will you verify the change was successful? Include test cases…"><?= Security::h($_POST['testing_plan'] ?? '') ?></textarea>
          </div>

        </div>
      </div>
    </div>

    <!-- Sidebar Info -->
    <div style="flex:1;min-width:220px">
      <div class="card" style="margin-bottom:1rem">
        <div class="card-header">
          <h3 class="card-title" style="font-size:.9rem"><i class="bi bi-info-circle"></i> Change Types</h3>
        </div>
        <div class="card-body text-sm" style="line-height:1.6">
          <p><strong>Normal</strong> — Standard change with full CAB review cycle.</p>
          <p><strong>Standard</strong> — Pre-approved, low-risk, follows a known process.</p>
          <p style="margin:0"><strong>Emergency</strong> — Urgent change, expedited approval. Rollback plan required.</p>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <h3 class="card-title" style="font-size:.9rem"><i class="bi bi-shield-check"></i> Risk Levels</h3>
        </div>
        <div class="card-body text-sm" style="line-height:1.6">
          <p><strong style="color:var(--success)">Low</strong> — Minimal blast radius, easily reversible.</p>
          <p><strong style="color:var(--warning)">Medium</strong> — Moderate impact, rollback possible.</p>
          <p><strong style="color:var(--danger)">High</strong> — Significant impact, limited rollback window.</p>
          <p style="margin:0"><strong style="color:var(--danger)">Critical</strong> — Major systems/data at risk.</p>
        </div>
      </div>
    </div>

  </div>

  <div style="display:flex;gap:1rem;justify-content:flex-end;margin-top:1.5rem">
    <a href="/change" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-send"></i> Submit Change Request
    </button>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
(function () {
  'use strict';

  var typeSelect   = document.getElementById('changeTypeSelect');
  var rollbackPlan = document.getElementById('rollbackPlan');
  var note         = document.getElementById('rollbackRequiredNote');

  function toggleRollbackRequirement() {
    var isEmergency = typeSelect.value === 'emergency';
    rollbackPlan.required = isEmergency;
    if (note) note.style.display = isEmergency ? '' : 'none';
  }

  typeSelect.addEventListener('change', toggleRollbackRequirement);
  toggleRollbackRequirement();
})();
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
