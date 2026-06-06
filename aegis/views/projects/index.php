<?php
$breadcrumbs  = $breadcrumbs  ?? [['Projects', null]];
$statusBadge = [
    'planning'  => 'badge-info',
    'active'    => 'badge-success',
    'on_hold'   => 'badge-warning',
    'completed' => 'badge-secondary',
    'cancelled' => 'badge-danger',
];
$priorityBadge = [
    'critical' => 'badge-danger',
    'high'     => 'badge-warning',
    'medium'   => 'badge-info',
    'low'      => 'badge-secondary',
];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">GRC Projects</h1>
    <p class="page-subtitle">Manage remediation projects, budgets, and team assignments</p>
  </div>
  <div class="page-actions">
    <a href="/projects/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Project</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(22, 163, 74, .08);color:var(--primary)"><i class="bi bi-briefcase-fill"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['total'] ?? 0) ?></div>
      <div class="stat-label">Total Projects</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--success-subtle);color:var(--success)"><i class="bi bi-play-circle-fill"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['active'] ?? 0) ?></div>
      <div class="stat-label">Active</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:rgba(107,114,128,.08);color:var(--text-muted)"><i class="bi bi-check-circle-fill"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['completed'] ?? 0) ?></div>
      <div class="stat-label">Completed</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:var(--info-subtle);color:var(--info)"><i class="bi bi-cash-stack"></i></div>
    <div>
      <div class="stat-value">$<?= number_format((float)($stats['total_budget'] ?? 0), 0) ?></div>
      <div class="stat-label">Total Budget Planned</div>
    </div>
  </div>
</div>

<!-- Projects table -->
<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Code</th>
          <th>Title</th>
          <th>Lead</th>
          <th>Priority</th>
          <th>Status</th>
          <th>Progress</th>
          <th>Budget</th>
          <th>End Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($projects): foreach ($projects as $p):
          $taskCount = (int)$p['task_count'];
          $doneCount = (int)$p['done_count'];
          $progress  = $taskCount > 0 ? round($doneCount / $taskCount * 100) : 0;
          $planned   = $p['budget_planned'] !== null ? (float)$p['budget_planned'] : null;
          $actual    = $p['budget_actual']  !== null ? (float)$p['budget_actual']  : null;
          $overBudget = $planned !== null && $actual !== null && $actual > $planned;
        ?>
          <tr>
            <td>
              <a href="/projects/<?= $p['id'] ?>" class="table-link" style="font-family:monospace;font-size:.85rem">
                <?= Security::h($p['project_code']) ?>
              </a>
            </td>
            <td>
              <a href="/projects/<?= $p['id'] ?>" class="table-link" style="font-weight:500">
                <?= Security::h($p['title']) ?>
              </a>
            </td>
            <td><?= Security::h($p['lead_name'] ?? '—') ?></td>
            <td><span class="badge <?= $priorityBadge[$p['priority']] ?? 'badge-secondary' ?>"><?= ucfirst(Security::h($p['priority'])) ?></span></td>
            <td><span class="badge <?= $statusBadge[$p['status']] ?? 'badge-secondary' ?>"><?= ucfirst(str_replace('_', ' ', Security::h($p['status']))) ?></span></td>
            <td style="min-width:120px">
              <?php if ($taskCount > 0): ?>
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="flex:1;background:var(--border);border-radius:4px;height:6px;overflow:hidden">
                    <div style="width:<?= $progress ?>%;background:var(--primary);height:100%"></div>
                  </div>
                  <span style="font-size:.8rem;color:var(--text-muted);white-space:nowrap"><?= $doneCount ?>/<?= $taskCount ?></span>
                </div>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:.85rem">No tasks</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($planned !== null): ?>
                <span>$<?= number_format($planned, 0) ?></span>
                <?php if ($actual !== null): ?>
                  <span style="color:<?= $overBudget ? 'var(--danger)' : 'var(--success)' ?>;font-size:.8rem;display:block">
                    actual: $<?= number_format($actual, 0) ?>
                    <?php if ($overBudget): ?><i class="bi bi-exclamation-triangle-fill"></i><?php endif; ?>
                  </span>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($p['end_date']): ?>
                <?php
                  $isOverdue = strtotime($p['end_date']) < time() && !in_array($p['status'], ['completed', 'cancelled']);
                ?>
                <span style="<?= $isOverdue ? 'color:var(--danger);font-weight:600' : '' ?>">
                  <?= date('M j, Y', strtotime($p['end_date'])) ?>
                </span>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="/projects/<?= $p['id'] ?>" class="btn btn-sm btn-secondary">View</a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td class="empty-row" colspan="9">
              <div class="empty-state-sm">
                <i class="bi bi-briefcase-fill" style="font-size:2.5rem"></i>
                <p style="margin:0;font-size:1rem;font-weight:500">No projects yet</p>
                <p style="margin:0;font-size:.875rem"><a href="/projects/create">Create the first project</a></p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
