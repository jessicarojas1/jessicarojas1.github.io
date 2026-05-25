<div class="page-header">
  <div>
    <h1 class="page-title">New Playbook</h1>
    <p class="page-subtitle">Define a step-by-step incident response procedure.</p>
  </div>
  <div class="page-actions">
    <a href="/playbooks" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Playbooks</a>
  </div>
</div>

<form method="POST" action="/playbooks/create" id="playbook-form">
  <?= Security::csrfField() ?>

  <div style="display:flex;flex-direction:column;gap:20px;max-width:860px">

    <!-- Card 1: Playbook Details -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-journal-bookmark-fill" style="color:var(--primary)"></i>
          <span class="card-title">Playbook Details</span>
        </div>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px">

        <div class="form-group">
          <label class="form-label" for="pb-title">Title <span style="color:var(--danger)">*</span></label>
          <input
            type="text"
            id="pb-title"
            name="title"
            class="form-control"
            required
            maxlength="255"
            placeholder="e.g. Ransomware Response Playbook"
            value="<?= Security::h($_POST['title'] ?? '') ?>"
          >
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label class="form-label" for="pb-category">Category</label>
            <select id="pb-category" name="category" class="form-control">
              <?php
              $categories = [
                'general'        => 'General',
                'ransomware'     => 'Ransomware',
                'data_breach'    => 'Data Breach',
                'ddos'           => 'DDoS',
                'phishing'       => 'Phishing',
                'insider_threat' => 'Insider Threat',
                'system_failure' => 'System Failure',
                'compliance'     => 'Compliance',
              ];
              $selCat = $_POST['category'] ?? 'general';
              foreach ($categories as $val => $label):
              ?>
                <option value="<?= $val ?>" <?= $selCat === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group" style="flex:1">
            <label class="form-label" for="pb-severity">Severity Filter</label>
            <select id="pb-severity" name="severity_filter" class="form-control">
              <?php
              $sevOptions = ['' => 'Any Severity', 'critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
              $selSev = $_POST['severity_filter'] ?? '';
              foreach ($sevOptions as $val => $label):
              ?>
                <option value="<?= $val ?>" <?= $selSev === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
              Optionally restrict this playbook to incidents of a specific severity.
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="pb-desc">Description</label>
          <textarea
            id="pb-desc"
            name="description"
            class="form-control"
            rows="4"
            placeholder="Describe the purpose of this playbook and when it should be used…"
          ><?= Security::h($_POST['description'] ?? '') ?></textarea>
        </div>

      </div>
    </div>

    <!-- Card 2: Steps -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-list-ol" style="color:var(--primary)"></i>
          <span class="card-title">Response Steps</span>
        </div>
        <div class="card-header-right">
          <button type="button" class="btn btn-sm btn-ghost" id="add-step-btn">
            <i class="bi bi-plus-lg"></i> Add Step
          </button>
        </div>
      </div>
      <div class="card-body" style="padding:0">

        <div id="steps-container">
          <!-- Step rows injected by JS -->
        </div>

        <!-- Step template (hidden, cloned by JS) -->
        <template id="step-tpl">
          <div class="step-row" style="padding:16px 20px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;align-items:center;justify-content:space-between">
              <strong class="step-num-label" style="font-size:0.9rem;color:var(--text-muted)">Step 1</strong>
              <button type="button" class="btn btn-sm btn-ghost remove-step-btn" style="color:var(--danger)">
                <i class="bi bi-trash"></i> Remove
              </button>
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label text-sm">Step Title <span style="color:var(--danger)">*</span></label>
              <input
                type="text"
                name="step_title[]"
                class="form-control form-control-sm"
                placeholder="e.g. Isolate affected systems"
                required
                maxlength="255"
              >
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label text-sm">Description</label>
              <textarea
                name="step_desc[]"
                class="form-control form-control-sm"
                rows="2"
                placeholder="Detailed instructions for this step…"
              ></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div class="form-group" style="margin:0">
                <label class="form-label text-sm">Owner Role</label>
                <input
                  type="text"
                  name="step_role[]"
                  class="form-control form-control-sm"
                  placeholder="e.g. CISO, IT Lead"
                  maxlength="50"
                >
              </div>
              <div class="form-group" style="margin:0">
                <label class="form-label text-sm">Due Time (minutes)</label>
                <input
                  type="number"
                  name="step_minutes[]"
                  class="form-control form-control-sm"
                  min="1"
                  max="525600"
                  placeholder="e.g. 60 for 1 hour"
                >
              </div>
            </div>
          </div>
        </template>

      </div>
    </div>

    <!-- Submit -->
    <div style="display:flex;gap:12px">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i> Create Playbook
      </button>
      <a href="/playbooks" class="btn btn-ghost">Cancel</a>
    </div>

  </div>
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

    clone.querySelector('.remove-step-btn').addEventListener('click', function () {
      if (container.querySelectorAll('.step-row').length <= 1) {
        alert('A playbook must have at least one step.');
        return;
      }
      this.closest('.step-row').remove();
      renumberSteps();
    });

    container.appendChild(clone);
    renumberSteps();
  }

  addBtn.addEventListener('click', addStep);

  // Start with one default step
  addStep();

  document.getElementById('playbook-form').addEventListener('submit', function (e) {
    var titles = container.querySelectorAll('input[name="step_title[]"]');
    var hasTitle = false;
    titles.forEach(function (inp) {
      if (inp.value.trim()) hasTitle = true;
    });
    if (!hasTitle) {
      e.preventDefault();
      alert('Please add at least one step with a title.');
    }
  });
}());
</script>
