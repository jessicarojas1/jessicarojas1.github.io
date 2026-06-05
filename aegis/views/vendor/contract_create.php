<?php
$breadcrumbs = $breadcrumbs ?? [['Vendors', '/vendor'], ['Contracts', '/vendor/contracts'], ['New Contract', null]];
// $vendor, $users are set by the controller
?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Contract</h1>
    <p class="page-subtitle">Adding contract for <?= Security::h($vendor['name']) ?></p>
  </div>
  <div class="page-actions">
    <a href="/vendor/<?= (int)$vendor['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Cancel</a>
  </div>
</div>

<div class="card" style="max-width:760px">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-file-earmark-plus" style="color:var(--primary)"></i>
      <span class="card-title">Contract Details</span>
    </div>
  </div>
  <div class="card-body">
    <form method="POST" action="/vendor/<?= (int)$vendor['id'] ?>/contract/save">
      <?= Security::csrfField() ?>

      <!-- Title -->
      <div class="form-group">
        <label class="form-label">Title <span style="color:#dc2626">*</span></label>
        <input type="text" name="title" class="form-control" required placeholder="e.g. Master Service Agreement 2025" maxlength="255">
      </div>

      <!-- Contract Number -->
      <div class="form-group">
        <label class="form-label">Contract Number</label>
        <input type="text" name="contract_number" class="form-control" placeholder="e.g. CON-2025-001" maxlength="100">
      </div>

      <!-- Status -->
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="active" selected>Active</option>
          <option value="draft">Draft</option>
          <option value="expired">Expired</option>
          <option value="terminated">Terminated</option>
        </select>
      </div>

      <!-- Value + Currency (side by side) -->
      <div class="form-row">
        <div class="form-group" style="flex:3">
          <label class="form-label">Contract Value</label>
          <input type="number" name="value" class="form-control" placeholder="0.00" min="0" step="0.01">
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">Currency</label>
          <input type="text" name="currency" class="form-control" value="USD" maxlength="3" placeholder="USD"
                 style="text-transform:uppercase" data-input="toUpperCaseInput">
        </div>
      </div>

      <!-- Dates (side by side) -->
      <div class="form-row">
        <div class="form-group" style="flex:1">
          <label class="form-label">Start Date <span style="color:#dc2626">*</span></label>
          <input type="date" name="start_date" class="form-control" required>
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">End Date</label>
          <input type="date" name="end_date" class="form-control">
        </div>
      </div>

      <!-- Auto-Renewal -->
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
          <input type="checkbox" name="auto_renewal" value="1" id="autoRenewalCheck" data-change="toggleNoticeField">
          <span>Auto-Renewal</span>
        </label>
        <small style="color:var(--text-muted);display:block;margin-top:4px">Check if this contract automatically renews at expiry.</small>
      </div>

      <!-- Renewal Notice Days (hidden unless auto_renewal checked) -->
      <div class="form-group" id="renewalNoticeDaysGroup" style="display:none">
        <label class="form-label">Renewal Notice Days</label>
        <input type="number" name="renewal_notice_days" class="form-control" value="30" min="1" max="365" style="max-width:160px">
        <small style="color:var(--text-muted)">How many days before expiry to send a renewal notice.</small>
      </div>

      <!-- Contract Owner -->
      <div class="form-group">
        <label class="form-label">Contract Owner</label>
        <select name="owner_id" class="form-control">
          <option value="">— Unassigned —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Description -->
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="4" placeholder="Key terms, scope, or notes about this contract..."></textarea>
      </div>

      <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:8px;border-top:1px solid var(--border)">
        <a href="/vendor/<?= (int)$vendor['id'] ?>" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Contract</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function toggleNoticeField() {
  var checked = document.getElementById('autoRenewalCheck').checked;
  document.getElementById('renewalNoticeDaysGroup').style.display = checked ? 'block' : 'none';
}
function toUpperCaseInput() { this.value = this.value.toUpperCase(); }
</script>
