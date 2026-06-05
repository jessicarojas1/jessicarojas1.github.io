<?php
$breadcrumbs   = $breadcrumbs   ?? [['Projects', '/projects'], ['Project', null]];
$csrf = Security::generateCsrfToken();
$statusBadge = ['planning'=>'badge-info','active'=>'badge-success','on_hold'=>'badge-warning','completed'=>'badge-secondary','cancelled'=>'badge-danger'];
$priorityBadge = ['critical'=>'badge-danger','high'=>'badge-warning','medium'=>'badge-info','low'=>'badge-secondary'];
$taskCount = count($tasks);
$doneCount = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));
$progress = $taskCount > 0 ? round($doneCount / $taskCount * 100) : 0;
$planned = $project['budget_planned'] !== null ? (float)$project['budget_planned'] : null;
$actual  = $project['budget_actual']  !== null ? (float)$project['budget_actual']  : null;
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($project['project_code']) ?></h1>
    <p class="page-subtitle"><?= Security::h($project['title']) ?></p>
  </div>
  <div style="display:flex;gap:10px;">
    <button id="btnOpenEdit" class="btn btn-secondary" data-show-modal="editModal"><i class="bi bi-pencil"></i> Edit</button>
    <form method="POST" action="/projects/<?= (int)$project['id'] ?>/delete" data-confirm="Delete this project?" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i></button>
    </form>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:24px;">
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Tasks -->
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title">Tasks</h3>
        <button id="btnOpenAddTask" class="btn btn-sm btn-secondary" data-show-modal="addTaskModal"><i class="bi bi-plus-lg"></i> Add Task</button>
      </div>
      <?php if ($taskCount > 0): ?>
      <div style="padding:12px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border);">
        <div style="flex:1;background:var(--border);border-radius:4px;height:8px;">
          <div style="width:<?= $progress ?>%;background:var(--primary);height:100%;border-radius:4px;transition:width 0.3s;"></div>
        </div>
        <span style="font-size:0.85rem;color:var(--text-muted);white-space:nowrap;"><?= $doneCount ?>/<?= $taskCount ?> (<?= $progress ?>%)</span>
      </div>
      <?php endif; ?>
      <?php if (empty($tasks)): ?>
      <div class="card-body"><p style="color:var(--text-muted);font-size:0.875rem;">No tasks yet.</p></div>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;">
        <?php foreach ($tasks as $t): ?>
        <div style="display:flex;align-items:flex-start;gap:12px;padding:12px 20px;border-bottom:1px solid var(--border);">
          <div style="padding-top:2px;">
            <?php if ($t['status'] === 'done'): ?>
            <i class="bi bi-check-circle-fill" style="color:var(--success);font-size:1.1rem;"></i>
            <?php else: ?>
            <i class="bi bi-circle" style="color:var(--text-muted);font-size:1.1rem;"></i>
            <?php endif; ?>
          </div>
          <div style="flex:1;">
            <div style="font-weight:500;<?= $t['status']==='done'?'text-decoration:line-through;color:var(--text-muted);':'' ?>"><?= Security::h($t['title']) ?></div>
            <?php if ($t['description']): ?>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;"><?= Security::h($t['description']) ?></div>
            <?php endif; ?>
            <div style="font-size:0.78rem;color:var(--text-muted);margin-top:4px;">
              <?php if ($t['assigned_name']): ?><i class="bi bi-person"></i> <?= Security::h($t['assigned_name']) ?><?php endif; ?>
              <?php if ($t['due_date']): ?>
                <?php $late = strtotime($t['due_date']) < time() && $t['status'] !== 'done'; ?>
                &nbsp;<i class="bi bi-calendar3"></i> <span style="<?= $late?'color:var(--danger);font-weight:600;':'' ?>"><?= date('M j, Y', strtotime($t['due_date'])) ?><?= $late?' (Overdue)':'' ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:6px;align-items:center;">
            <?php if ($t['status'] !== 'done'): ?>
            <form method="POST" action="/projects/<?= (int)$project['id'] ?>/task/<?= (int)$t['id'] ?>/complete" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <button type="submit" class="btn btn-sm btn-secondary" title="Mark done"><i class="bi bi-check-lg"></i></button>
            </form>
            <?php endif; ?>
            <form method="POST" action="/projects/<?= (int)$project['id'] ?>/task/<?= (int)$t['id'] ?>/delete" data-confirm="Delete task?" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Linked Items -->
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 class="card-title">Linked Items</h3>
        <button id="btnOpenAddLink" class="btn btn-sm btn-secondary" data-show-modal="addLinkModal"><i class="bi bi-link-45deg"></i> Add Link</button>
      </div>
      <?php if (empty($links)): ?>
      <div class="card-body"><p style="color:var(--text-muted);font-size:0.875rem;">No linked items.</p></div>
      <?php else: ?>
      <table class="table">
        <thead><tr><th>Type</th><th>ID</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($links as $lk): ?>
        <tr>
          <td><span class="badge badge-secondary"><?= ucfirst(Security::h($lk['entity_type'])) ?></span></td>
          <td><a href="/<?= Security::h($lk['entity_type']) ?>/<?= (int)$lk['entity_id'] ?>">#<?= (int)$lk['entity_id'] ?></a></td>
          <td>
            <form method="POST" action="/projects/<?= (int)$project['id'] ?>/link/<?= (int)$lk['id'] ?>/remove" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <button type="submit" class="btn btn-sm btn-danger" data-confirm="Remove link?"><i class="bi bi-x-lg"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Details</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Status</div><span class="badge <?= $statusBadge[$project['status']] ?? 'badge-secondary' ?>"><?= ucfirst(str_replace('_',' ',$project['status'])) ?></span></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Priority</div><span class="badge <?= $priorityBadge[$project['priority']] ?? 'badge-secondary' ?>"><?= ucfirst($project['priority']) ?></span></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Lead</div><div><?= Security::h($project['lead_name'] ?? '—') ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Start Date</div><div><?= $project['start_date'] ? date('M j, Y', strtotime($project['start_date'])) : '—' ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">End Date</div>
          <?php if ($project['end_date']):
            $isOverdue = strtotime($project['end_date']) < time() && !in_array($project['status'],['completed','cancelled']);
          ?>
          <div style="<?= $isOverdue?'color:var(--danger);font-weight:600;':'' ?>"><?= date('M j, Y', strtotime($project['end_date'])) ?><?= $isOverdue?' (Overdue)':'' ?></div>
          <?php else: ?>—<?php endif; ?>
        </div>
        <?php if ($planned !== null): ?>
        <div>
          <div style="font-size:0.75rem;color:var(--text-muted);">Budget Planned</div>
          <div style="font-weight:700;">$<?= number_format($planned, 0) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($actual !== null): ?>
        <div>
          <div style="font-size:0.75rem;color:var(--text-muted);">Budget Actual</div>
          <div style="font-weight:700;<?= ($planned!==null&&$actual>$planned)?'color:var(--danger);':'' ?>">$<?= number_format($actual, 0) ?><?= ($planned!==null&&$actual>$planned)?' <i class="bi bi-exclamation-triangle-fill"></i>':'' ?></div>
        </div>
        <?php endif; ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Created By</div><div><?= Security::h($project['created_by_name'] ?? '—') ?></div></div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="um-overlay" id="editModal">
  <div class="um-dialog">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">Edit Project</h3>
      <button id="btnCloseEdit" data-close-modal="editModal" style="background:none;border:none;cursor:pointer;font-size:1.25rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/projects/<?= (int)$project['id'] ?>/update">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div style="display:flex;flex-direction:column;gap:14px;">
        <div class="form-group"><label class="form-label">Title</label><input type="text" name="title" class="form-control" value="<?= Security::h($project['title']) ?>" required></div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?= Security::h($project['description'] ?? '') ?></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group"><label class="form-label">Priority</label>
            <select name="priority" class="form-control">
              <?php foreach (['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= $project['priority']===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Status</label>
            <select name="status" class="form-control">
              <?php foreach (['planning'=>'Planning','active'=>'Active','on_hold'=>'On Hold','completed'=>'Completed','cancelled'=>'Cancelled'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= $project['status']===$v?'selected':'' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= Security::h($project['start_date'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?= Security::h($project['end_date'] ?? '') ?>"></div>
          <div class="form-group"><label class="form-label">Project Lead</label>
            <select name="project_lead" class="form-control">
              <option value="">— Unassigned —</option>
              <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= $project['project_lead']==$u['id']?'selected':'' ?>><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div></div>
          <div class="form-group"><label class="form-label">Budget Planned ($)</label><input type="number" name="budget_planned" class="form-control" min="0" step="0.01" value="<?= $planned !== null ? number_format($planned,2,'.','') : '' ?>"></div>
          <div class="form-group"><label class="form-label">Budget Actual ($)</label><input type="number" name="budget_actual" class="form-control" min="0" step="0.01" value="<?= $actual !== null ? number_format($actual,2,'.','') : '' ?>"></div>
        </div>
        <div style="display:flex;gap:10px;margin-top:8px;">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <button type="button" id="btnCancelEdit" data-close-modal="editModal" class="btn btn-secondary">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Add Task Modal -->
<div class="um-overlay" id="addTaskModal">
  <div class="um-dialog">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">Add Task</h3>
      <button id="btnCloseAddTask" data-close-modal="addTaskModal" style="background:none;border:none;cursor:pointer;font-size:1.25rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/projects/<?= (int)$project['id'] ?>/task/add">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-group"><label class="form-label">Task Title <span style="color:var(--danger)">*</span></label><input type="text" name="task_title" class="form-control" required placeholder="e.g. Update access control policy"></div>
      <div class="form-group"><label class="form-label">Description</label><textarea name="task_desc" class="form-control" rows="2"></textarea></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label class="form-label">Assign To</label>
          <select name="task_assigned_to" class="form-control">
            <option value="">— Unassigned —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Due Date</label><input type="date" name="task_due_date" class="form-control"></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">Add Task</button>
        <button type="button" id="btnCancelAddTask" data-close-modal="addTaskModal" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Link Modal -->
<div class="um-overlay" id="addLinkModal">
  <div class="um-dialog">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">Link Item</h3>
      <button id="btnCloseAddLink" data-close-modal="addLinkModal" style="background:none;border:none;cursor:pointer;font-size:1.25rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/projects/<?= (int)$project['id'] ?>/link/add">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-group"><label class="form-label">Entity Type</label>
        <select name="entity_type" class="form-control">
          <option value="risk">Risk</option>
          <option value="control">Control</option>
          <option value="issue">Issue</option>
          <option value="finding">Audit Finding</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Entity ID <span style="color:var(--danger)">*</span></label><input type="number" name="entity_id" class="form-control" required min="1" placeholder="Enter numeric ID"></div>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">Link</button>
        <button type="button" id="btnCancelAddLink" data-close-modal="addLinkModal" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script nonce="<?= Security::nonce() ?>">
(function() {
  document.querySelectorAll('form[data-confirm]').forEach(function(f) {
    f.addEventListener('submit', function(e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
  });
  document.querySelectorAll('button[data-confirm]').forEach(function(btn) {
    btn.addEventListener('click', function(e) { if (!confirm(btn.dataset.confirm)) e.preventDefault(); });
  });
})();
</script>
