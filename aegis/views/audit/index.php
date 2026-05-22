<?php
$pageTitle    = 'Audits';
$activeModule = 'audit';
$breadcrumbs  = [['Audits', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Audit Management</h1>
    <p class="page-subtitle">Schedule, track, and complete internal audits</p>
  </div>
  <a href="/audit/create" class="btn btn-primary"><i class="bi bi-clipboard2-plus"></i> New Audit</a>
</div>

<!-- Summary -->
<div class="stats-row">
  <div class="stat-mini"><i class="bi bi-calendar-event" style="color:#4f46e5"></i><span class="stat-mini-num"><?= $summary['planned'] ?? 0 ?></span><span>Planned</span></div>
  <div class="stat-mini"><i class="bi bi-play-circle" style="color:#0284c7"></i><span class="stat-mini-num"><?= $summary['in_progress'] ?? 0 ?></span><span>In Progress</span></div>
  <div class="stat-mini"><i class="bi bi-check-circle" style="color:#059669"></i><span class="stat-mini-num"><?= $summary['completed'] ?? 0 ?></span><span>Completed</span></div>
  <div class="stat-mini"><i class="bi bi-exclamation-circle" style="color:#dc2626"></i><span class="stat-mini-num"><?= $summary['overdue'] ?? 0 ?></span><span>Overdue</span></div>
</div>

<!-- Filter -->
<div class="filter-bar card">
  <a href="/audit" class="btn btn-sm <?= !($status??'') ? 'btn-primary' : 'btn-ghost' ?>">All</a>
  <a href="/audit?status=planned" class="btn btn-sm <?= ($status??'')==='planned' ? 'btn-primary' : 'btn-ghost' ?>">Planned</a>
  <a href="/audit?status=in_progress" class="btn btn-sm <?= ($status??'')==='in_progress' ? 'btn-primary' : 'btn-ghost' ?>">In Progress</a>
  <a href="/audit?status=completed" class="btn btn-sm <?= ($status??'')==='completed' ? 'btn-primary' : 'btn-ghost' ?>">Completed</a>
  <a href="/audit?status=overdue" class="btn btn-sm <?= ($status??'')==='overdue' ? 'btn-danger' : 'btn-ghost' ?>">Overdue</a>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr><th>Audit</th><th>Package</th><th>Type</th><th>Auditor</th><th>Scheduled</th><th>Status</th><th>Score</th><th></th></tr>
      </thead>
      <tbody>
        <?php if ($audits): foreach ($audits as $audit): ?>
          <?php $overdue = $audit['status'] === 'planned' && $audit['scheduled_date'] && strtotime($audit['scheduled_date']) < time(); ?>
          <tr <?= $overdue ? 'class="row-danger"' : '' ?>>
            <td>
              <a href="/audit/<?= $audit['id'] ?>" class="table-link fw-600"><?= Security::h($audit['name']) ?></a>
              <?php if ($audit['description']): ?>
                <div class="text-muted text-sm"><?= Security::h(substr($audit['description'],0,60)) ?>...</div>
              <?php endif; ?>
            </td>
            <td><?= Security::h($audit['package_name'] ?? '—') ?></td>
            <td><span class="tag"><?= ucfirst(str_replace('_',' ',$audit['audit_type'])) ?></span></td>
            <td><?= Security::h($audit['auditor_name'] ?? 'Unassigned') ?></td>
            <td>
              <?= $audit['scheduled_date'] ? date('M j, Y', strtotime($audit['scheduled_date'])) : '—' ?>
              <?php if ($overdue): ?><span class="badge badge-danger" style="margin-left:4px">Overdue</span><?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $audit['status'] ?>"><?= ucfirst(str_replace('_',' ',$audit['status'])) ?></span></td>
            <td><?= $audit['score'] !== null ? '<strong>'.round($audit['score']).'%</strong>' : '—' ?></td>
            <td><a href="/audit/<?= $audit['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-right"></i></a></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="8" class="empty-row">
            <div class="empty-state-sm"><i class="bi bi-clipboard-x"></i><p>No audits yet. <a href="/audit/create">Create your first audit</a>.</p></div>
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
