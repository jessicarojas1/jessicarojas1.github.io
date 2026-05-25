<?php
/**
 * views/approval/template_create.php — Create a new approval template.
 *
 * Variables provided by ApprovalController::createTemplate():
 *   $users  array  — active users for the "Required User" select
 */
?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Approval Template</h1>
    <p class="page-subtitle">Define a multi-step approval chain and the conditions under which it applies.</p>
  </div>
  <div class="page-actions">
    <a href="/admin/approval-templates" class="btn btn-ghost">
      <i class="bi bi-arrow-left"></i> Back to Templates
    </a>
  </div>
</div>

<form method="POST" action="/admin/approval-templates/save" id="template-form">
  <?= Security::csrfField() ?>

  <div style="display:flex;flex-direction:column;gap:20px;max-width:860px">

    <!-- ── Template basics ──────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-diagram-3" style="color:var(--primary)"></i>
          <span class="card-title">Template Details</span>
        </div>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px">

        <!-- Name -->
        <div class="form-group">
          <label class="form-label" for="name">
            Template Name <span style="color:var(--danger)">*</span>
          </label>
          <input
            type="text"
            id="name"
            name="name"
            class="form-control"
            required
            maxlength="200"
            placeholder="e.g. High-Risk Acceptance Approval"
            value="<?= Security::h($_POST['name'] ?? '') ?>"
          >
        </div>

        <!-- Entity type -->
        <div class="form-group">
          <label class="form-label" for="entity_type">
            Entity Type <span style="color:var(--danger)">*</span>
          </label>
          <select id="entity_type" name="entity_type" class="form-control" required>
            <option value="">— Select entity type —</option>
            <?php
            $entityTypes = [
                'risk'     => 'Risk',
                'policy'   => 'Policy',
                'change'   => 'Change Request',
                'audit'    => 'Audit',
                'incident' => 'Incident',
                'vendor'   => 'Vendor',
            ];
            foreach ($entityTypes as $val => $label):
                $sel = ($_POST['entity_type'] ?? '') === $val ? 'selected' : '';
            ?>
              <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Conditions -->
        <div class="form-group">
          <label class="form-label" for="conditions">
            Trigger Conditions
            <span class="text-muted text-sm" style="font-weight:400">
              (optional JSON — leave blank to always trigger)
            </span>
          </label>
          <textarea
            id="conditions"
            name="conditions"
            class="form-control"
            rows="3"
            placeholder='{"min_score": 15}  or  {"status_change": "accepted"}'
            style="font-family:var(--font-mono, monospace);font-size:0.85rem"
          ><?= Security::h($_POST['conditions'] ?? '') ?></textarea>
          <div class="form-hint text-muted text-sm" style="margin-top:4px">
            Supported keys:
            <code>min_score</code> (numeric threshold),
            <code>status_change</code> (target status string),
            <code>risk_tier</code> (tier label).
            Example: <code>{"min_score": 14}</code>
          </div>
        </div>

        <!-- Active flag -->
        <div class="form-group" style="display:flex;align-items:center;gap:10px">
          <input
            type="checkbox"
            id="is_active"
            name="is_active"
            value="1"
            <?= isset($_POST['is_active']) || !isset($_POST['name']) ? 'checked' : '' ?>
            style="width:16px;height:16px;cursor:pointer"
          >
          <label for="is_active" class="form-label" style="margin:0;cursor:pointer">
            Active — enable this template immediately
          </label>
        </div>

      </div>
    </div>

    <!-- ── Approval steps ────────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-list-ol" style="color:var(--primary)"></i>
          <span class="card-title">Approval Steps</span>
        </div>
        <div class="card-header-right">
          <button type="button" class="btn btn-sm btn-ghost" id="add-step-btn">
            <i class="bi bi-plus-lg"></i> Add Step
          </button>
        </div>
      </div>
      <div class="card-body" style="padding:0">

        <div id="steps-container">
          <!-- Step rows injected here (and one default below) -->
        </div>

        <!-- Step template (hidden, cloned by JS) -->
        <template id="step-tpl">
          <div class="step-row" style="padding:16px 20px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;align-items:center;justify-content:space-between">
              <strong class="step-num-label" style="font-size:0.9rem;color:var(--text-muted)">Step N</strong>
              <button type="button" class="btn btn-sm btn-ghost remove-step-btn" style="color:var(--danger)">
                <i class="bi bi-trash"></i> Remove
              </button>
            </div>
            <div style="display:grid;grid-template-columns:2fr 1fr 2fr 100px;gap:12px;align-items:end">
              <!-- Label -->
              <div class="form-group" style="margin:0">
                <label class="form-label text-sm">Step Label <span style="color:var(--danger)">*</span></label>
                <input
                  type="text"
                  name="step_label[]"
                  class="form-control form-control-sm"
                  placeholder="e.g. Manager Review"
                  required
                  maxlength="100"
                >
              </div>
              <!-- Required role -->
              <div class="form-group" style="margin:0">
                <label class="form-label text-sm">Required Role</label>
                <select name="step_role[]" class="form-control form-control-sm">
                  <option value="">Any / Specific user</option>
                  <option value="admin">Admin</option>
                  <option value="manager">Manager</option>
                  <option value="auditor">Auditor</option>
                  <option value="analyst">Analyst</option>
                  <option value="viewer">Viewer</option>
                </select>
              </div>
              <!-- Required user (overrides role if set) -->
              <div class="form-group" style="margin:0">
                <label class="form-label text-sm">Specific User <span class="text-muted text-sm">(optional)</span></label>
                <select name="step_user[]" class="form-control form-control-sm">
                  <option value="">— Any matching role —</option>
                  <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>">
                      <?= Security::h($u['name']) ?> (<?= Security::h($u['role']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <!-- Due hours -->
              <div class="form-group" style="margin:0">
                <label class="form-label text-sm">Due (hrs)</label>
                <input
                  type="number"
                  name="step_due_hours[]"
                  class="form-control form-control-sm"
                  min="1"
                  max="8760"
                  placeholder="48"
                >
              </div>
            </div>
          </div>
        </template>

      </div>
    </div>

    <!-- ── Submit ─────────────────────────────────────────────────────────── -->
    <div style="display:flex;gap:12px">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i> Create Template
      </button>
      <a href="/admin/approval-templates" class="btn btn-ghost">Cancel</a>
    </div>

  </div><!-- /max-width wrapper -->
</form>

<script>
(function () {
  'use strict';

  var container = document.getElementById('steps-container');
  var addBtn    = document.getElementById('add-step-btn');
  var tpl       = document.getElementById('step-tpl');

  function renumberSteps() {
    var rows = container.querySelectorAll('.step-row');
    rows.forEach(function (row, idx) {
      var lbl = row.querySelector('.step-num-label');
      if (lbl) lbl.textContent = 'Step ' + (idx + 1);
    });
  }

  function addStep() {
    var clone = tpl.content.cloneNode(true);

    // Wire the remove button
    clone.querySelector('.remove-step-btn').addEventListener('click', function () {
      // Must keep at least 1 step
      if (container.querySelectorAll('.step-row').length <= 1) {
        alert('An approval template must have at least one step.');
        return;
      }
      this.closest('.step-row').remove();
      renumberSteps();
    });

    container.appendChild(clone);
    renumberSteps();
  }

  // Attach add-step listener
  addBtn.addEventListener('click', addStep);

  // Start with one default step
  addStep();

  // Validate at least one non-empty label on submit
  document.getElementById('template-form').addEventListener('submit', function (e) {
    var labels = container.querySelectorAll('input[name="step_label[]"]');
    var hasLabel = false;
    labels.forEach(function (inp) {
      if (inp.value.trim()) hasLabel = true;
    });
    if (!hasLabel) {
      e.preventDefault();
      alert('Please add at least one approval step with a label.');
    }
  });
}());
</script>
