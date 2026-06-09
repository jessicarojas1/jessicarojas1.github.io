<?php
$pageTitle    = $wf['name'];
$activeModule = 'workflows';
$breadcrumbs  = [['Workflows', '/workflows'], [$wf['name'], null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title"><?= Security::h($wf['name']) ?> <?= $wf['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Inactive</span>' ?></h1>
    <p class="page-subtitle"><?= Security::h(ucfirst($wf['workflow_type'])) ?> · <?= Security::h(ucfirst($wf['approval_mode'])) ?> approval · used <?= $usage ?> time<?= $usage===1?'':'s' ?></p></div>
  <div class="page-actions">
    <?php if (Auth::can('approval.view')): ?><a href="/approvals/start?template=<?= (int)$wf['id'] ?>" class="btn btn-primary"><i class="bi bi-send"></i> Start Request</a><?php endif; ?>
    <?php if (Auth::can('workflow.manage')): ?><a href="/workflows/<?= (int)$wf['id'] ?>/edit" class="btn btn-ghost"><i class="bi bi-pencil"></i> Edit &amp; Stages</a><?php endif; ?>
    <?php if (Auth::can('workflow.manage') && $wf['is_active']): ?><form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/delete" style="margin:0" data-confirm="Deactivate this workflow template?"><?= Security::csrfField() ?><button class="btn btn-ghost btn-danger" type="submit"><i class="bi bi-x-circle"></i> Deactivate</button></form><?php endif; ?>
    <?php if (Auth::can('workflow.manage') && !$wf['is_active']): ?><form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/reactivate" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-ghost btn-success" type="submit"><i class="bi bi-arrow-clockwise"></i> Reactivate</button></form><?php endif; ?>
  </div>
</div>

<?php if ($wf['description']): ?><div class="card" style="margin-bottom:18px"><div class="card-body"><p style="margin:0;color:var(--text-muted)"><?= Security::h($wf['description']) ?></p></div></div><?php endif; ?>

<div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-list-ol"></i> Approval Steps</span></div></div><div class="card-body">
  <ul class="tl">
    <?php foreach ($steps as $s): ?>
    <li><span class="tl-dot"><?= (int)$s['step_number'] ?></span>
      <div class="tl-title"><?= Security::h($s['name']) ?></div>
      <div class="tl-meta">Approver: <?= $s['approver_name'] ? Security::h($s['approver_name']) : ($s['approver_role'] ? Security::h(Auth::roleLabel($s['approver_role'])) . ' (role)' : 'Any approver') ?> · SLA <?= (int)$s['sla_hours'] ?>h</div>
    </li>
    <?php endforeach; ?>
    <?php if (!$steps): ?><li><div class="empty-state-sm">No steps defined.</div></li><?php endif; ?>
  </ul>
</div></div>

<?php if (!empty($states)): ?>
<?php $wfDiagramEditable = false; require PALADIN_ROOT . '/views/partials/workflow_diagram.php'; ?>
<?php endif; ?>

<?php if (!empty($assignedSpaces)): ?>
<div class="card" style="margin-top:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-collection"></i> Applied to Spaces</span></div></div>
  <div class="card-body"><div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach ($assignedSpaces as $sp): ?><a href="/spaces/<?= (int)$sp['id'] ?>" class="chip" style="text-decoration:none"><?= Security::h($sp['space_key']) ?></a><?php endforeach; ?>
  </div></div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
