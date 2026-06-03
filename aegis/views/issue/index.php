<?php
$pageTitle    = 'Issues';
$activeModule = 'issue';
$breadcrumbs  = [['Issues', null]];
ob_start();

$severityColors = [
    'critical' => '#ef4444',
    'high'     => '#f97316',
    'medium'   => '#f59e0b',
    'low'      => '#22c55e',
];
$statusColors = [
    'open'           => '#3b82f6',
    'in_progress'    => '#8b5cf6',
    'pending_review' => '#f59e0b',
    'resolved'       => '#22c55e',
    'closed'         => '#6b7280',
    'wont_fix'       => '#9ca3af',
];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Issue &amp; Remediation Tracker</h1>
    <p class="page-subtitle">Track, assign, and resolve compliance and security issues</p>
  </div>
  <div class="page-actions">
    <a href="/issue/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Issue</a>
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
    <div class="stat-icon" style="background:#3b82f620;color:#3b82f6"><i class="bi bi-list-ul"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['total'] ?? 0) ?></div>
      <div class="stat-label">Total Issues</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#3b82f620;color:#3b82f6"><i class="bi bi-circle-fill"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['open'] ?? 0) ?></div>
      <div class="stat-label">Open</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#8b5cf620;color:#8b5cf6"><i class="bi bi-arrow-repeat"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['in_progress'] ?? 0) ?></div>
      <div class="stat-label">In Progress</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#ef444420;color:#ef4444"><i class="bi bi-exclamation-octagon-fill"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['critical'] ?? 0) ?></div>
      <div class="stat-label">Critical</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="background:#f9731620;color:#f97316"><i class="bi bi-clock-fill"></i></div>
    <div>
      <div class="stat-value"><?= (int)($stats['overdue'] ?? 0) ?></div>
      <div class="stat-label">Overdue</div>
    </div>
  </div>
</div>

<!-- Filter bar -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-body" style="padding:.75rem 1rem">
    <form method="GET" class="filter-form" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
      <select name="severity" class="form-control form-control-sm" data-autosubmit>
        <option value="">All severities</option>
        <?php foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($_GET['severity'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="form-control form-control-sm" data-autosubmit>
        <option value="">All statuses</option>
        <?php foreach (['open' => 'Open', 'in_progress' => 'In Progress', 'pending_review' => 'Pending Review', 'resolved' => 'Resolved', 'closed' => 'Closed', 'wont_fix' => "Won't Fix"] as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($_GET['status'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <select name="assigned_to" class="form-control form-control-sm" data-autosubmit>
        <option value="">All assignees</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= (int)($_GET['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="/issue" class="btn btn-secondary btn-sm">Clear</a>
    </form>
  </div>
</div>

<!-- Issues table -->
<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Issue #</th>
          <th>Title</th>
          <th>Severity</th>
          <th>Status</th>
          <th>Assigned To</th>
          <th>Due Date</th>
          <th>Source</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($issues): foreach ($issues as $issue):
          $isOverdue = $issue['due_date']
            && strtotime($issue['due_date']) < time()
            && !in_array($issue['status'], ['resolved', 'closed', 'wont_fix']);
          $sevColor = $severityColors[$issue['severity']] ?? '#6b7280';
          $stColor  = $statusColors[$issue['status']]    ?? '#6b7280';
        ?>
          <tr>
            <td>
              <a href="/issue/<?= $issue['id'] ?>" class="table-link" style="font-family:monospace;font-size:.85rem">
                <?= Security::h($issue['issue_number']) ?>
              </a>
            </td>
            <td>
              <a href="/issue/<?= $issue['id'] ?>" class="table-link" style="font-weight:500">
                <?= Security::h($issue['title']) ?>
              </a>
            </td>
            <td>
              <span class="status-chip" style="background:<?= $sevColor ?>20;color:<?= $sevColor ?>;border:1px solid <?= $sevColor ?>40">
                <?= ucfirst(Security::h($issue['severity'])) ?>
              </span>
            </td>
            <td>
              <span class="status-chip" style="background:<?= $stColor ?>20;color:<?= $stColor ?>;border:1px solid <?= $stColor ?>40">
                <?= Security::h(str_replace('_', ' ', ucfirst($issue['status']))) ?>
              </span>
            </td>
            <td><?= Security::h($issue['assigned_to_name'] ?? '—') ?></td>
            <td>
              <?php if ($issue['due_date']): ?>
                <span style="<?= $isOverdue ? 'color:#ef4444;font-weight:600' : '' ?>">
                  <?= Security::h(date('M j, Y', strtotime($issue['due_date']))) ?>
                  <?php if ($isOverdue): ?><i class="bi bi-exclamation-circle" title="Overdue"></i><?php endif; ?>
                </span>
              <?php else: ?>
                <span style="color:#9ca3af">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($issue['source_type']): ?>
                <span class="status-chip" style="background:#6b728020;color:#6b7280;border:1px solid #6b728040;font-size:.75rem">
                  <?= Security::h(ucfirst($issue['source_type'])) ?>
                </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="color:#6b7280;font-size:.85rem"><?= Security::h(date('M j, Y', strtotime($issue['created_at']))) ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:3rem">
              <div style="display:flex;flex-direction:column;align-items:center;gap:.75rem;color:#9ca3af">
                <i class="bi bi-check2-circle" style="font-size:2.5rem"></i>
                <p style="margin:0;font-size:1rem;font-weight:500">No issues found</p>
                <p style="margin:0;font-size:.875rem">
                  <?php if (!empty($_GET['severity']) || !empty($_GET['status']) || !empty($_GET['assigned_to'])): ?>
                    Try adjusting your filters. <a href="/issue">Clear all</a>
                  <?php else: ?>
                    <a href="/issue/create">Create the first issue</a>
                  <?php endif; ?>
                </p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
