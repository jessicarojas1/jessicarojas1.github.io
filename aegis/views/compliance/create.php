<?php
$breadcrumbs = $breadcrumbs ?? [['Compliance', '/compliance'], ['New Package', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Create Compliance Package</h1>
    <p class="page-subtitle">Build a custom framework and add controls manually</p>
  </div>
  <a href="/compliance" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
</div>

<div class="two-col-layout">
  <div>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-shield-plus"></i> Package Details</h3>
      </div>
      <div class="card-body">
        <form method="POST" action="/compliance/create">
          <?= Security::csrfField() ?>

          <div class="form-group">
            <label class="form-label">Package Name <span style="color:var(--danger)">*</span></label>
            <input type="text" name="name" class="form-control" required autofocus
                   placeholder="e.g. HIPAA Security Rule 2024" value="<?= Security::h($_POST['name'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Version</label>
            <input type="text" name="version" class="form-control"
                   placeholder="1.0" value="<?= Security::h($_POST['version'] ?? '1.0') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"
                      placeholder="Brief description of this compliance package…"><?= Security::h($_POST['description'] ?? '') ?></textarea>
          </div>

          <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Create Package</button>
            <a href="/compliance" class="btn btn-ghost">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Right column: what happens next -->
  <div>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-info-circle-fill"></i> What happens next?</h3>
      </div>
      <div class="card-body">
        <div style="display:flex;flex-direction:column;gap:16px">
          <div style="display:flex;gap:12px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">1</div>
            <div>
              <div style="font-weight:600;font-size:13px">Create the package</div>
              <div class="text-muted" style="font-size:12px">Give it a name and optional description.</div>
            </div>
          </div>
          <div style="display:flex;gap:12px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">2</div>
            <div>
              <div style="font-weight:600;font-size:13px">Add domains / categories</div>
              <div class="text-muted" style="font-size:12px">Group your controls into logical sections (e.g. Access Control, Incident Response).</div>
            </div>
          </div>
          <div style="display:flex;gap:12px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--primary);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">3</div>
            <div>
              <div style="font-weight:600;font-size:13px">Add controls one-by-one or import</div>
              <div class="text-muted" style="font-size:12px">Type each control manually, or upload a CSV/PDF to populate a domain in bulk.</div>
            </div>
          </div>
          <div style="display:flex;gap:12px;align-items:flex-start">
            <div style="width:28px;height:28px;border-radius:50%;background:var(--success);color:var(--card-bg);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">✓</div>
            <div>
              <div style="font-weight:600;font-size:13px">Start assessing</div>
              <div class="text-muted" style="font-size:12px">Assign controls, record evidence, and track compliance progress.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-cloud-upload"></i> Prefer to import?</h3>
      </div>
      <div class="card-body">
        <p class="text-muted" style="margin-bottom:12px">Have a CSV, PDF, or JSON with your controls already?</p>
        <a href="/compliance/import" class="btn btn-ghost btn-full"><i class="bi bi-cloud-upload"></i> Import from file</a>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
