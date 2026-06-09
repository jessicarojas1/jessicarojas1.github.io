<?php
$pageTitle    = 'Edit Workflow';
$activeModule = 'admin_workflows';
$breadcrumbs  = [['Administration', '/admin'], ['Workflows', '/workflows'], [$wf['name'], '/workflows/' . (int)$wf['id']], ['Edit', null]];
ob_start();
$roleOpts = ['reviewer','approver','compliance_admin','space_owner'];
?>
<div class="page-header">
  <div><h1 class="page-title">Edit Workflow</h1><p class="page-subtitle">Update the workflow and manage its approval stages</p></div>
  <div class="page-actions"><a href="/workflows/<?= (int)$wf['id'] ?>" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.3fr;gap:20px;align-items:start">
  <!-- Workflow details -->
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-sliders"></i> Workflow</span></div></div>
    <div class="card-body">
      <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/edit">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" class="form-control" required value="<?= Security::h($wf['name']) ?>"></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"><?= Security::h($wf['description'] ?? '') ?></textarea></div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Type</label>
            <select name="workflow_type" class="form-select"><?php foreach (['policy','procedure','process','change','record','evidence','corrective','general'] as $t): ?><option value="<?= $t ?>" <?= $wf['workflow_type']===$t?'selected':'' ?>><?= ucfirst($t) ?></option><?php endforeach; ?></select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Approval Mode</label>
            <select name="approval_mode" class="form-select"><?php foreach (['single'=>'Single','sequential'=>'Sequential','parallel'=>'Parallel','consensus'=>'Consensus'] as $k=>$v): ?><option value="<?= $k ?>" <?= $wf['approval_mode']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
          </div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save Workflow</button></div>
      </form>
    </div>
  </div>

  <!-- Stages -->
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-list-ol"></i> Stages (<?= count($steps) ?>)</span></div></div>
    <div class="card-body">
      <?php if ($steps): ?>
      <ul class="tl" style="margin-bottom:8px">
        <?php foreach ($steps as $s): ?>
        <li>
          <span class="tl-dot"><?= (int)$s['step_number'] ?></span>
          <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/steps/<?= (int)$s['id'] ?>/update" class="form-row" style="align-items:flex-end;gap:8px;margin:0">
            <?= Security::csrfField() ?>
            <div class="form-group" style="margin:0;flex:2"><label class="form-label">Stage</label><input type="text" name="name" class="form-control" value="<?= Security::h($s['name']) ?>" required></div>
            <div class="form-group" style="margin:0;flex:1"><label class="form-label">Role</label>
              <select name="approver_role" class="form-select"><option value="">—</option><?php foreach ($roleOpts as $r): ?><option value="<?= $r ?>" <?= ($s['approver_role']??'')===$r?'selected':'' ?>><?= Security::h(Auth::roleLabel($r)) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group" style="margin:0;flex:1"><label class="form-label">User</label>
              <select name="approver_user_id" class="form-select"><option value="">—</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= ((int)($s['approver_user_id']??0))===(int)$u['id']?'selected':'' ?>><?= Security::h($u['name']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-group" style="margin:0;flex:0 0 80px"><label class="form-label">SLA h</label><input type="number" name="sla_hours" class="form-control" value="<?= (int)$s['sla_hours'] ?>" min="1"></div>
            <button class="btn btn-sm btn-ghost" type="submit" title="Save stage"><i class="bi bi-check-lg"></i></button>
          </form>
          <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/steps/<?= (int)$s['id'] ?>/delete" style="margin:6px 0 0" data-confirm="Remove this stage?"><?= Security::csrfField() ?><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i> Remove stage</button></form>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><div class="empty-state-sm">No stages yet — add the first one below.</div><?php endif; ?>

      <hr style="border:none;border-top:1px solid var(--border-light);margin:14px 0">
      <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/steps" class="form-row" style="align-items:flex-end;gap:8px;margin:0">
        <?= Security::csrfField() ?>
        <div class="form-group" style="margin:0;flex:2"><label class="form-label">New stage</label><input type="text" name="name" class="form-control" placeholder="e.g. Management Approval" required></div>
        <div class="form-group" style="margin:0;flex:1"><label class="form-label">Role</label>
          <select name="approver_role" class="form-select"><option value="">—</option><?php foreach ($roleOpts as $r): ?><option value="<?= $r ?>"><?= Security::h(Auth::roleLabel($r)) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group" style="margin:0;flex:1"><label class="form-label">User</label>
          <select name="approver_user_id" class="form-select"><option value="">—</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group" style="margin:0;flex:0 0 80px"><label class="form-label">SLA h</label><input type="number" name="sla_hours" class="form-control" value="72" min="1"></div>
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
