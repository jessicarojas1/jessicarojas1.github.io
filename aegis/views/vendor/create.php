<?php
$pageTitle    = 'New Vendor';
$activeModule = 'vendor';
$breadcrumbs  = [['Vendor Risk', '/vendor'], ['New Vendor', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Add Vendor</h1>
    <p class="page-subtitle">Register a third-party vendor for risk assessment and management</p>
  </div>
  <div class="page-actions">
    <a href="/vendor" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<form method="POST" action="/vendor/create">
  <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

  <div class="two-col-layout">

    <!-- Left column -->
    <div style="flex:2;display:flex;flex-direction:column;gap:1rem">

      <div class="card">
        <div class="card-header">
          <div class="card-header-left"><i class="bi bi-building" style="color:var(--primary)"></i><span class="card-title">Vendor Information</span></div>
        </div>
        <div class="card-body">

          <div class="form-row">
            <div class="form-group" style="flex:2">
              <label class="form-label" for="name">Vendor Name <span style="color:#dc2626">*</span></label>
              <input type="text" id="name" name="name" class="form-control" placeholder="Company or service name" required autofocus>
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label" for="category">Category</label>
              <select id="category" name="category" class="form-control">
                <option value="">— Select —</option>
                <?php foreach (['Cloud Provider','SaaS','Hardware','Professional Services','Financial','Legal','Other'] as $cat): ?>
                  <option value="<?= $cat ?>"><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group" style="flex:1">
              <label class="form-label" for="website">Website</label>
              <input type="url" id="website" name="website" class="form-control" placeholder="https://…">
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label" for="country">Country</label>
              <input type="text" id="country" name="country" class="form-control" placeholder="e.g. United States">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="3" placeholder="Services provided, relationship overview…"></textarea>
          </div>

        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-header-left"><i class="bi bi-person-lines-fill" style="color:#d97706"></i><span class="card-title">Contact &amp; Contract</span></div>
        </div>
        <div class="card-body">

          <div class="form-row">
            <div class="form-group" style="flex:1">
              <label class="form-label" for="primary_contact">Primary Contact</label>
              <input type="text" id="primary_contact" name="primary_contact" class="form-control" placeholder="Contact name">
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label" for="contact_email">Contact Email</label>
              <input type="email" id="contact_email" name="contact_email" class="form-control" placeholder="contact@vendor.com">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group" style="flex:1">
              <label class="form-label" for="contract_start">Contract Start</label>
              <input type="date" id="contract_start" name="contract_start" class="form-control">
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label" for="contract_end">Contract End</label>
              <input type="date" id="contract_end" name="contract_end" class="form-control">
            </div>
          </div>

        </div>
      </div>

    </div>

    <!-- Right column -->
    <div style="display:flex;flex-direction:column;gap:1rem">

      <div class="card">
        <div class="card-header">
          <div class="card-header-left"><i class="bi bi-exclamation-triangle-fill" style="color:#d97706"></i><span class="card-title">Risk Classification</span></div>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label" for="risk_tier">Risk Tier</label>
            <select id="risk_tier" name="risk_tier" class="form-control" onchange="updateTierPreview(this.value)">
              <option value="critical">Critical</option>
              <option value="high">High</option>
              <option value="medium" selected>Medium</option>
              <option value="low">Low</option>
            </select>
          </div>

          <div id="tierPreview" style="padding:0.75rem;border-radius:8px;background:#0284c720;border:1px solid #0284c740;margin-bottom:1rem">
            <strong id="tierLabel" style="color:#0284c7">Medium Risk</strong>
            <p id="tierDesc" style="font-size:0.82rem;color:var(--text-muted);margin:4px 0 0">Periodic assessments required; monitor access.</p>
          </div>

          <div class="form-group">
            <label class="form-label" for="status">Status</label>
            <select id="status" name="status" class="form-control">
              <option value="active" selected>Active</option>
              <option value="inactive">Inactive</option>
              <option value="under_review">Under Review</option>
              <option value="terminated">Terminated</option>
            </select>
          </div>

          <div style="display:flex;flex-direction:column;gap:10px;padding-top:4px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
              <input type="checkbox" name="data_access" value="1" style="width:16px;height:16px">
              <span>Has access to sensitive data</span>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
              <input type="checkbox" name="critical_service" value="1" style="width:16px;height:16px">
              <span>Provides critical service</span>
            </label>
          </div>

        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <button type="submit" class="btn btn-primary" style="width:100%"><i class="bi bi-building-add"></i> Add Vendor</button>
          <a href="/vendor" class="btn btn-ghost" style="width:100%;margin-top:0.5rem;text-align:center;display:block">Cancel</a>
        </div>
      </div>

    </div>
  </div>
</form>

<script>
const tierData = {
  critical: { color:'#dc2626', label:'Critical Risk', desc:'Continuous monitoring; executive oversight required.' },
  high:     { color:'#d97706', label:'High Risk',     desc:'Frequent assessments; formal approval needed.' },
  medium:   { color:'#0284c7', label:'Medium Risk',   desc:'Periodic assessments required; monitor access.' },
  low:      { color:'#059669', label:'Low Risk',      desc:'Annual review; standard due diligence.' },
};
function updateTierPreview(val) {
  const d = tierData[val] || tierData.medium;
  const preview = document.getElementById('tierPreview');
  const label   = document.getElementById('tierLabel');
  const desc    = document.getElementById('tierDesc');
  label.textContent = d.label;
  label.style.color = d.color;
  preview.style.background = d.color + '20';
  preview.style.borderColor = d.color + '40';
  desc.textContent = d.desc;
}
updateTierPreview(document.getElementById('risk_tier').value);
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
