<?php
$csrf = Security::generateCsrfToken();
$breadcrumbs = [['CUI Registry', '/cui'], [Security::h($item['inventory_number'] ?? 'Record'), null]];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($item['inventory_number']) ?></h1>
    <p class="page-subtitle">CUI Record</p>
  </div>
  <div style="display:flex;gap:10px;">
    <button class="btn btn-secondary" data-show-modal="cuiEditModal"><i class="bi bi-pencil"></i> Edit</button>
    <form method="POST" action="/cui/<?= (int)$item['id'] ?>/delete" data-confirm="Delete this CUI record?" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i></button>
    </form>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
  <div class="card">
    <div class="card-header"><h3 class="card-title">Data Details</h3></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:16px;">
      <div><div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Description</div><div><?= nl2br(Security::h($item['data_description'])) ?></div></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div><div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">CUI Category</div><div><?= Security::h($item['cui_category'] ?: '—') ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Storage Type</div><div><?= ucfirst(str_replace('_', ' ', $item['storage_type'])) ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">System</div><div><?= Security::h($item['system_name'] ?: '—') ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Linked Asset</div><div><?= Security::h($item['asset_name'] ?? '—') ?></div></div>
      </div>
      <?php if ($item['location_description']): ?>
      <div><div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Location</div><div><?= nl2br(Security::h($item['location_description'])) ?></div></div>
      <?php endif; ?>
      <?php if ($item['access_controls']): ?>
      <div><div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Access Controls</div><div><?= nl2br(Security::h($item['access_controls'])) ?></div></div>
      <?php endif; ?>
      <div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Encryption</div>
        <?php if ($item['is_encrypted']): ?>
          <span class="badge badge-success"><i class="bi bi-shield-check"></i> Encrypted</span>
          <?php if ($item['encryption_details']): ?> <span style="font-size:0.85rem;"><?= Security::h($item['encryption_details']) ?></span><?php endif; ?>
        <?php else: ?>
          <span class="badge badge-danger"><i class="bi bi-shield-x"></i> Not Encrypted</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Metadata</h3></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
      <div><div style="font-size:0.75rem;color:var(--text-muted);">Data Owner</div><div><?= Security::h($item['data_owner'] ?: '—') ?></div></div>
      <div><div style="font-size:0.75rem;color:var(--text-muted);">Created</div><div><?= date('M j, Y', strtotime($item['created_at'])) ?></div></div>
      <div><div style="font-size:0.75rem;color:var(--text-muted);">Last Updated</div><div><?= date('M j, Y', strtotime($item['updated_at'])) ?></div></div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="um-overlay" id="cuiEditModal">
  <div class="um-dialog" style="max-width:680px">
    <div class="um-header">
      <h3>Edit CUI Record</h3>
      <button class="um-close" data-close-modal="cuiEditModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/cui/<?= (int)$item['id'] ?>/update">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div style="display:flex;flex-direction:column;gap:14px;">
        <div class="form-group"><label class="form-label">Description</label><textarea name="data_description" class="form-control" rows="3" required><?= Security::h($item['data_description']) ?></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group"><label class="form-label">CUI Category</label><input type="text" name="cui_category" class="form-control" value="<?= Security::h($item['cui_category'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">Storage Type</label>
            <select name="storage_type" class="form-control">
              <?php foreach (['database','file_share','cloud','email','paper','other'] as $st): ?>
              <option value="<?= $st ?>" <?= $item['storage_type']===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">System Name</label><input type="text" name="system_name" class="form-control" value="<?= Security::h($item['system_name'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">Data Owner</label><input type="text" name="data_owner" class="form-control" value="<?= Security::h($item['data_owner'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">Asset</label>
            <select name="asset_id" class="form-control">
              <option value="">— None —</option>
              <?php foreach ($assets as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $item['asset_id']==$a['id']?'selected':'' ?>><?= Security::h($a['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Location Description</label><textarea name="location_description" class="form-control" rows="2"><?= Security::h($item['location_description'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Access Controls</label><textarea name="access_controls" class="form-control" rows="2"><?= Security::h($item['access_controls'] ?? '') ?></textarea></div>
        <div class="form-group"><label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_encrypted" <?= $item['is_encrypted']?'checked':'' ?>> Encrypted at rest</label></div>
        <div class="form-group"><label class="form-label">Encryption Details</label><input type="text" name="encryption_details" class="form-control" value="<?= Security::h($item['encryption_details'] ?? '') ?>"></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">Save</button>
        <button type="button" class="btn btn-secondary" data-close-modal="cuiEditModal">Cancel</button>
      </div>
    </form>
  </div>
</div>
