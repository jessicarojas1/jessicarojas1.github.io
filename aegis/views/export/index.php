<?php ob_start(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Export Data</h1>
    <p class="page-subtitle">Download GRC data as CSV or JSON for reporting, backup, or external analysis</p>
  </div>
</div>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

<!-- Single export -->
<div style="flex:1;min-width:300px">
  <div class="card">
    <div class="card-header"><h3><i class="bi bi-download"></i> Quick Export</h3></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
      <p class="text-sm text-muted">Export a single data set in your preferred format.</p>
      <form method="POST" action="/export/download">
        <?= Security::csrfField() ?>
        <div class="form-group">
          <label class="form-label">Data Type</label>
          <select name="type" class="form-control">
            <?php foreach ($exportTypes as $key => $meta): ?>
              <?php if ($meta['perm'] !== 'admin' || Auth::role() === 'admin'): ?>
                <option value="<?= Security::h($key) ?>"><?= Security::h($meta['label']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Format</label>
          <div style="display:flex;gap:16px;margin-top:4px">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
              <input type="radio" name="format" value="csv" checked> CSV (Excel-compatible)
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
              <input type="radio" name="format" value="json"> JSON
            </label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="bi bi-file-earmark-arrow-down"></i> Download
        </button>
      </form>
    </div>
  </div>
</div>

<!-- Bulk export -->
<div style="flex:1;min-width:300px">
  <div class="card">
    <div class="card-header"><h3><i class="bi bi-file-earmark-zip"></i> Full Export (ZIP)</h3></div>
    <div class="card-body">
      <p class="text-sm text-muted" style="margin-bottom:16px">Download all selected data sets as a single ZIP archive of CSV files.</p>
      <form method="POST" action="/export/download-all">
        <?= Security::csrfField() ?>
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
          <?php foreach ($exportTypes as $key => $meta): ?>
            <?php if ($meta['perm'] !== 'admin' || Auth::role() === 'admin'): ?>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
              <input type="checkbox" name="types[]" value="<?= Security::h($key) ?>" checked>
              <span><?= Security::h($meta['label']) ?></span>
            </label>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-secondary" style="width:100%">
          <i class="bi bi-archive"></i> Download ZIP
        </button>
      </form>
    </div>
  </div>
</div>

<!-- Info panel -->
<div style="flex:1;min-width:260px">
  <div class="card">
    <div class="card-header"><h3><i class="bi bi-info-circle"></i> Export Notes</h3></div>
    <div class="card-body">
      <ul class="text-sm" style="margin:0;padding-left:20px;line-height:1.8">
        <li>CSV files include a UTF-8 BOM for correct Excel display</li>
        <li>All timestamps are in UTC</li>
        <li>Activity Log exports are capped at 50,000 rows</li>
        <li>Sensitive fields (password hashes, secrets) are never exported</li>
        <li>All exports are logged in the activity log</li>
        <?php if (Auth::role() === 'admin'): ?>
        <li>Activity Log export is available to admins only</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <div class="card-header"><h3><i class="bi bi-clock-history"></i> GDPR / Data Portability</h3></div>
    <div class="card-body">
      <p class="text-sm text-muted">AEGIS exports support GDPR Article 20 data portability requirements. Use the Full Export to produce a complete data package.</p>
      <p class="text-sm text-muted" style="margin-top:8px">For automated, scheduled exports configure <a href="/metrics" class="text-link">report schedules</a> under Metrics.</p>
    </div>
  </div>
</div>

</div>
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
?>
