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
  <div class="page-actions"><a href="/audit/create" class="btn btn-primary"><i class="bi bi-clipboard2-plus"></i> New Audit</a></div>
</div>

<!-- Summary -->
<div class="stats-row">
  <div class="stat-mini"><i class="bi bi-calendar-event" style="color:var(--primary)"></i><span class="stat-mini-num"><?= $summary['planned'] ?? 0 ?></span><span>Planned</span></div>
  <div class="stat-mini"><i class="bi bi-play-circle" style="color:var(--info)"></i><span class="stat-mini-num"><?= $summary['in_progress'] ?? 0 ?></span><span>In Progress</span></div>
  <div class="stat-mini"><i class="bi bi-check-circle" style="color:var(--success)"></i><span class="stat-mini-num"><?= $summary['completed'] ?? 0 ?></span><span>Completed</span></div>
  <div class="stat-mini"><i class="bi bi-exclamation-circle" style="color:var(--danger)"></i><span class="stat-mini-num"><?= $summary['overdue'] ?? 0 ?></span><span>Overdue</span></div>
</div>

<?php
$_filterCount = count(array_filter([
    $_GET['status'] ?? '',
]));
?>
<div class="filter-toolbar">
  <div class="filter-popover-wrap">
    <button type="button" class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#auditFilterPopover">
      <i class="bi bi-funnel-fill"></i> Filters
      <?php if ($_filterCount > 0): ?>
        <span class="filter-active-count"><?= $_filterCount ?></span>
      <?php endif; ?>
    </button>
    <div id="auditFilterPopover" class="filter-popover <?= $_filterCount ? 'open' : '' ?>">
      <form method="GET" action="/audit">
        <div class="filter-popover-grid single-col">
          <div class="filter-field">
            <label>Status</label>
            <select name="status">
              <option value="">All statuses</option>
              <?php foreach (['planned'=>'Planned','in_progress'=>'In Progress','completed'=>'Completed','overdue'=>'Overdue','cancelled'=>'Cancelled'] as $sv=>$sl): ?>
                <option value="<?= $sv ?>" <?= ($_GET['status']??'')===$sv?'selected':'' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="filter-popover-actions">
          <button type="submit" class="btn btn-primary btn-sm">Apply</button>
          <a href="/audit" class="btn btn-ghost btn-sm">Clear</a>
        </div>
      </form>
    </div>
  </div>
  <?php if ($_filterCount): ?>
  <div class="filter-chips">
    <?php if (!empty($_GET['status'])): ?>
      <?php $__stl = ['planned'=>'Planned','in_progress'=>'In Progress','completed'=>'Completed','overdue'=>'Overdue','cancelled'=>'Cancelled']; ?>
      <span class="filter-chip">Status: <?= Security::h($__stl[$_GET['status']] ?? $_GET['status']) ?> <a href="<?= Security::h(preg_replace('/[?&]status=[^&]*/','', $_SERVER['REQUEST_URI'])) ?>" class="filter-chip-remove">×</a></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr><th scope="col">Audit</th><th scope="col">Package</th><th scope="col">Type</th><th scope="col">Auditor</th><th scope="col">Scheduled</th><th scope="col">Status</th><th scope="col">Score</th><th scope="col"></th></tr>
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
