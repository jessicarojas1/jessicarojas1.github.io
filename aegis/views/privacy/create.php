<?php ob_start(); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Processing Activity</h1>
    <p class="page-subtitle">Add an entry to your Record of Processing Activities (GDPR Art. 30)</p>
  </div>
  <div class="page-actions">
    <a href="/privacy" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Cancel</a>
  </div>
</div>

<div class="card" style="max-width:800px">
  <div class="card-body">
    <form method="POST" action="/privacy/create">
      <?= Security::csrfField() ?>

      <h4 style="font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px">Basic Information</h4>

      <div class="form-group">
        <label class="form-label required">Activity Name</label>
        <input type="text" name="name" class="form-control" required maxlength="255"
               placeholder="e.g. Customer account management, Employee payroll processing">
      </div>

      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3" placeholder="Brief description of the processing activity"></textarea>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1">
          <label class="form-label">Data Controller</label>
          <input type="text" name="controller_name" class="form-control" placeholder="Organisation responsible for the data">
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">Data Processor</label>
          <input type="text" name="processor_name" class="form-control" placeholder="Third party processing on your behalf">
        </div>
      </div>

      <hr style="margin:20px 0;border-color:var(--border)">
      <h4 style="font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px">Processing Details</h4>

      <div class="form-group">
        <label class="form-label">Purpose of Processing</label>
        <textarea name="purpose" class="form-control" rows="3"
                  placeholder="Why is this data being processed? What is the business purpose?"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Legal Basis (GDPR Art. 6)</label>
        <select name="legal_basis" class="form-control">
          <option value="">— Select —</option>
          <option value="consent">Consent — data subject gave clear consent</option>
          <option value="contract">Contract — processing necessary for a contract</option>
          <option value="legal_obligation">Legal Obligation — required by law</option>
          <option value="vital_interests">Vital Interests — to protect someone's life</option>
          <option value="public_task">Public Task — public interest or official authority</option>
          <option value="legitimate_interest">Legitimate Interest — organisation's legitimate interests</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Categories of Data Subjects</label>
        <input type="text" name="data_subject_categories" class="form-control"
               placeholder="e.g. Customers, Employees, Website visitors, Suppliers">
      </div>

      <div class="form-group">
        <label class="form-label">Categories of Personal Data</label>
        <textarea name="data_categories" class="form-control" rows="2"
                  placeholder="e.g. Name, email, phone, IP address, financial data, health records, biometric data…"></textarea>
      </div>

      <hr style="margin:20px 0;border-color:var(--border)">
      <h4 style="font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px">Sharing & Retention</h4>

      <div class="form-group">
        <label class="form-label">Recipients / Disclosures</label>
        <textarea name="recipients" class="form-control" rows="2"
                  placeholder="Who receives or has access to this data? (internal teams, third parties, regulators)"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Third Country Transfers</label>
        <input type="text" name="third_country_transfers" class="form-control"
               placeholder="Countries outside EEA data is transferred to, and the safeguard used (e.g. SCCs, adequacy decision)">
      </div>

      <div class="form-group">
        <label class="form-label">Retention Period</label>
        <input type="text" name="retention_period" class="form-control" maxlength="255"
               placeholder="e.g. 7 years after contract end, Deleted 30 days after account closure">
      </div>

      <div class="form-group">
        <label class="form-label">Security Measures</label>
        <textarea name="security_measures" class="form-control" rows="2"
                  placeholder="Encryption at rest/in transit, access controls, pseudonymisation, audit logging…"></textarea>
      </div>

      <hr style="margin:20px 0;border-color:var(--border)">
      <h4 style="font-size:13px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px">DPIA</h4>

      <div class="form-group">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
          <input type="checkbox" name="dpia_required" value="1" id="dpiaRequired" data-change="toggleDpiaFields">
          <span>Data Protection Impact Assessment (DPIA) required</span>
        </label>
      </div>

      <div id="dpiaFields" style="display:none">
        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px">
              <input type="checkbox" name="dpia_completed" value="1">
              <span>DPIA completed</span>
            </label>
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">DPIA Date</label>
            <input type="date" name="dpia_date" class="form-control">
          </div>
        </div>
      </div>

      <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--border)">
        <a href="/privacy" class="btn btn-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Activity</button>
      </div>
    </form>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function toggleDpiaFields() {
  document.getElementById('dpiaFields').style.display = this.checked ? 'block' : 'none';
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
