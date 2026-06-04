<?php
$templates = [
  'risks'     => 'title,description,likelihood,impact,status,category,treatment_type',
  'vendors'   => 'name,category,website,description,risk_tier',
  'incidents' => 'title,description,severity',
];

$notes = [
  'risks' => [
    'label'  => 'Risks',
    'icon'   => 'bi-exclamation-triangle-fill',
    'color'  => '#ef4444',
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
    'color'  => '#0284c7',
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
    'color'  => '#f97316',
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

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

<!-- Import form -->
<div style="flex:0 0 460px;min-width:320px">
  <div class="card">
    <div class="card-body">
      <form method="POST" action="/import/upload" enctype="multipart/form-data">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label">Entity Type <span class="required">*</span></label>
          <select name="import_type" id="importType" class="form-control" onchange="onTypeChange()">
            <option value="">— Select type —</option>
            <option value="risks">Risks</option>
            <option value="vendors">Vendors</option>
            <option value="incidents">Incidents</option>
          </select>
        </div>

        <div id="templateBox" style="display:none;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:16px">
          <p style="font-size:13px;margin:0 0 8px;color:var(--text-muted)"><strong style="color:var(--text)">Required CSV columns:</strong></p>
          <code id="templateHeaders" style="font-size:12px;word-break:break-all"></code>
          <div style="margin-top:10px">
            <button type="button" onclick="downloadTemplate()" class="btn btn-sm btn-secondary">
              <i class="bi bi-download"></i> Download Template CSV
            </button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">CSV File <span class="required">*</span></label>
          <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
          <p class="form-hint">Max 10 MB. First row must be the header row.</p>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload"></i> Import</button>
      </form>
    </div>
  </div>

  <!-- General rules -->
  <div class="card" style="margin-top:16px">
    <div class="card-header"><h3><i class="bi bi-info-circle-fill"></i> General Rules</h3></div>
    <div class="card-body" style="font-size:13.5px">
      <ul style="margin:0;padding-left:18px;line-height:2.1">
        <li><strong>Atomic imports</strong> — if any row fails validation the entire file is rejected; no partial data is written.</li>
        <li><strong>No duplicate detection</strong> — every row creates a new record. De-duplicate your CSV before importing.</li>
        <li><strong>Header row required</strong> — the first row must be the column names exactly as shown in the templates.</li>
        <li><strong>Column order</strong> — order does not matter; columns are matched by name.</li>
        <li><strong>Optional fields</strong> — include the column header but leave the cell blank to use the default value.</li>
        <li><strong>Encoding</strong> — save your CSV as UTF-8. Excel users: <em>Save As → CSV UTF-8 (comma-delimited)</em>.</li>
        <li><strong>File size</strong> — maximum 10 MB per upload. Split larger files into batches.</li>
      </ul>
    </div>
  </div>
</div>

<!-- Per-entity field reference -->
<div style="flex:1;min-width:280px">
  <div id="dynamicNotes" style="display:none">
    <!-- filled by JS when a type is selected -->
  </div>

  <!-- Always-visible full reference -->
  <div id="allNotes">
    <?php foreach ($notes as $type => $n): ?>
    <div class="card" style="margin-bottom:20px">
      <div class="card-header" style="display:flex;align-items:center;gap:8px">
        <i class="bi <?= $n['icon'] ?>" style="color:<?= $n['color'] ?>"></i>
        <h3 style="margin:0"><?= $n['label'] ?> — Field Reference</h3>
      </div>
      <div class="card-body" style="padding:0">
        <table class="table" style="margin:0;font-size:13px">
          <thead>
            <tr>
              <th style="width:140px">Column</th>
              <th style="width:80px">Required?</th>
              <th style="width:80px">Type</th>
              <th>Description &amp; Accepted Values</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($n['fields'] as $f): ?>
            <tr>
              <td><code><?= $f[0] ?></code></td>
              <td>
                <?php if ($f[1] === 'Required'): ?>
                  <span class="badge badge-danger">Required</span>
                <?php else: ?>
                  <span class="badge badge-secondary">Optional</span>
                <?php endif; ?>
              </td>
              <td style="color:var(--text-muted)"><?= $f[2] ?></td>
              <td style="font-size:12.5px"><?= $f[3] ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="padding:12px 16px;background:var(--bg);border-top:1px solid var(--border)">
          <p style="margin:0 0 4px;font-size:12px;color:var(--text-muted);font-weight:600">EXAMPLE ROW</p>
          <code style="font-size:11.5px;word-break:break-all"><?= $n['example'] ?></code>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

</div><!-- end flex wrapper -->

<script nonce="<?= Security::nonce() ?>">
const templates = <?= json_encode($templates) ?>;

function onTypeChange() {
  const type = document.getElementById('importType').value;
  const box  = document.getElementById('templateBox');
  if (type && templates[type]) {
    document.getElementById('templateHeaders').textContent = templates[type];
    box.style.display = 'block';
  } else {
    box.style.display = 'none';
  }
}

function downloadTemplate() {
  const type    = document.getElementById('importType').value;
  const headers = templates[type];
  if (!headers) return;
  const blob = new Blob([headers + '\n'], { type: 'text/csv' });
  const a    = document.createElement('a');
  a.href     = URL.createObjectURL(blob);
  a.download = type + '_template.csv';
  a.click();
}
</script>
