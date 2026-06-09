<?php
$pageTitle    = 'Document Numbering';
$activeModule = 'admin_numbering';
$breadcrumbs  = [['Administration', '/admin'], ['Document Numbering', null]];
ob_start();
$labels = [
  'policy' => 'Policy', 'procedure' => 'Procedure', 'process' => 'Process', 'standard' => 'Standard',
  'guideline' => 'Guideline', 'work_instruction' => 'Work Instruction', 'plan' => 'Plan', 'form' => 'Form',
  'template' => 'Template', 'record' => 'Record', 'evidence' => 'Evidence', 'training' => 'Training',
];
$sep = $config['separator']; $pad = (int)$config['pad'];
$sample = $config['prefixes']['policy'] . $sep . str_pad('1', $pad, '0', STR_PAD_LEFT);
?>
<div class="page-header">
  <div><h1 class="page-title">Document Numbering</h1><p class="page-subtitle">Define how controlled-document codes are auto-generated per type.</p></div>
</div>

<div class="card" style="max-width:720px">
  <div class="card-body">
    <form method="POST" action="/admin/numbering">
      <?= Security::csrfField() ?>
      <div class="form-row" style="gap:16px">
        <div class="form-group" style="flex:1">
          <label class="form-label" for="separator">Separator</label>
          <input type="text" id="separator" name="separator" class="form-control" maxlength="3" value="<?= Security::h($sep) ?>">
          <div class="form-hint">Between prefix and number (e.g. <code>-</code>).</div>
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label" for="pad">Number padding</label>
          <input type="number" id="pad" name="pad" class="form-control" min="1" max="8" value="<?= $pad ?>">
          <div class="form-hint">Zero-padded width, e.g. 4 → <code>0001</code>.</div>
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">Example</label>
          <div class="form-control" style="background:var(--card-bg);font-family:ui-monospace,monospace"><?= Security::h($sample) ?></div>
        </div>
      </div>

      <hr style="border:none;border-top:1px solid var(--border-light);margin:8px 0 16px">
      <label class="form-label">Prefix per document type</label>
      <div class="form-row" style="flex-wrap:wrap;gap:12px;margin-top:6px">
        <?php foreach ($labels as $type => $label): ?>
          <div class="form-group" style="flex:0 0 calc(33% - 12px);min-width:160px;margin-bottom:0">
            <label class="form-label" style="font-weight:400" for="prefix_<?= $type ?>"><?= Security::h($label) ?></label>
            <input type="text" id="prefix_<?= $type ?>" name="prefix[<?= $type ?>]" class="form-control" maxlength="10" value="<?= Security::h($config['prefixes'][$type] ?? '') ?>" style="text-transform:uppercase">
          </div>
        <?php endforeach; ?>
      </div>

      <div class="form-actions" style="margin-top:18px"><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Numbering</button></div>
      <div class="form-hint" style="margin-top:10px"><i class="bi bi-info-circle"></i> Existing document codes are never changed; this affects newly created documents only.</div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
