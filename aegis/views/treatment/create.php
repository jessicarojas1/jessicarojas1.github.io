<div class="page-header">
  <div>
    <h1 class="page-title">New Treatment Plan</h1>
    <p class="page-subtitle">For risk: <strong><?= Security::h($risk['title']) ?></strong></p>
  </div>
  <div class="page-actions">
    <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Risk</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<form method="POST" action="/risk/<?= (int)$risk['id'] ?>/treatment/create" id="treatment-form">
  <?= Security::csrfField() ?>

  <div style="display:flex;flex-direction:column;gap:20px;max-width:860px">

    <!-- Card 1: Plan Details -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-shield-check" style="color:var(--primary)"></i>
          <span class="card-title">Plan Details</span>
        </div>
      </div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px">

        <div class="form-group">
          <label class="form-label" for="tp-title">Title <span style="color:var(--danger)">*</span></label>
          <input
            type="text"
            id="tp-title"
            name="title"
            class="form-control"
            required
            maxlength="255"
            placeholder="e.g. Implement MFA for all privileged accounts"
            value="<?= Security::h($_POST['title'] ?? '') ?>"
          >
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label class="form-label" for="tp-strategy">Strategy</label>
            <select id="tp-strategy" name="strategy" class="form-control">
              <?php
              $strategies = ['mitigate' => 'Mitigate', 'transfer' => 'Transfer', 'accept' => 'Accept', 'avoid' => 'Avoid'];
              $selStrategy = $_POST['strategy'] ?? 'mitigate';
              foreach ($strategies as $val => $label):
              ?>
                <option value="<?= $val ?>" <?= $selStrategy === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label" for="tp-status">Status</label>
            <select id="tp-status" name="status" class="form-control">
              <?php
              $statuses = ['draft' => 'Draft', 'active' => 'Active'];
              $selStatus = $_POST['status'] ?? 'draft';
              foreach ($statuses as $val => $label):
              ?>
                <option value="<?= $val ?>" <?= $selStatus === $val ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label" for="tp-score">Target Risk Score</label>
            <input
              type="number"
              id="tp-score"
              name="target_score"
              class="form-control"
              min="1"
              max="25"
              placeholder="Residual score goal"
              value="<?= Security::h($_POST['target_score'] ?? '') ?>"
            >
          </div>
        </div>

        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label class="form-label" for="tp-owner">Owner</label>
            <select id="tp-owner" name="owner_id" class="form-control">
              <option value="">— Unassigned —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (($_POST['owner_id'] ?? '') == $u['id']) ? 'selected' : '' ?>>
                  <?= Security::h($u['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label" for="tp-start">Start Date</label>
            <input type="date" id="tp-start" name="start_date" class="form-control" value="<?= Security::h($_POST['start_date'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label" for="tp-target">Target Date</label>
            <input type="date" id="tp-target" name="target_date" class="form-control" value="<?= Security::h($_POST['target_date'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="tp-desc">Description</label>
          <textarea
            id="tp-desc"
            name="description"
            class="form-control"
            rows="4"
            placeholder="Describe the overall treatment approach and objectives…"
          ><?= Security::h($_POST['description'] ?? '') ?></textarea>
        </div>

      </div>
    </div>

    <!-- Card 2: Milestones -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-list-check" style="color:var(--primary)"></i>
          <span class="card-title">Milestones</span>
        </div>
        <div class="card-header-right">
          <button type="button" class="btn btn-sm btn-ghost" id="add-milestone-btn">
            <i class="bi bi-plus-lg"></i> Add Milestone
          </button>
        </div>
      </div>
      <div class="card-body" style="padding:0">

        <div id="milestones-container">
          <!-- Milestone rows injected by JS -->
        </div>

        <!-- Milestone template -->
        <template id="milestone-tpl">
          <div class="milestone-row" style="padding:16px 20px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;align-items:center;justify-content:space-between">
              <strong class="milestone-num-label" style="font-size:0.9rem;color:var(--text-muted)">Milestone 1</strong>
              <button type="button" class="btn btn-sm btn-ghost remove-milestone-btn" style="color:var(--danger)">
                <i class="bi bi-trash"></i> Remove
              </button>
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label text-sm">Title <span style="color:var(--danger)">*</span></label>
              <input
                type="text"
                name="step_title[]"
                class="form-control form-control-sm"
                placeholder="e.g. Deploy MFA to pilot group"
                maxlength="255"
              >
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label text-sm">Description</label>
              <textarea
                name="step_desc[]"
                class="form-control form-control-sm"
                rows="2"
                placeholder="Optional details…"
              ></textarea>
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label text-sm">Due Date</label>
              <input type="date" name="step_due[]" class="form-control form-control-sm">
            </div>
          </div>
        </template>

        <div id="no-milestones-msg" style="padding:20px;text-align:center;color:var(--text-muted);font-size:13px">
          No milestones added — milestones are optional and can be added after creation.
        </div>

      </div>
    </div>

    <!-- Submit -->
    <div style="display:flex;gap:12px">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i> Create Treatment Plan
      </button>
      <a href="/risk/<?= (int)$risk['id'] ?>" class="btn btn-ghost">Cancel</a>
    </div>

  </div>
</form>

<script>
(function () {
  'use strict';

  var container  = document.getElementById('milestones-container');
  var addBtn     = document.getElementById('add-milestone-btn');
  var tpl        = document.getElementById('milestone-tpl');
  var noMsgEl    = document.getElementById('no-milestones-msg');

  function renumber() {
    var rows = container.querySelectorAll('.milestone-row');
    rows.forEach(function (row, idx) {
      var lbl = row.querySelector('.milestone-num-label');
      if (lbl) lbl.textContent = 'Milestone ' + (idx + 1);
    });
    noMsgEl.style.display = rows.length === 0 ? 'block' : 'none';
  }

  function addMilestone() {
    var clone = tpl.content.cloneNode(true);
    clone.querySelector('.remove-milestone-btn').addEventListener('click', function () {
      this.closest('.milestone-row').remove();
      renumber();
    });
    container.appendChild(clone);
    renumber();
  }

  addBtn.addEventListener('click', addMilestone);

  // Start with no milestones (optional)
  renumber();
}());
</script>
