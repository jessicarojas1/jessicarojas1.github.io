<?php
$pageTitle    = 'Workflows';
$activeModule = 'workflows';
$breadcrumbs  = [['Workflows', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Workflow Templates</h1><p class="page-subtitle">Reusable approval routes for policies, procedures, changes &amp; records</p></div>
  <div class="page-actions"><?php if (Auth::can('workflow.manage')): ?><a href="/workflows/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Workflow</a><?php endif; ?></div>
</div>

<?php if ($templates): ?>
<div class="lib-grid">
  <?php foreach ($templates as $w): ?>
  <a href="/workflows/<?= (int)$w['id'] ?>" class="lib-card">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div class="lib-card-icon" style="background:var(--primary)"><i class="bi bi-diagram-2-fill"></i></div>
      <?= $w['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Inactive</span>' ?>
    </div>
    <div class="lib-card-title"><?= Security::h($w['name']) ?></div>
    <div class="lib-card-desc"><?= Security::h($w['description'] ?: 'No description.') ?></div>
    <div class="lib-card-foot">
      <span class="chip"><?= Security::h(ucfirst($w['workflow_type'])) ?></span>
      <span class="chip"><?= Security::h(ucfirst($w['approval_mode'])) ?></span>
      <span style="margin-left:auto"><i class="bi bi-list-ol"></i> <?= (int)$w['step_count'] ?> steps</span>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-diagram-2"></i><p>No workflow templates yet.</p><?php if (Auth::can('workflow.manage')): ?><a href="/workflows/create" class="btn btn-sm btn-primary">Create one</a><?php endif; ?></div></div></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
