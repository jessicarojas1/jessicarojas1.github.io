<?php
$breadcrumbs  = $breadcrumbs  ?? [['POAM', null]];
$csrf = Security::generateCsrfToken();
$statusLabels = [
    'open'        => ['Open',        'badge-danger'],
    'in_progress' => ['In Progress', 'badge-warning'],
    'closed'      => ['Closed',      'badge-success'],
    'cancelled'   => ['Cancelled',   'badge-secondary'],
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">POA&amp;M Items</h1>
    <p class="page-subtitle">Plans of Action &amp; Milestones — track remediation of non-compliant controls</p>
  </div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-secondary" data-show-modal="importModal"><i class="bi bi-upload"></i> Import CSV</button>
    <button class="btn btn-secondary" data-show-modal="createModal"><i class="bi bi-pencil-square"></i> New Item</button>
    <button class="btn btn-primary" data-show-modal="generateModal"><i class="bi bi-lightning-fill"></i> Generate from Package</button>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success" style="background:color-mix(in srgb,var(--success) 15%,transparent);border:1px solid var(--success);color:var(--success);padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error" style="background:color-mix(in srgb,var(--danger) 15%,transparent);border:1px solid var(--danger);color:var(--danger);padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if (empty($items)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-list-check" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No POA&amp;M Items</h3>
  <p style="color:var(--text-muted);margin-bottom:20px;">Generate from a compliance package, create manually, or import via CSV.</p>
  <div style="display:flex;gap:8px;justify-content:center;">
    <button class="btn btn-primary" data-show-modal="generateModal"><i class="bi bi-lightning-fill"></i> Generate from Package</button>
    <button class="btn btn-secondary" data-show-modal="createModal"><i class="bi bi-pencil-square"></i> New Item</button>
  </div>
</div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead>
      <tr><th>POAM #</th><th>Title</th><th>Package</th><th>Owner</th><th>Status</th><th>Scheduled Completion</th><th>Milestones</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item):
      [$statusLabel, $statusClass] = $statusLabels[$item['status']] ?? ['Unknown', 'badge-secondary'];
      $totalM = (int)$item['total_milestones'];
      $doneM  = (int)$item['completed_milestones'];
    ?>
      <tr>
        <td><strong><?= Security::h($item['poam_number']) ?></strong></td>
        <td><?= Security::h($item['title']) ?></td>
        <td><?= Security::h($item['package_name'] ?? '—') ?></td>
        <td><?= Security::h($item['owner_name'] ?? '—') ?></td>
        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
        <td><?= $item['scheduled_completion'] ? date('M j, Y', strtotime($item['scheduled_completion'])) : '—' ?></td>
        <td>
          <?php if ($totalM > 0): ?>
            <span style="font-size:0.85rem;"><?= $doneM ?>/<?= $totalM ?></span>
            <div style="background:var(--border);border-radius:4px;height:4px;width:60px;margin-top:4px;">
              <div style="background:var(--success);border-radius:4px;height:4px;width:<?= $totalM > 0 ? round($doneM/$totalM*100) : 0 ?>%;"></div>
            </div>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><a href="/poam/<?= (int)$item['id'] ?>" class="btn btn-sm btn-secondary">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── Generate from Package Modal ── -->
<div class="um-overlay" id="generateModal">
  <div class="um-dialog">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <strong>Generate POA&amp;M from Package</strong>
      <button data-close-modal="generateModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
      <form method="POST" action="/poam/generate">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="form-group">
          <label class="form-label">Compliance Package</label>
          <select name="package_id" class="form-control" required>
            <option value="">— Select package —</option>
            <?php foreach ($packages as $pkg): ?>
              <option value="<?= (int)$pkg['id'] ?>"><?= Security::h($pkg['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <p style="font-size:0.85rem;color:var(--text-muted);margin-top:8px;">
          Creates POA&amp;M items for all non-compliant and partial controls in the selected package. Existing items are skipped.
        </p>
        <div style="display:flex;gap:8px;margin-top:16px;">
          <button type="submit" class="btn btn-primary"><i class="bi bi-lightning-fill"></i> Generate</button>
          <button type="button" data-close-modal="generateModal" class="btn btn-secondary">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Manual Create Modal ── -->
<div class="um-overlay" id="createModal">
  <div class="um-dialog">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">New POA&amp;M Item</h3>
      <button data-close-modal="createModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/poam/create">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Title <span style="color:var(--danger)">*</span></label>
          <input type="text" name="title" class="form-control" required placeholder="e.g. Remediate MFA gap in AC-2">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Weakness Description</label>
          <textarea name="weakness_description" class="form-control" rows="3" placeholder="Describe the identified weakness or gap..."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Compliance Package</label>
          <select name="package_id" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($packages as $pkg): ?>
              <option value="<?= (int)$pkg['id'] ?>"><?= Security::h($pkg['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Owner</label>
          <select name="owner_id" class="form-control">
            <option value="">— Unassigned —</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Scheduled Completion</label>
          <input type="date" name="scheduled_completion" class="form-control">
        </div>
        <div class="form-group">
          <label class="form-label">Required Resources</label>
          <input type="text" name="required_resources" class="form-control" placeholder="e.g. 40 hrs, $5,000">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes or context..."></textarea>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">Create Item</button>
        <button type="button" data-close-modal="createModal" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- ── CSV Import Modal ── -->
<div class="um-overlay" id="importModal">
  <div class="um-dialog">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">Import POA&amp;M Items via CSV</h3>
      <button data-close-modal="importModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/poam/import" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-group">
        <label class="form-label">CSV File <span style="color:var(--danger)">*</span></label>
        <label class="file-drop" id="fileDropPoam" for="poamCsvFile">
          <i class="bi bi-filetype-csv" style="font-size:2rem;color:var(--success)"></i>
          <p>Drag &amp; drop or <strong>click to upload</strong></p>
          <p class="text-muted">.csv format, max 10MB</p>
        </label>
        <input type="file" id="poamCsvFile" name="csv_file" accept=".csv,.txt" required style="display:none"
               data-change="showFileChange" data-drop-id="fileDropPoam" data-name-id="poamCsvName" data-color="#059669">
        <div id="poamCsvName" style="margin-top:8px;color:var(--success);display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
      </div>
      <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;"><i class="bi bi-cloud-upload"></i> Import CSV</button>
    </form>
    <div style="margin-top:10px;text-align:center">
      <button type="button" id="btnDlTemplate" class="btn btn-ghost btn-sm"><i class="bi bi-download"></i> Download CSV Template</button>
    </div>
    <div style="margin-top:8px;text-align:right">
      <button type="button" data-close-modal="importModal" class="btn btn-secondary btn-sm">Cancel</button>
    </div>

    <!-- Field Reference Guide -->
    <div style="margin-top:24px;border-top:1px solid var(--border);padding-top:20px;">
      <h4 style="font-size:0.95rem;margin-bottom:12px;">CSV Field Reference</h4>
      <table class="table" style="font-size:0.8rem;">
        <thead><tr><th>Column</th><th>Required</th><th>Type</th><th>Notes</th></tr></thead>
        <tbody>
          <tr><td><code>title</code></td><td><span class="badge badge-danger">Required</span></td><td>Text</td><td>Short name for the POA&amp;M item</td></tr>
          <tr><td><code>weakness_description</code></td><td><span class="badge badge-secondary">Optional</span></td><td>Text</td><td>Description of the identified gap or weakness</td></tr>
          <tr><td><code>package</code></td><td><span class="badge badge-secondary">Optional</span></td><td>Text</td><td>Exact compliance package name (e.g. <em>CMMC 2.0 Level 2</em>)</td></tr>
          <tr><td><code>owner_email</code></td><td><span class="badge badge-secondary">Optional</span></td><td>Email</td><td>Email of an existing AEGIS user</td></tr>
          <tr><td><code>scheduled_completion</code></td><td><span class="badge badge-secondary">Optional</span></td><td>Date</td><td>Target completion date (YYYY-MM-DD or MM/DD/YYYY)</td></tr>
          <tr><td><code>required_resources</code></td><td><span class="badge badge-secondary">Optional</span></td><td>Text</td><td>People, budget, or tools needed (e.g. <em>40 hrs, $5,000</em>)</td></tr>
          <tr><td><code>notes</code></td><td><span class="badge badge-secondary">Optional</span></td><td>Text</td><td>Any additional context or notes</td></tr>
        </tbody>
      </table>
      <p style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">All imported items are created with status <strong>Open</strong>. POAM numbers are auto-assigned.</p>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
(function() {
  // Template download
  document.getElementById('btnDlTemplate').addEventListener('click', function() {
    var csv = 'title,weakness_description,package,owner_email,scheduled_completion,required_resources,notes\n' +
              '"Remediate MFA gap","Multi-factor authentication not enforced for privileged accounts","CMMC 2.0 Level 2","admin@example.com","2025-12-31","40 hrs","Priority 1 remediation"\n' +
              '"Update access control policy","Access control policy last reviewed 3 years ago","","","2025-09-30","8 hrs",""';
    var blob = new Blob([csv], { type: 'text/csv' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'poam_import_template.csv';
    a.click();
  });
})();
</script>
