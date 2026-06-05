<?php
$csrf = Security::generateCsrfToken();
$respBadge = ['customer'=>'badge-primary','provider'=>'badge-info','shared'=>'badge-success'];
$breadcrumbs = [['RACI Matrix', '/raci'], ['Responsibilities', null]];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Shared Responsibility Matrix</h1>
    <p class="page-subtitle"><?= Security::h($package['name']) ?> — Define who owns each control: customer, provider, or shared</p>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="/raci/<?= (int)$package['id'] ?>" class="btn btn-secondary">RACI Matrix</a>
    <a href="/raci" class="btn btn-secondary">Back</a>
  </div>
</div>

<?php if (empty($controls)): ?>
<div class="card" style="text-align:center;padding:40px;">
  <p style="color:var(--text-muted);">No controls (level-2 objectives) found in this package.</p>
</div>
<?php else: ?>
<form method="POST" action="/raci/<?= (int)$package['id'] ?>/responsibility/save">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div style="display:flex;flex-direction:column;gap:16px;">
    <?php foreach ($controls as $c): ?>
    <div class="card">
      <div class="card-body" style="display:grid;grid-template-columns:220px 1fr;gap:20px;">
        <div>
          <div style="font-family:monospace;font-size:0.8rem;color:var(--text-muted);"><?= Security::h($c['code']) ?></div>
          <div style="font-weight:600;font-size:0.9rem;margin-top:4px;"><?= Security::h($c['title']) ?></div>
          <?php if ($c['responsibility']): ?>
          <div style="margin-top:8px;"><span class="badge <?= $respBadge[$c['responsibility']] ?? 'badge-secondary' ?>"><?= ucfirst($c['responsibility']) ?></span></div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <div class="form-group">
            <label class="form-label" style="font-size:0.78rem;">Responsibility</label>
            <select name="srm[<?= (int)$c['id'] ?>][responsibility]" class="form-control" style="max-width:200px;">
              <option value="customer" <?= ($c['responsibility']??'customer')==='customer'?'selected':'' ?>>Customer</option>
              <option value="provider" <?= ($c['responsibility']??'')==='provider'?'selected':'' ?>>Provider / Vendor</option>
              <option value="shared"   <?= ($c['responsibility']??'')==='shared'?'selected':'' ?>>Shared</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label" style="font-size:0.78rem;">Provider / Vendor Name</label>
              <input type="text" name="srm[<?= (int)$c['id'] ?>][provider_name]" class="form-control" value="<?= Security::h($c['provider_name'] ?? '') ?>" placeholder="e.g. AWS, Microsoft">
            </div>
            <div></div>
            <div class="form-group">
              <label class="form-label" style="font-size:0.78rem;">Customer Notes</label>
              <textarea name="srm[<?= (int)$c['id'] ?>][customer_notes]" class="form-control" rows="2" placeholder="Our responsibilities..."><?= Security::h($c['customer_notes'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
              <label class="form-label" style="font-size:0.78rem;">Provider Notes</label>
              <textarea name="srm[<?= (int)$c['id'] ?>][provider_notes]" class="form-control" rows="2" placeholder="Provider responsibilities..."><?= Security::h($c['provider_notes'] ?? '') ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:20px;display:flex;gap:10px;">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Responsibility Matrix</button>
    <a href="/raci" class="btn btn-secondary">Cancel</a>
  </div>
</form>
<?php endif; ?>
