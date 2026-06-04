<?php $csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">New CUI Record</h1>
    <p class="page-subtitle">Document where Controlled Unclassified Information exists</p>
  </div>
</div>

<form method="POST" action="/cui/create">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
    <div style="display:flex;flex-direction:column;gap:20px;">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Data Information</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
          <div class="form-group">
            <label class="form-label">Data Description <span style="color:var(--danger)">*</span></label>
            <textarea name="data_description" class="form-control" rows="3" required placeholder="Describe what CUI data this is and its purpose"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">CUI Category</label>
            <input type="text" name="cui_category" class="form-control" list="cui-categories" placeholder="e.g. PII, ITAR, PHI">
            <datalist id="cui-categories">
              <option value="PII">
              <option value="PHI">
              <option value="ITAR">
              <option value="Export Controlled">
              <option value="Financial">
              <option value="Legal">
              <option value="Procurement">
              <option value="Technical Data">
              <option value="Privacy Act">
              <option value="Law Enforcement">
            </datalist>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label">Linked Asset</label>
              <select name="asset_id" class="form-control">
                <option value="">— None —</option>
                <?php foreach ($assets as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= Security::h($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">System Name</label>
              <input type="text" name="system_name" class="form-control" placeholder="e.g. HR Portal">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Location Description</label>
            <textarea name="location_description" class="form-control" rows="2" placeholder="Where exactly is this data stored?"></textarea>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><h3 class="card-title">Access &amp; Security</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
          <div class="form-group">
            <label class="form-label">Access Controls</label>
            <textarea name="access_controls" class="form-control" rows="2" placeholder="Who has access and how is access controlled?"></textarea>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="is_encrypted" id="encCheck">
              <span class="form-label" style="margin:0;">Data is encrypted at rest</span>
            </label>
          </div>
          <div id="encDetails" style="display:none;">
            <div class="form-group">
              <label class="form-label">Encryption Details</label>
              <input type="text" name="encryption_details" class="form-control" placeholder="e.g. AES-256, AWS KMS">
            </div>
          </div>
        </div>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:20px;">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Classification</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
          <div class="form-group">
            <label class="form-label">Storage Type</label>
            <select name="storage_type" class="form-control">
              <option value="database">Database</option>
              <option value="file_share">File Share</option>
              <option value="cloud">Cloud Storage</option>
              <option value="email">Email</option>
              <option value="paper">Paper</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Data Owner</label>
            <input type="text" name="data_owner" class="form-control" placeholder="Name or team">
          </div>
        </div>
      </div>
    </div>
  </div>
  <div style="margin-top:20px;display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary">Create CUI Record</button>
    <a href="/cui" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
document.getElementById('encCheck').addEventListener('change', function() {
  document.getElementById('encDetails').style.display = this.checked ? 'block' : 'none';
});
</script>
