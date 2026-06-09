<?php
$editing      = !empty($doc);
$pageTitle    = $editing ? 'Edit Document' : 'New Document';
$activeModule = 'documents';
$breadcrumbs  = [['Documents', '/documents'], [$editing ? $doc['document_code'] : 'New', null]];
$action       = $editing ? '/documents/' . (int)$doc['id'] . '/edit' : '/documents/create';
$relations    = $relations ?? [];
$preSpace     = (int)($_GET['space'] ?? ($doc['space_id'] ?? 0));
ob_start();
?>
<div class="page-header"><div><h1 class="page-title"><?= $editing ? 'Edit ' . Security::h($doc['document_code']) : 'Create Document' ?></h1><p class="page-subtitle">Document control metadata &amp; content</p></div></div>

<form method="POST" action="<?= $action ?>" enctype="multipart/form-data">
  <?= Security::csrfField() ?>
  <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
    <div>
      <div class="card" style="margin-bottom:18px"><div class="card-body">
        <div class="form-row">
          <div class="form-group" style="flex:2"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required value="<?= Security::h($doc['title'] ?? '') ?>"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Type *</label><select name="doc_type" class="form-select" required><?php foreach (View::docTypes() as $t): ?><option value="<?= $t ?>" <?= (($doc['doc_type'] ?? 'policy')===$t)?'selected':'' ?>><?= View::docTypeLabel($t) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"><?= Security::h($doc['description'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Body (optional rich content)</label>
          <?php $wId='docbody'; $wName='body'; $wValue=$doc['body'] ?? ''; require PALADIN_ROOT . '/views/partials/wysiwyg.php'; ?>
        </div>
      </div></div>

      <div class="card" style="margin-bottom:18px"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-paperclip"></i> Attached File</span></div></div><div class="card-body">
        <?php if ($editing && $doc['file_original_name']): ?><p class="form-hint">Current: <strong><?= Security::h($doc['file_original_name']) ?></strong> — uploading a new file replaces it.</p><?php endif; ?>
        <input type="file" name="file" class="form-control">
        <div class="form-hint" style="margin-top:6px"><strong>Field reference:</strong> <code>file</code> — allowed types &amp; size limit are set in Admin → Settings. Stored with a randomized filename; MIME &amp; extension validated server-side.</div>
      </div></div>

      <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-link-45deg"></i> Relationships</span></div></div><div class="card-body" id="relRows">
        <p class="form-hint" style="margin-top:0">Link this document to related processes, risks, controls or systems.</p>
        <?php
          $relRows = $relations ?: [['relation_type'=>'related_process','target_label'=>'']];
          foreach ($relRows as $r):
        ?>
        <div class="form-row rel-row" style="align-items:flex-end;gap:10px">
          <div class="form-group" style="margin:0;flex:0 0 200px"><select name="relation_type[]" class="form-select">
            <?php foreach (['related_process'=>'Process','related_risk'=>'Risk','related_control'=>'Control','related_system'=>'System','related_policy'=>'Policy','related_procedure'=>'Procedure'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($r['relation_type'] ?? '')===$k)?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
          </select></div>
          <div class="form-group" style="margin:0;flex:1"><input type="text" name="relation_label[]" class="form-control" placeholder="Name / identifier" value="<?= Security::h($r['target_label'] ?? '') ?>"></div>
        </div>
        <?php endforeach; ?>
        <button type="button" class="btn btn-sm btn-ghost" id="addRel" style="margin-top:8px"><i class="bi bi-plus"></i> Add relationship</button>
      </div></div>
    </div>

    <div>
      <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-sliders"></i> Control Metadata</span></div></div><div class="card-body">
        <div class="form-group"><label class="form-label">Space</label><select name="space_id" class="form-select"><option value="">— None —</option><?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ($preSpace==$s['id'])?'selected':'' ?>><?= Security::h($s['space_key'] . ' — ' . $s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Revision</label><input type="text" name="revision" class="form-control" value="<?= Security::h($doc['revision'] ?? '1.0') ?>"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Classification</label><select name="classification" class="form-select"><?php foreach (View::classifications() as $c): ?><option value="<?= $c ?>" <?= (($doc['classification'] ?? 'internal')===$c)?'selected':'' ?>><?= ucfirst($c) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label">Owner</label><select name="owner_id" class="form-select"><?php foreach ($users as $usr): ?><option value="<?= (int)$usr['id'] ?>" <?= (($doc['owner_id'] ?? Auth::id())==$usr['id'])?'selected':'' ?>><?= Security::h($usr['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Reviewer</label><select name="reviewer_id" class="form-select"><option value="">—</option><?php foreach ($users as $usr): ?><option value="<?= (int)$usr['id'] ?>" <?= (($doc['reviewer_id'] ?? 0)==$usr['id'])?'selected':'' ?>><?= Security::h($usr['name']) ?></option><?php endforeach; ?></select></div>
          <div class="form-group" style="flex:1"><label class="form-label">Approver</label><select name="approver_id" class="form-select"><option value="">—</option><?php foreach ($users as $usr): ?><option value="<?= (int)$usr['id'] ?>" <?= (($doc['approver_id'] ?? 0)==$usr['id'])?'selected':'' ?>><?= Security::h($usr['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="<?= Security::h($doc['department'] ?? '') ?>"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Business Unit</label><input type="text" name="business_unit" class="form-control" value="<?= Security::h($doc['business_unit'] ?? '') ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">Effective Date</label><input type="date" name="effective_date" class="form-control" value="<?= Security::h($doc['effective_date'] ?? '') ?>"></div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Review Date</label><input type="date" name="review_date" class="form-control" value="<?= Security::h($doc['review_date'] ?? '') ?>"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Expiration</label><input type="date" name="expiration_date" class="form-control" value="<?= Security::h($doc['expiration_date'] ?? '') ?>"></div>
        </div>
        <div class="form-group"><label class="perm-chk"><input type="checkbox" name="requires_ack" value="1" <?= !empty($doc['requires_ack'])?'checked':'' ?>> Require acknowledgement (read receipt)</label></div>
      </div></div>
      <div class="form-actions" style="margin-top:18px">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> <?= $editing ? 'Save' : 'Create' ?></button>
        <a href="<?= $editing ? '/documents/' . (int)$doc['id'] : '/documents' ?>" class="btn btn-ghost">Cancel</a>
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
