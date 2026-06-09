<?php
$editing      = !empty($process);
$pageTitle    = $editing ? 'Edit Process' : 'New Process';
$activeModule = 'processes';
$breadcrumbs  = [['Processes', '/processes'], [$editing ? $process['process_code'] : 'New', null]];
$action       = $editing ? '/processes/' . (int)$process['id'] . '/edit' : '/processes/create';
$relations    = $relations ?? [];
$preSpace     = (int)($_GET['space'] ?? ($process['space_id'] ?? 0));
$canPublish   = Auth::can('process.publish');
ob_start();
?>
<div class="page-header"><div><h1 class="page-title"><?= $editing ? 'Edit ' . Security::h($process['process_code']) : 'Create Process' ?></h1><p class="page-subtitle">Process definition, flow &amp; relationships</p></div></div>

<form method="POST" action="<?= $action ?>">
  <?= Security::csrfField() ?>
  <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
    <div>
      <div class="card" style="margin-bottom:18px"><div class="card-body">
        <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required value="<?= Security::h($process['name'] ?? '') ?>"></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"><?= Security::h($process['description'] ?? '') ?></textarea></div>
        <div class="form-group" style="margin-bottom:0"><label class="form-label">Process Flow</label><textarea name="diagram" class="form-control" rows="8" placeholder="Describe the steps of this process, one per line…"><?= Security::h($process['diagram'] ?? '') ?></textarea><div class="form-hint">Plain-text flow description. Rendered as preformatted text on the process page.</div></div>
      </div></div>

      <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-link-45deg"></i> Relationships</span></div></div><div class="card-body" id="relRows">
        <p class="form-hint" style="margin-top:0">Link this process to related policies, procedures, controls, risks or departments.</p>
        <?php
          $relRows = $relations ?: [['relation_type'=>'related_policy','target_label'=>'']];
          foreach ($relRows as $r):
        ?>
        <div class="form-row rel-row" style="align-items:flex-end;gap:10px">
          <div class="form-group" style="margin:0;flex:0 0 200px"><select name="relation_type[]" class="form-select">
            <?php foreach (['related_policy'=>'Policy','related_procedure'=>'Procedure','related_control'=>'Control','related_risk'=>'Risk','related_department'=>'Department'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($r['relation_type'] ?? '')===$k)?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
          </select></div>
          <div class="form-group" style="margin:0;flex:1"><input type="text" name="relation_label[]" class="form-control" placeholder="Name / identifier" value="<?= Security::h($r['target_label'] ?? '') ?>"></div>
        </div>
        <?php endforeach; ?>
        <button type="button" class="btn btn-sm btn-ghost" id="addRel" style="margin-top:8px"><i class="bi bi-plus"></i> Add relationship</button>
      </div></div>
    </div>

    <div>
      <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-sliders"></i> Process Metadata</span></div></div><div class="card-body">
        <div class="form-group"><label class="form-label">Space</label><select name="space_id" class="form-select"><option value="">— None —</option><?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ($preSpace==$s['id'])?'selected':'' ?>><?= Security::h($s['space_key'] . ' — ' . $s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Owner</label><select name="owner_id" class="form-select"><?php foreach ($users as $usr): ?><option value="<?= (int)$usr['id'] ?>" <?= (($process['owner_id'] ?? Auth::id())==$usr['id'])?'selected':'' ?>><?= Security::h($usr['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="<?= Security::h($process['department'] ?? '') ?>"></div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Version</label><input type="text" name="version" class="form-control" value="<?= Security::h($process['version'] ?? '1.0') ?>"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Status</label><select name="status" class="form-select">
            <?php foreach (['draft'=>'Draft','in_review'=>'In Review','published'=>'Published','retired'=>'Retired'] as $k=>$v): ?>
              <?php if ($k==='published' && !$canPublish) continue; ?>
              <option value="<?= $k ?>" <?= (($process['status'] ?? 'draft')===$k)?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select><?php if (!$canPublish): ?><div class="form-hint">Publishing requires the <code>process.publish</code> permission.</div><?php endif; ?></div>
        </div>
      </div></div>
      <div class="form-actions" style="margin-top:18px">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> <?= $editing ? 'Save' : 'Create' ?></button>
        <a href="<?= $editing ? '/processes/' . (int)$process['id'] : '/processes' ?>" class="btn btn-ghost">Cancel</a>
      </div>
    </div>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
(function(){
  var btn = document.getElementById('addRel');
  if(!btn) return;
  btn.addEventListener('click', function(){
    var rows = document.getElementById('relRows');
    var first = rows.querySelector('.rel-row');
    var clone = first.cloneNode(true);
    clone.querySelectorAll('input').forEach(function(i){ i.value=''; });
    first.parentNode.insertBefore(clone, btn);
  });
})();
</script>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
