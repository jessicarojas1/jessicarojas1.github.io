<?php
$editing      = !empty($task);
$pageTitle    = $editing ? 'Edit Task' : 'New Task';
$activeModule = 'tasks';
$breadcrumbs  = [['Tasks', '/tasks'], [$editing ? 'Edit' : 'New', null]];
$action       = $editing ? '/tasks/' . (int)$task['id'] . '/edit' : '/tasks/create';
ob_start();
$typeLabels = ['task'=>'Task','review'=>'Review','approval'=>'Approval','corrective_action'=>'Corrective Action'];
?>
<div class="page-header"><div><h1 class="page-title"><?= $editing ? 'Edit Task' : 'Create Task' ?></h1></div></div>

<div class="card form-page">
  <div class="card-body">
    <form method="POST" action="<?= $action ?>">
      <?= Security::csrfField() ?>
      <div class="form-group"><label class="form-label">Title *</label>
        <input type="text" name="title" class="form-control" required value="<?= Security::h($task['title'] ?? '') ?>" placeholder="Review Q3 access controls">
      </div>
      <div class="form-group"><label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="4"><?= Security::h($task['description'] ?? '') ?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Type</label>
          <select name="type" class="form-select">
            <?php foreach ($typeLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= ($task['type'] ?? 'task')===$val?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Priority</label>
          <select name="priority" class="form-select">
            <?php foreach (['urgent','high','medium','low'] as $p): ?><option value="<?= $p ?>" <?= ($task['priority'] ?? 'medium')===$p?'selected':'' ?>><?= ucfirst($p) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['open','in_progress','blocked','done','cancelled'] as $st): ?><option value="<?= $st ?>" <?= ($task['status'] ?? 'open')===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option><?php endforeach; ?>
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
      <div class="form-row">
        <div class="form-group"><label class="form-label">Linked Entity Type</label>
          <select name="entity_type" class="form-select"><option value="">None</option>
            <?php foreach (['document'=>'Document','page'=>'Page','process'=>'Process'] as $val => $lbl): ?><option value="<?= $val ?>" <?= ($task['entity_type'] ?? '')===$val?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Linked Entity ID</label>
          <input type="number" name="entity_id" class="form-control" min="1" value="<?= Security::h((string)($task['entity_id'] ?? '')) ?>">
          <div class="form-hint">Optional reference to a document, page or process.</div>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> <?= $editing ? 'Save Changes' : 'Create Task' ?></button>
        <a href="<?= $editing ? '/tasks/' . (int)$task['id'] : '/tasks' ?>" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
