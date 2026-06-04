<?php
$entityTypes = ['risk','policy','audit','incident','vendor','control','asset'];
$activeTab   = Security::sanitizeInput($_GET['tab'] ?? 'risk');
if (!in_array($activeTab, $entityTypes, true)) $activeTab = 'risk';
?>

<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Custom Fields</h1>
    <p class="page-subtitle">Define extra metadata fields for each entity type</p>
  </div>
  <button class="btn btn-primary" id="openAddFieldBtn"><i class="bi bi-plus-lg"></i> Add Field</button>
</div>

<!-- Add Field Form -->
<div id="addFieldCard" class="card" style="display:none;margin-bottom:20px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <strong>Add Custom Field</strong>
    <button type="button" class="btn btn-ghost btn-sm" id="closeAddFieldBtn"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="card-body">
    <form method="POST" action="/admin/custom-fields/save">
      <?= Security::csrfField() ?>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Entity Type <span class="required">*</span></label>
          <select name="entity_type" class="form-control" required>
            <?php foreach ($entityTypes as $et): ?>
              <option value="<?= $et ?>" <?= $activeTab === $et ? 'selected' : '' ?>><?= ucfirst($et) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Field Key <span class="required">*</span></label>
          <input type="text" name="field_key" class="form-control" placeholder="e.g. vendor_ref_id" required pattern="[a-z][a-z0-9_]{1,49}">
          <small class="form-hint">Lowercase letters, numbers, underscores. Starts with a letter.</small>
        </div>
        <div class="form-group">
          <label class="form-label">Label <span class="required">*</span></label>
          <input type="text" name="label" class="form-control" placeholder="Human-readable label" required>
        </div>
        <div class="form-group">
          <label class="form-label">Field Type <span class="required">*</span></label>
          <select name="field_type" class="form-control" required id="fieldTypeSelect">
            <option value="text">Text</option>
            <option value="textarea">Textarea</option>
            <option value="number">Number</option>
            <option value="date">Date</option>
            <option value="select">Select (dropdown)</option>
            <option value="checkbox">Checkbox</option>
            <option value="url">URL</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Sort Order</label>
          <input type="number" name="sort_order" class="form-control" value="0" min="0">
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:4px">
          <label class="toggle-switch" style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="hidden" name="required" value="">
            <input type="checkbox" name="required" value="1" id="requiredCheck">
            <span class="toggle-slider"></span>
            <span>Required field</span>
          </label>
        </div>
      </div>
      <div class="form-group" id="optionsGroup" style="display:none">
        <label class="form-label">Options <small class="text-muted">(one per line)</small></label>
        <textarea name="options" class="form-control" rows="4" placeholder="Option A&#10;Option B&#10;Option C"></textarea>
      </div>
      <div style="display:flex;gap:8px;margin-top:8px">
        <button type="submit" class="btn btn-primary">Save Field</button>
        <button type="button" class="btn btn-ghost" onclick="toggleAddForm()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Entity Type Tabs -->
<div class="tab-bar" style="display:flex;gap:4px;border-bottom:2px solid var(--border);margin-bottom:20px;flex-wrap:wrap">
  <?php foreach ($entityTypes as $et): ?>
    <a href="/admin/custom-fields?tab=<?= $et ?>"
       class="tab-item<?= $activeTab === $et ? ' active' : '' ?>"
       style="padding:10px 18px;text-decoration:none;font-weight:<?= $activeTab === $et ? '600' : '400' ?>;color:<?= $activeTab === $et ? 'var(--primary)' : 'var(--text-muted)' ?>;border-bottom:<?= $activeTab === $et ? '2px solid var(--primary)' : '2px solid transparent' ?>;margin-bottom:-2px">
      <?= ucfirst($et) ?>
      <?php $cnt = count($fields[$et] ?? []); if ($cnt): ?>
        <span class="badge" style="margin-left:6px;background:var(--primary-light,#e0e7ff);color:var(--primary);font-size:11px"><?= $cnt ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- Fields table for active tab -->
<div class="card">
  <div class="card-body p0">
    <?php $tabFields = $fields[$activeTab] ?? []; ?>
    <?php if ($tabFields): ?>
    <table class="table">
      <thead>
        <tr>
          <th>Label</th>
          <th>Key</th>
          <th>Type</th>
          <th>Options</th>
          <th>Required</th>
          <th>Sort</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tabFields as $f): ?>
        <tr>
          <td><strong><?= Security::h($f['label']) ?></strong></td>
          <td><code style="font-size:13px;background:var(--bg-subtle,#f8fafc);padding:2px 6px;border-radius:4px"><?= Security::h($f['field_key']) ?></code></td>
          <td><span class="badge" style="background:var(--bg-subtle,#f1f5f9);color:var(--text)"><?= Security::h($f['field_type']) ?></span></td>
          <td>
            <?php if ($f['field_type'] === 'select' && $f['options']): ?>
              <?php $opts = json_decode($f['options'], true) ?: []; ?>
              <span class="text-muted text-sm"><?= count($opts) ?> option(s)</span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= $f['is_required'] ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-gray">No</span>' ?></td>
          <td class="text-muted"><?= (int)$f['sort_order'] ?></td>
          <td>
            <form method="POST" action="/admin/custom-fields/<?= (int)$f['id'] ?>/delete" onsubmit="return confirm('Delete this custom field? This cannot be undone.')">
              <?= Security::csrfField() ?>
              <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger,#ef4444)" title="Delete field">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state-sm" style="padding:48px;text-align:center">
      <i class="bi bi-sliders" style="font-size:2rem;color:var(--text-muted)"></i>
      <p style="margin-top:12px;color:var(--text-muted)">No custom fields defined for <strong><?= ucfirst($activeTab) ?></strong> yet.</p>
      <button class="btn btn-primary btn-sm" onclick="toggleAddForm()" style="margin-top:8px"><i class="bi bi-plus-lg"></i> Add the first field</button>
    </div>
    <?php endif; ?>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function toggleAddForm() {
  var card = document.getElementById('addFieldCard');
  card.style.display = card.style.display === 'none' ? 'block' : 'none';
  if (card.style.display === 'block') {
    card.scrollIntoView({behavior:'smooth', block:'nearest'});
  }
}
function toggleOptionsField(val) {
  document.getElementById('optionsGroup').style.display = val === 'select' ? 'block' : 'none';
}
</script>
