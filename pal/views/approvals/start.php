<?php
$pageTitle    = 'Start Approval';
$activeModule = 'approvals';
$breadcrumbs  = [['Approvals', '/approvals'], ['Start', null]];
$tplPre       = (int)($_GET['template'] ?? 0);
ob_start();
?>
<div class="page-header"><div><h1 class="page-title">Start an Approval</h1><p class="page-subtitle">Route an item through a workflow or directly to an approver</p></div></div>

<div class="card form-page"><div class="card-body">
  <form method="POST" action="/approvals/start">
    <?= Security::csrfField() ?>
    <div class="form-group"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required value="<?= Security::h($prefill['title']) ?>" placeholder="What needs approval?"></div>
    <div class="form-row">
      <div class="form-group" style="flex:1"><label class="form-label">Linked Item Type</label>
        <select name="entity_type" class="form-select">
          <option value="">— None —</option>
          <?php foreach (['document'=>'Document','page'=>'Page','process'=>'Process'] as $k=>$v): ?><option value="<?= $k ?>" <?= $prefill['entity_type']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:1"><label class="form-label">Linked Item ID</label><input type="number" name="entity_id" class="form-control" value="<?= $prefill['entity_id'] ?: '' ?>" placeholder="Optional"></div>
    </div>

    <div class="form-group"><label class="form-label">Workflow Template</label>
      <select name="template_id" class="form-select" id="tplSelect">
        <option value="">— Ad-hoc single approver —</option>
        <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tplPre===(int)$t['id']?'selected':'' ?>><?= Security::h($t['name']) ?> (<?= Security::h(ucfirst($t['approval_mode'])) ?>)</option><?php endforeach; ?>
      </select>
      <div class="form-hint">Choose a predefined route, or leave blank to pick a single approver below.</div>
    </div>

    <div id="adhocBlock" class="form-row">
      <div class="form-group" style="flex:1"><label class="form-label">Approver (user)</label><select name="approver_id" class="form-select"><option value="">—</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="flex:1"><label class="form-label">Or approver role</label><select name="approver_role" class="form-select"><option value="approver">Approver</option><option value="compliance_admin">Compliance Administrator</option><option value="space_owner">Space Owner</option></select></div>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><i class="bi bi-send"></i> Route for Approval</button>
      <a href="/approvals" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div></div>

<script nonce="<?= Security::nonce() ?>">
(function(){
  var sel = document.getElementById('tplSelect');
  var adhoc = document.getElementById('adhocBlock');
  function toggle(){ adhoc.style.display = sel.value ? 'none' : ''; }
  sel.addEventListener('change', toggle); toggle();
})();
</script>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
