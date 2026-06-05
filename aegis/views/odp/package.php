<?php
$csrf = Security::generateCsrfToken();
$breadcrumbs = [['ODP', '/odp'], [Security::h($package['name'] ?? 'Package'), null]];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">ODP Center — <?= Security::h($package['name']) ?></h1>
    <p class="page-subtitle"><?= Security::h($package['standard_code']) ?> · Define organizationally defined parameters for each control</p>
  </div>
  <a href="/odp" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> All Packages</a>
</div>

<?php if (empty($controls)): ?>
<div class="card" style="text-align:center;padding:40px;">
  <p style="color:var(--text-muted);">No controls found in this package.</p>
</div>
<?php else: ?>

<!-- Add new ODP -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-header"><h3 class="card-title">Add ODP Entry</h3></div>
  <div class="card-body">
    <form method="POST" action="/odp/save" style="display:grid;grid-template-columns:2fr 1fr 2fr 2fr auto;gap:12px;align-items:end;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
      <div class="form-group" style="margin:0;">
        <label class="form-label">Control</label>
        <select name="objective_id" class="form-control" required>
          <option value="">— Select control —</option>
          <?php foreach ($controls as $ctrl): ?>
          <option value="<?= (int)$ctrl['id'] ?>"><?= Security::h($ctrl['code']) ?> — <?= Security::h(mb_substr($ctrl['title'], 0, 50)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Parameter Name</label>
        <input type="text" name="parameter_name" class="form-control" required placeholder="e.g. session_timeout">
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Value</label>
        <input type="text" name="parameter_value" class="form-control" placeholder="e.g. 15 minutes">
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" placeholder="Optional rationale">
      </div>
      <button type="submit" class="btn btn-primary">Add</button>
    </form>
  </div>
</div>

<?php foreach ($controls as $ctrl): if (empty($ctrl['odps'])) continue; ?>
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;align-items:center;gap:12px;">
    <span style="background:var(--primary);color:#fff;padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;font-family:monospace;"><?= Security::h($ctrl['code']) ?></span>
    <h3 class="card-title" style="margin:0;"><?= Security::h($ctrl['title']) ?></h3>
  </div>
  <div class="card-body">
    <table class="table">
      <thead><tr><th>Parameter</th><th>Value</th><th>Notes</th><th>Updated</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($ctrl['odps'] as $odp): ?>
        <tr>
          <td style="font-family:monospace;font-weight:600;"><?= Security::h($odp['parameter_name']) ?></td>
          <td>
            <form method="POST" action="/odp/save" style="display:flex;gap:8px;align-items:center;margin:0;">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
              <input type="hidden" name="objective_id" value="<?= (int)$ctrl['id'] ?>">
              <input type="hidden" name="parameter_name" value="<?= Security::h($odp['parameter_name']) ?>">
              <input type="text" name="parameter_value" class="form-control" value="<?= Security::h($odp['parameter_value'] ?? '') ?>" style="max-width:200px;">
              <input type="text" name="notes" class="form-control" value="<?= Security::h($odp['notes'] ?? '') ?>" placeholder="Notes" style="max-width:150px;">
              <button type="submit" class="btn btn-sm btn-primary">Save</button>
            </form>
          </td>
          <td style="font-size:0.8rem;color:var(--text-muted);"><?= Security::h($odp['notes'] ?? '') ?></td>
          <td style="font-size:0.8rem;color:var(--text-muted);"><?= $odp['updated_at'] ? date('M j, Y', strtotime($odp['updated_at'])) : '—' ?></td>
          <td></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<?php
$controlsWithoutODP = array_filter($controls, fn($c) => empty($c['odps']));
if (!empty($controlsWithoutODP)): ?>
<div class="card">
  <div class="card-header"><h3 class="card-title" style="color:var(--text-muted);">Controls Without ODPs (<?= count($controlsWithoutODP) ?>)</h3></div>
  <div class="card-body">
    <p style="font-size:0.85rem;color:var(--text-muted);">The following controls do not yet have any ODP entries defined:</p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
      <?php foreach ($controlsWithoutODP as $ctrl): ?>
      <span style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:0.8rem;font-family:monospace;"><?= Security::h($ctrl['code']) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
