<?php
$pageTitle    = $task['title'];
$activeModule = 'tasks';
$breadcrumbs  = [['Tasks', '/tasks'], [$task['title'], null]];
ob_start();
$typeLabels = ['task'=>'Task','review'=>'Review','approval'=>'Approval','corrective_action'=>'Corrective Action'];
$overdue    = $task['due_date'] && strtotime($task['due_date']) < strtotime('today') && !in_array($task['status'], ['done','cancelled'], true);
$entityLink = null;
if (!empty($task['entity_type']) && !empty($task['entity_id'])) {
    $map = ['document' => '/documents/', 'page' => '/pages/', 'process' => '/processes/'];
    if (isset($map[$task['entity_type']])) $entityLink = $map[$task['entity_type']] . (int)$task['entity_id'];
}
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($task['title']) ?> <?= View::statusBadge($task['status']) ?></h1>
    <p class="page-subtitle"><span class="chip"><?= Security::h($typeLabels[$task['type']] ?? ucfirst($task['type'])) ?></span> <?= View::priorityBadge($task['priority']) ?></p>
  </div>
  <div class="page-actions">
    <?php if ($task['status'] !== 'done' && Auth::can('task.complete')): ?>
    <form method="POST" action="/tasks/<?= (int)$task['id'] ?>/complete" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-success" type="submit"><i class="bi bi-check2-circle"></i> Mark Complete</button></form>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
  <div>
    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-card-text"></i> Description</span></div></div>
      <div class="card-body">
        <?php if (trim((string)$task['description']) !== ''): ?>
          <p style="margin:0;white-space:pre-wrap"><?= Security::h($task['description']) ?></p>
        <?php else: ?>
          <div class="empty-state-sm">No description provided.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (Auth::can('task.edit')): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-pencil-square"></i> Edit Task</span></div></div>
      <div class="card-body">
        <form method="POST" action="/tasks/<?= (int)$task['id'] ?>/edit">
          <?= Security::csrfField() ?>
          <div class="form-group"><label class="form-label">Title *</label>
            <input type="text" name="title" class="form-control" required value="<?= Security::h($task['title']) ?>">
          </div>
          <div class="form-group"><label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="4"><?= Security::h($task['description'] ?? '') ?></textarea>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Type</label>
              <select name="type" class="form-select">
                <?php foreach ($typeLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $task['type']===$val?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['open','in_progress','blocked','done','cancelled'] as $st): ?><option value="<?= $st ?>" <?= $task['status']===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Priority</label>
              <select name="priority" class="form-select">
                <?php foreach (['urgent','high','medium','low'] as $p): ?><option value="<?= $p ?>" <?= $task['priority']===$p?'selected':'' ?>><?= ucfirst($p) ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Assignee</label>
              <select name="assigned_to" class="form-select"><option value="">Unassigned</option>
                <?php foreach ($users as $usr): ?><option value="<?= (int)$usr['id'] ?>" <?= ((int)($task['assigned_to'] ?? 0)===(int)$usr['id'])?'selected':'' ?>><?= Security::h($usr['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Due Date</label>
              <input type="date" name="due_date" class="form-control" value="<?= Security::h($task['due_date'] ?? '') ?>">
            </div>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save Changes</button>
            <a href="/tasks" class="btn btn-ghost">Cancel</a>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> Details</span></div></div>
      <div class="card-body">
        <div class="meta-grid" style="grid-template-columns:1fr 1fr">
          <div class="meta-item"><div class="meta-label">Type</div><div class="meta-value"><?= Security::h($typeLabels[$task['type']] ?? ucfirst($task['type'])) ?></div></div>
          <div class="meta-item"><div class="meta-label">Priority</div><div class="meta-value"><?= View::priorityBadge($task['priority']) ?></div></div>
          <div class="meta-item"><div class="meta-label">Status</div><div class="meta-value"><?= View::statusBadge($task['status']) ?></div></div>
          <div class="meta-item"><div class="meta-label">Assignee</div><div class="meta-value"><?= Security::h($task['assignee_name'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Created By</div><div class="meta-value"><?= Security::h($task['creator_name'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Due</div><div class="meta-value"><?php if ($task['due_date']): ?><span class="<?= $overdue ? 'badge badge-overdue' : '' ?>"><?= View::fmtDate($task['due_date']) ?></span><?php else: ?>—<?php endif; ?></div></div>
          <div class="meta-item"><div class="meta-label">Completed</div><div class="meta-value"><?= View::fmtDate($task['completed_at']) ?></div></div>
          <div class="meta-item"><div class="meta-label">Created</div><div class="meta-value"><?= View::fmtDate($task['created_at']) ?></div></div>
          <?php if ($entityLink): ?>
          <div class="meta-item"><div class="meta-label">Linked <?= Security::h(ucfirst($task['entity_type'])) ?></div><div class="meta-value"><a href="<?= Security::h($entityLink) ?>"><?= Security::h(ucfirst($task['entity_type'])) ?> #<?= (int)$task['entity_id'] ?></a></div></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
