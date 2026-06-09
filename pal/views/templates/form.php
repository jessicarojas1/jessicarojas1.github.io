<?php
$pageTitle    = 'New Template';
$activeModule = 'templates';
$breadcrumbs  = [['Templates', '/templates'], ['New', null]];
$categories   = [
    'document' => 'Document', 'page' => 'Page', 'process' => 'Process',
    'meeting' => 'Meeting', 'project' => 'Project', 'risk' => 'Risk', 'audit' => 'Audit',
];
ob_start();
?>
<div class="page-header"><div><h1 class="page-title">Create Template</h1><p class="page-subtitle">A reusable starting point for new content</p></div></div>

<form method="POST" action="/templates/create">
  <?= Security::csrfField() ?>
  <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
    <div>
      <div class="card" style="margin-bottom:18px"><div class="card-body">
        <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required value="<?= Security::h($template['name'] ?? '') ?>"></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"><?= Security::h($template['description'] ?? '') ?></textarea></div>
        <div class="form-group" style="margin-bottom:0"><label class="form-label">Body</label>
          <?php $wId='tplbody'; $wName='body'; $wValue=$template['body'] ?? ''; require PAL_ROOT . '/views/partials/wysiwyg.php'; ?>
        </div>
      </div></div>
    </div>

    <div>
      <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-sliders"></i> Settings</span></div></div><div class="card-body">
        <div class="form-group"><label class="form-label">Category *</label>
          <select name="category" class="form-select" required>
            <?php foreach ($categories as $key => $label): ?><option value="<?= Security::h($key) ?>" <?= (($template['category'] ?? 'document')===$key)?'selected':'' ?>><?= Security::h($label) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Document Type</label>
          <select name="doc_type" class="form-select">
            <option value="">— None —</option>
            <?php foreach (View::docTypes() as $t): ?><option value="<?= Security::h($t) ?>" <?= (($template['doc_type'] ?? '')===$t)?'selected':'' ?>><?= Security::h(View::docTypeLabel($t)) ?></option><?php endforeach; ?>
          </select>
          <div class="form-hint">Only applied when the category is <strong>Document</strong>.</div>
        </div>
        <div class="form-group" style="margin-bottom:0"><label class="perm-chk"><input type="checkbox" name="is_active" value="1" <?= (!isset($template) || !empty($template['is_active']))?'checked':'' ?>> Active (available for use)</label></div>
      </div></div>
      <div class="form-actions" style="margin-top:18px">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Create</button>
        <a href="/templates" class="btn btn-ghost">Cancel</a>
      </div>
    </div>
  </div>
</form>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
