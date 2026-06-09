<?php
$pageTitle    = 'New Workflow';
$activeModule = 'workflows';
$breadcrumbs  = [['Workflows', '/workflows'], ['New', null]];
ob_start();
?>
<div class="page-header"><div><h1 class="page-title">Create Workflow Template</h1><p class="page-subtitle">Define an approval route with ordered steps</p></div></div>

<form method="POST" action="/workflows/create">
  <?= Security::csrfField() ?>
  <div class="card" style="margin-bottom:18px"><div class="card-body">
    <div class="form-row">
      <div class="form-group" style="flex:2"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required placeholder="Policy Approval"></div>
      <div class="form-group" style="flex:1"><label class="form-label">Type</label><select name="workflow_type" class="form-select"><?php foreach (['policy','procedure','process','change','record','evidence','corrective','general'] as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="flex:1"><label class="form-label">Approval Mode</label><select name="approval_mode" class="form-select"><option value="single">Single approver</option><option value="sequential" selected>Sequential</option><option value="parallel">Parallel</option><option value="consensus">Consensus</option></select></div>
    </div>
    <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
  </div></div>

  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-list-ol"></i> Steps</span></div></div><div class="card-body" id="stepRows">
    <p class="form-hint" style="margin-top:0">Add ordered approval steps. Assign each to a specific user or a role.</p>
    <?php for ($i = 0; $i < 2; $i++): ?>
    <div class="form-row step-row" style="align-items:flex-end;gap:10px">
      <div class="form-group" style="margin:0;flex:2"><label class="form-label">Step name</label><input type="text" name="step_name[]" class="form-control" placeholder="<?= $i===0?'Review':'Approval' ?>"></div>
      <div class="form-group" style="margin:0;flex:1"><label class="form-label">Role</label><select name="step_role[]" class="form-select"><option value="">—</option><?php foreach (['reviewer','approver','compliance_admin','space_owner'] as $r): ?><option value="<?= $r ?>" <?= ($i===0&&$r==='reviewer')||($i===1&&$r==='approver')?'selected':'' ?>><?= Security::h(Auth::roleLabel($r)) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0;flex:1"><label class="form-label">Or user</label><select name="step_user[]" class="form-select"><option value="">—</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0;flex:0 0 90px"><label class="form-label">SLA (h)</label><input type="number" name="step_sla[]" class="form-control" value="72" min="1"></div>
    </div>
    <?php endfor; ?>
    <button type="button" class="btn btn-sm btn-ghost" id="addStep" style="margin-top:8px"><i class="bi bi-plus"></i> Add step</button>
  </div></div>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Create Workflow</button>
    <a href="/workflows" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
(function(){
  var btn = document.getElementById('addStep');
  btn.addEventListener('click', function(){
    var rows = document.getElementById('stepRows');
    var first = rows.querySelector('.step-row');
    var clone = first.cloneNode(true);
    clone.querySelectorAll('input').forEach(function(i){ if(i.type==='number'){i.value='72';}else{i.value='';} });
    clone.querySelectorAll('select').forEach(function(s){ s.selectedIndex=0; });
    rows.insertBefore(clone, btn);
  });
})();
</script>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
