<?php
$pageTitle    = 'Tasks';
$activeModule = 'tasks';
$breadcrumbs  = [['Tasks', null]];
ob_start();
$view      = $_GET['view'] ?? 'my';
$hasFilter = !empty($_GET['status']) || !empty($_GET['priority']) || !empty($_GET['assigned_to']);
$tabs = [
    'my'        => ['My Tasks',  'bi-person-check'],
    'team'      => ['Team',      'bi-people'],
    'overdue'   => ['Overdue',   'bi-clock-history'],
    'completed' => ['Completed', 'bi-check2-circle'],
    'all'       => ['All',       'bi-list-ul'],
];
$typeLabels = ['task'=>'Task','review'=>'Review','approval'=>'Approval','corrective_action'=>'Corrective Action'];
$qs = function(array $extra) use ($view) {
    return '/tasks?' . http_build_query(array_merge(['view' => $view], $extra));
};
?>
<div class="page-header">
  <div><h1 class="page-title">Tasks &amp; Action Items</h1><p class="page-subtitle">Reviews, approvals, corrective actions &amp; assignments</p></div>
  <div class="page-actions"><?php if (Auth::can('task.create')): ?><a href="/tasks/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Task</a><?php endif; ?></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-list-task"></i></div><div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Total</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-value"><?= (int)$stats['open'] ?></div><div class="stat-label">Open</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-clock-history"></i></div><div><div class="stat-value"><?= (int)$stats['overdue'] ?></div><div class="stat-label">Overdue</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= (int)$stats['completed'] ?></div><div class="stat-label">Completed</div></div></div>
</div>

<div class="tab-bar" style="margin:18px 0 0">
  <?php foreach ($tabs as $key => [$label, $icon]): ?>
    <a href="/tasks?view=<?= $key ?>" class="tab-btn <?= $view === $key ? 'active' : '' ?>" style="text-decoration:none"><i class="bi <?= $icon ?>"></i> <?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="card" style="margin:18px 0">
  <div class="card-body">
    <form method="GET" action="/tasks" class="form-row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
      <input type="hidden" name="view" value="<?= Security::h($view) ?>">
      <div class="form-group" style="margin:0"><label class="form-label">Status</label>
        <select name="status" class="form-select"><option value="">All</option>
          <?php foreach (['open','in_progress','blocked','done','cancelled'] as $st): ?><option value="<?= $st ?>" <?= ($_GET['status'] ?? '')===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0"><label class="form-label">Priority</label>
        <select name="priority" class="form-select"><option value="">All</option>
          <?php foreach (['urgent','high','medium','low'] as $p): ?><option value="<?= $p ?>" <?= ($_GET['priority'] ?? '')===$p?'selected':'' ?>><?= ucfirst($p) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0"><label class="form-label">Assignee</label>
        <select name="assigned_to" class="form-select"><option value="">All</option>
          <?php foreach ($users as $usr): ?><option value="<?= (int)$usr['id'] ?>" <?= (($_GET['assigned_to'] ?? '')==$usr['id'])?'selected':'' ?>><?= Security::h($usr['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
      <?php if ($hasFilter): ?><a href="/tasks?view=<?= Security::h($view) ?>" class="btn btn-ghost">Reset</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>Title</th><th>Type</th><th>Priority</th><th>Assignee</th><th>Due</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $t): ?>
      <?php $overdue = $t['due_date'] && strtotime($t['due_date']) < strtotime('today') && !in_array($t['status'], ['done','cancelled'], true); ?>
      <tr>
        <td><a href="/tasks/<?= (int)$t['id'] ?>" class="table-link"><?= Security::h($t['title']) ?></a></td>
        <td><span class="chip"><?= Security::h($typeLabels[$t['type']] ?? ucfirst($t['type'])) ?></span></td>
        <td><?= View::priorityBadge($t['priority']) ?></td>
        <td class="form-hint"><?= Security::h($t['assignee_name'] ?: '—') ?></td>
        <td class="form-hint"><?php if ($t['due_date']): ?><span class="<?= $overdue ? 'badge badge-overdue' : '' ?>"><?= View::fmtDate($t['due_date']) ?></span><?php else: ?>—<?php endif; ?></td>
        <td><?= View::statusBadge($t['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tasks): ?>
      <tr><td colspan="6"><div class="empty-state"><i class="bi bi-check2-all"></i><p>No tasks found.</p><?php if ($hasFilter): ?><a href="/tasks?view=<?= Security::h($view) ?>">Clear filters</a><?php elseif (Auth::can('task.create')): ?><a href="/tasks/create" class="btn btn-sm btn-primary">Create the first task</a><?php endif; ?></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
