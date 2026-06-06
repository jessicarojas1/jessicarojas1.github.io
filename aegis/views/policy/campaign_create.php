<?php
// $policies = approved policies list (from controller)
$pageTitle    = 'New Campaign';
$activeModule = 'policy';
$breadcrumbs  = [['Policies', '/policy'], ['New Campaign', null]];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Attestation Campaign</h1>
    <p class="page-subtitle">Require users to read and acknowledge a policy</p>
  </div>
</div>

<div class="card" style="max-width:640px">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-pen-fill"></i> Campaign Details</h3>
  </div>
  <div class="card-body">
    <form method="POST" action="/policy/attestations/save">
      <?= Security::csrfField() ?>

      <div class="form-group">
        <label class="form-label" for="policy_id">Policy <span style="color:var(--danger)">*</span></label>
        <?php if ($policies): ?>
          <select name="policy_id" id="policy_id" class="form-control" required>
            <option value="">— Select an approved policy —</option>
            <?php foreach ($policies as $p): ?>
              <option value="<?= $p['id'] ?>"><?= Security::h($p['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">Only published/approved policies are shown.</div>
        <?php else: ?>
          <div class="alert-box" style="background:#fef9c3;border-color:#fbbf24;color:var(--warning)">
            <i class="bi bi-exclamation-triangle-fill"></i>
            No approved policies found. Publish a policy first before creating a campaign.
          </div>
          <select name="policy_id" id="policy_id" class="form-control" disabled>
            <option value="">No approved policies available</option>
          </select>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label class="form-label" for="title">Campaign Title <span style="color:var(--danger)">*</span></label>
        <input type="text" name="title" id="title" class="form-control"
               placeholder="e.g. Attest: Acceptable Use Policy — Q1 2026" required
               value="<?= Security::h($_POST['title'] ?? '') ?>">
        <div class="form-hint">A clear name users will see when asked to attest.</div>
      </div>

      <div class="form-group">
        <label class="form-label" for="due_date">Due Date <span class="text-muted">(optional)</span></label>
        <input type="date" name="due_date" id="due_date" class="form-control"
               value="<?= Security::h($_POST['due_date'] ?? '') ?>">
        <div class="form-hint">Leave blank for no deadline.</div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary" <?= !$policies ? 'disabled' : '' ?>>
          <i class="bi bi-check-lg"></i> Create Campaign
        </button>
        <a href="/policy/attestations" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
(function () {
  var policySelect = document.getElementById('policy_id');
  var titleInput   = document.getElementById('title');

  if (!policySelect || !titleInput) return;

  policySelect.addEventListener('change', function () {
    var selected = this.options[this.selectedIndex];
    if (selected && selected.value && !titleInput.value) {
      titleInput.value = 'Attest: ' + selected.text.trim();
    }
  });
})();
</script>
