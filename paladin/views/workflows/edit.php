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

<!-- ===== Stateful workflow: states + transitions + spaces ===== -->
<div class="card" style="margin-top:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-bounding-box-circles"></i> States</span></div><span class="form-hint">Define the named states content moves through (one is the initial state).</span></div>
  <div class="card-body">
    <?php if ($states): ?>
    <?php foreach ($states as $st): ?>
      <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/states/<?= (int)$st['id'] ?>/update" class="form-row" style="align-items:flex-end;gap:8px;margin:0 0 8px">
        <?= Security::csrfField() ?>
        <span class="wf-dot" style="background:<?= Security::h($st['color']) ?>"></span>
        <div class="form-group" style="margin:0;flex:2"><input type="text" name="name" class="form-control" value="<?= Security::h($st['name']) ?>" required></div>
        <div class="form-group" style="margin:0;flex:0 0 130px"><select name="kind" class="form-select"><?php foreach (['initial','inprogress','review','approved','rejected','final'] as $k): ?><option value="<?= $k ?>" <?= $st['kind']===$k?'selected':'' ?>><?= ucfirst($k) ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="margin:0;flex:0 0 60px"><input type="color" name="color" class="form-control" value="<?= Security::h($st['color']) ?>" style="height:38px;padding:3px"></div>
        <label class="perm-chk" style="margin:0"><input type="checkbox" name="is_initial" value="1" <?= $st['is_initial']?'checked':'' ?>> Initial</label>
        <button class="btn btn-sm btn-ghost" type="submit" title="Save"><i class="bi bi-check-lg"></i></button>
        <button class="btn btn-sm btn-danger" type="submit" formaction="/workflows/<?= (int)$wf['id'] ?>/states/<?= (int)$st['id'] ?>/delete" formnovalidate data-confirm="Remove this state and its transitions?"><i class="bi bi-trash"></i></button>
      </form>
    <?php endforeach; ?>
    <?php else: ?><div class="empty-state-sm">No states yet — add the first below (e.g. Draft, Peer Review, Approved, Released).</div><?php endif; ?>
    <hr style="border:none;border-top:1px solid var(--border-light);margin:12px 0">
    <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/states" class="form-row" style="align-items:flex-end;gap:8px;margin:0">
      <?= Security::csrfField() ?>
      <div class="form-group" style="margin:0;flex:2"><label class="form-label">New state</label><input type="text" name="name" class="form-control" placeholder="e.g. Peer Review" required></div>
      <div class="form-group" style="margin:0;flex:0 0 130px"><label class="form-label">Kind</label><select name="kind" class="form-select"><?php foreach (['initial','inprogress','review','approved','rejected','final'] as $k): ?><option value="<?= $k ?>"><?= ucfirst($k) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0;flex:0 0 60px"><label class="form-label">Color</label><input type="color" name="color" class="form-control" value="#2563eb" style="height:38px;padding:3px"></div>
      <label class="perm-chk" style="margin:0 0 8px"><input type="checkbox" name="is_initial" value="1"> Initial</label>
      <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-arrow-left-right"></i> Transitions</span></div><span class="form-hint">Allowed moves between states and who can perform them.</span></div>
  <div class="card-body">
    <?php $stateName = []; foreach ($states as $st) { $stateName[(int)$st['id']] = $st; } ?>
    <?php if ($transitions): foreach ($transitions as $tr):
      $f = $stateName[(int)$tr['from_state_id']] ?? null; $t = $stateName[(int)$tr['to_state_id']] ?? null; ?>
      <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border-light)">
        <?php if ($f): ?><span class="wf-chip" style="background:<?= Security::h($f['color']) ?>"><?= Security::h($f['name']) ?></span><?php endif; ?>
        <span class="form-hint"><i class="bi bi-arrow-right"></i> <strong><?= Security::h($tr['action_label']) ?></strong> <i class="bi bi-arrow-right"></i></span>
        <?php if ($t): ?><span class="wf-chip" style="background:<?= Security::h($t['color']) ?>"><?= Security::h($t['name']) ?></span><?php endif; ?>
        <span class="form-hint" style="flex:1">by <?= $tr['approver_user_id'] ? 'user' : ($tr['approver_role'] ? Security::h(Auth::roleLabel($tr['approver_role'])) : 'anyone') ?></span>
        <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/transitions/<?= (int)$tr['id'] ?>/delete" style="margin:0" data-confirm="Remove this transition?"><?= Security::csrfField() ?><button class="btn btn-sm btn-danger btn-unstyled" type="submit" style="border:none;background:none;color:var(--danger)"><i class="bi bi-x"></i></button></form>
      </div>
    <?php endforeach; else: ?><div class="empty-state-sm">No transitions yet.</div><?php endif; ?>
    <?php if (count($states) >= 2): ?>
    <hr style="border:none;border-top:1px solid var(--border-light);margin:12px 0">
    <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/transitions" class="form-row" style="align-items:flex-end;gap:8px;margin:0">
      <?= Security::csrfField() ?>
      <div class="form-group" style="margin:0;flex:1"><label class="form-label">From</label><select name="from_state_id" class="form-select"><?php foreach ($states as $st): ?><option value="<?= (int)$st['id'] ?>"><?= Security::h($st['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0;flex:0 0 130px"><label class="form-label">Action</label><input type="text" name="action_label" class="form-control" placeholder="Approve" required></div>
      <div class="form-group" style="margin:0;flex:1"><label class="form-label">To</label><select name="to_state_id" class="form-select"><?php foreach ($states as $st): ?><option value="<?= (int)$st['id'] ?>"><?= Security::h($st['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0;flex:0 0 150px"><label class="form-label">Who (role)</label><select name="approver_role" class="form-select"><option value="">Anyone</option><?php foreach (['reviewer','approver','compliance_admin','space_owner'] as $r): ?><option value="<?= $r ?>"><?= Security::h(Auth::roleLabel($r)) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
    </form>
    <?php else: ?><div class="form-hint" style="margin-top:8px">Add at least two states to define transitions.</div><?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-collection"></i> Applied to Spaces</span></div></div>
  <div class="card-body">
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px">
      <?php foreach ($spaces as $sp): if (!in_array($sp['id'], $assigned)) continue; ?>
        <span class="chip"><?= Security::h($sp['space_key']) ?>
          <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/spaces/<?= (int)$sp['id'] ?>/unassign" style="display:inline;margin:0"><?= Security::csrfField() ?><button class="btn-unstyled" type="submit" style="border:none;background:none;cursor:pointer;color:var(--text-light);padding:0 0 0 4px"><i class="bi bi-x"></i></button></form>
        </span>
      <?php endforeach; ?>
      <?php if (!array_filter($spaces, fn($sp)=>in_array($sp['id'],$assigned))): ?><span class="form-hint">Not applied to any space yet.</span><?php endif; ?>
    </div>
    <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/spaces" class="form-row" style="gap:8px;margin:0;max-width:420px">
      <?= Security::csrfField() ?>
      <select name="space_id" class="form-select" style="flex:1"><?php foreach ($spaces as $sp): if (in_array($sp['id'],$assigned)) continue; ?><option value="<?= (int)$sp['id'] ?>"><?= Security::h($sp['space_key'] . ' — ' . $sp['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-plus-lg"></i> Apply</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
