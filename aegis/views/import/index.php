<?php
$breadcrumbs = $breadcrumbs ?? [['Import', null]];
$templates = [
  'risks'     => 'title,description,likelihood,impact,status,category,treatment_type',
  'vendors'   => 'name,category,website,description,risk_tier',
  'incidents' => 'title,description,severity',
];

$notes = [
  'risks' => [
    'label'  => 'Risks',
    'icon'   => 'bi-exclamation-triangle-fill',
    'color'  => 'var(--danger)',
    'fields' => [
      ['title',          'Required',  'string',  'Short descriptive title for the risk.'],
      ['description',    'Optional',  'string',  'Detailed description of the risk scenario.'],
      ['likelihood',     'Required',  '1–5',     '1 = Very Unlikely, 2 = Unlikely, 3 = Possible, 4 = Likely, 5 = Almost Certain.'],
      ['impact',         'Required',  '1–5',     '1 = Negligible, 2 = Minor, 3 = Moderate, 4 = Major, 5 = Catastrophic.'],
      ['status',         'Optional',  'enum',    'open / accepted / mitigated / closed / transferred. Defaults to <strong>open</strong>.'],
      ['category',       'Optional',  'string',  'Must match an existing Risk Category name exactly (case-sensitive). Leave blank to leave uncategorised.'],
      ['treatment_type', 'Optional',  'enum',    'mitigate / transfer / accept / avoid. Leave blank if not yet determined.'],
    ],
    'example' => 'Ransomware via Phishing,Employees targeted by credential-harvesting emails,4,5,open,Cybersecurity,mitigate',
  ],
  'vendors' => [
    'label'  => 'Vendors',
    'icon'   => 'bi-building',
    'color'  => 'var(--info)',
    'fields' => [
      ['name',        'Required', 'string', 'Full legal or trade name of the vendor.'],
      ['category',    'Optional', 'string', 'Vendor category (e.g. Cloud Provider, Software, Consultant, Staffing).'],
      ['website',     'Optional', 'URL',    'Must begin with <code>http://</code> or <code>https://</code>. Blank if unknown.'],
      ['description', 'Optional', 'string', 'What services or products this vendor provides.'],
      ['risk_tier',   'Optional', 'enum',   'critical / high / medium / low. Defaults to <strong>medium</strong>. Tier 1 = critical (data access / critical service), Tier 4 = low.'],
    ],
    'example' => 'Acme Cloud Corp,Cloud Provider,https://acmecorp.example,Primary IaaS provider,high',
  ],
  'incidents' => [
    'label'  => 'Incidents',
    'icon'   => 'bi-fire',
    'color'  => 'var(--orange)',
    'fields' => [
      ['title',       'Required', 'string', 'Short descriptive title for the incident.'],
      ['description', 'Optional', 'string', 'Summary of what occurred, initial indicators, or affected systems.'],
      ['severity',    'Required', 'enum',   'critical / high / medium / low. Determines SLA clock and escalation.'],
    ],
    'example' => 'Phishing Campaign — Finance Team,Multiple employees received credential-harvesting emails,high',
  ],
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Bulk Import</h1>
    <p class="page-subtitle">Import risks, vendors, or incidents from CSV files.</p>
  </div>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:420px 1fr;gap:24px;align-items:start">

<!-- ── Left: Upload form ─────────────────────────────────────────────────── -->
<div>
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-cloud-upload-fill"></i> Upload CSV</h3></div>
    <div class="card-body">
      <form method="POST" action="/import/upload" enctype="multipart/form-data">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label">Entity Type <span class="required">*</span></label>
          <select name="import_type" id="importType" class="form-control">
            <option value="">— Select type —</option>
            <option value="risks">Risks</option>
            <option value="vendors">Vendors</option>
            <option value="incidents">Incidents</option>
          </select>
        </div>

        <div id="templateBox" style="display:none;background:var(--bg-secondary);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:16px">
          <p style="font-size:13px;margin:0 0 8px;color:var(--text-muted)"><strong style="color:var(--text)">Required CSV columns:</strong></p>
          <code id="templateHeaders" style="font-size:12px;word-break:break-all;color:var(--text)"></code>
          <div style="margin-top:10px">
            <button type="button" id="btnDownloadTemplate" class="btn btn-sm btn-secondary">
              <i class="bi bi-download"></i> Download Template CSV
            </button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">CSV File <span class="required">*</span></label>
          <label class="file-drop" id="fileDropBulk" for="bulkCsvFile">
            <i class="bi bi-filetype-csv" style="font-size:2rem;color:var(--success)"></i>
            <p>Drag &amp; drop or <strong>click to upload</strong></p>
            <p class="text-muted">.csv format, max 10MB</p>
          </label>
          <input type="file" id="bulkCsvFile" name="csv_file" accept=".csv,.txt" required style="display:none"
                 data-change="showFileChange" data-drop-id="fileDropBulk" data-name-id="bulkCsvName" data-color="var(--success)">
          <div id="bulkCsvName" style="margin-top:8px;color:var(--success);display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          <div style="margin-top:10px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:8px;padding:10px 12px;font-size:0.8rem;">
            <div style="font-weight:600;color:var(--text);margin-bottom:5px;"><i class="bi bi-info-circle" style="color:var(--primary)"></i> Upload Reference</div>
            <table style="width:100%;border-collapse:collapse;font-size:0.78rem;">
              <thead><tr style="color:var(--text-muted)"><th scope="col" style="text-align:left;padding:2px 8px 2px 0;">Field</th><th scope="col" style="text-align:left;padding:2px 8px 2px 0;">Format</th><th scope="col" style="text-align:left;padding:2px 8px 2px 0;">Max Size</th><th scope="col" style="text-align:left;">Required</th></tr></thead>
              <tbody style="color:var(--text);">
                <tr><td style="padding:2px 8px 2px 0;font-family:monospace">csv_file</td><td style="padding:2px 8px 2px 0;">CSV (.csv) or plain text (.txt)</td><td style="padding:2px 8px 2px 0;">10 MB</td><td><strong style="color:var(--danger)">Yes</strong></td></tr>
              </tbody>
            </table>
            <div style="margin-top:5px;color:var(--text-muted);">Row 1 must be a header row. Download the template for the exact column layout expected.</div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full"><i class="bi bi-cloud-upload"></i> Import CSV</button>
      </form>
    </div>
  </div>

  <!-- General rules -->
  <div class="card" style="margin-top:16px">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle-fill"></i> Import Rules</h3></div>
    <div class="card-body" style="font-size:13px;padding:14px 18px">
      <ul style="margin:0;padding-left:18px;line-height:2.1">
        <li><strong>Atomic</strong> — if any row fails the entire file is rejected.</li>
        <li><strong>No dedup</strong> — every row creates a new record.</li>
        <li><strong>Header required</strong> — first row must be column names exactly as shown.</li>
        <li><strong>Column order</strong> — order does not matter; matched by name.</li>
        <li><strong>Optional fields</strong> — include header but leave cell blank to use default.</li>
        <li><strong>Encoding</strong> — save as UTF-8. Excel: <em>Save As → CSV UTF-8</em>.</li>
        <li><strong>Max size</strong> — 10 MB per upload.</li>
      </ul>
    </div>
  </div>
</div>

<!-- ── Right: Field reference (tabbed) ──────────────────────────────────── -->
<div class="card">
  <div class="card-header" style="padding:0 16px;border-bottom:1px solid var(--border)">
    <div style="display:flex;gap:0;border-bottom:none">
      <?php foreach ($notes as $type => $n): ?>
        <button class="import-tab-btn<?= $type === 'risks' ? ' active' : '' ?>"
                data-tab-target="notePane_<?= $type ?>"
                style="background:none;border:none;padding:13px 18px;cursor:pointer;font-size:13px;font-weight:600;
                       color:var(--text-muted);border-bottom:2px solid transparent;margin-bottom:-1px;
                       display:inline-flex;align-items:center;gap:6px;transition:all .15s"
                id="tab_<?= $type ?>">
          <i class="bi <?= $n['icon'] ?>" style="color:<?= $n['color'] ?>"></i> <?= $n['label'] ?>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <?php foreach ($notes as $type => $n): ?>
  <div id="notePane_<?= $type ?>" class="note-pane" style="<?= $type !== 'risks' ? 'display:none' : '' ?>">
    <div style="padding:14px 16px 8px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <span style="font-size:12px;color:var(--text-muted)">
        <i class="bi bi-info-circle"></i> Column names are case-sensitive and must match exactly.
      </span>
      <button type="button" class="btn btn-ghost btn-sm dl-tpl-btn" data-type="<?= $type ?>">
        <i class="bi bi-download"></i> Download Template
      </button>
    </div>
    <div style="overflow-x:auto">
      <table class="table" style="margin:0;font-size:13px">
        <thead>
          <tr>
            <th scope="col" style="width:140px">Column</th>
            <th scope="col" style="width:90px">Required?</th>
            <th scope="col" style="width:70px">Type</th>
            <th scope="col">Description &amp; Accepted Values</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($n['fields'] as $f): ?>
          <tr>
            <td><code style="font-size:12px"><?= $f[0] ?></code></td>
            <td>
              <?php if ($f[1] === 'Required'): ?>
                <span class="badge badge-danger" style="font-size:11px">Required</span>
              <?php else: ?>
                <span class="badge badge-secondary" style="font-size:11px">Optional</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--text-muted);font-size:12px"><?= $f[2] ?></td>
            <td style="font-size:12.5px"><?= $f[3] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:12px 16px;background:var(--bg-secondary);border-top:1px solid var(--border)">
      <p style="margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);font-weight:700">Example Row</p>
      <code style="font-size:11.5px;word-break:break-all;color:var(--text)"><?= $n['example'] ?></code>
    </div>
  </div>
  <?php endforeach; ?>
</div>

</div>

<script nonce="<?= Security::nonce() ?>">
const templates = <?= json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

// Tab switching
document.querySelectorAll('.import-tab-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.import-tab-btn').forEach(function(b) {
      b.style.color = 'var(--text-muted)';
      b.style.borderBottomColor = 'transparent';
    });
    document.querySelectorAll('.note-pane').forEach(function(p) { p.style.display = 'none'; });
    btn.style.color = 'var(--primary)';
    btn.style.borderBottomColor = 'var(--primary)';
    document.getElementById(btn.dataset.tabTarget).style.display = 'block';
  });
});

// Sync dropdown with tabs
document.getElementById('importType').addEventListener('change', function() {
  const type = this.value;
  const box  = document.getElementById('templateBox');
  if (type && templates[type]) {
    document.getElementById('templateHeaders').textContent = templates[type];
    box.style.display = 'block';
    var tabBtn = document.getElementById('tab_' + type);
    if (tabBtn) tabBtn.click();
  } else {
    box.style.display = 'none';
  }
});

// Download template buttons
function downloadTemplate(type) {
  const headers = templates[type];
  if (!headers) { alert('Please select an entity type first.'); return; }
  const blob = new Blob([headers + '\n'], { type: 'text/csv' });
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = type + '_template.csv';
  a.click();
}

document.getElementById('btnDownloadTemplate').addEventListener('click', function() {
  downloadTemplate(document.getElementById('importType').value);
});
document.querySelectorAll('.dl-tpl-btn').forEach(function(btn) {
  btn.addEventListener('click', function() { downloadTemplate(btn.dataset.type); });
});

// Activate first tab style
(function() {
  var first = document.querySelector('.import-tab-btn.active');
  if (first) { first.style.color = 'var(--primary)'; first.style.borderBottomColor = 'var(--primary)'; }
})();
</script>
