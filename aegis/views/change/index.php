<?php
$pageTitle    = 'Change Requests';
$activeModule = 'change';
$breadcrumbs  = [['Change Requests', null]];

$currentStatus = Security::sanitizeInput($_GET['status'] ?? '');

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Change Requests</h1>
    <p class="page-subtitle">Manage and track change requests through their lifecycle</p>
  </div>
  <div class="page-actions">
    <a href="/change/create" class="btn btn-primary">
      <i class="bi bi-plus-lg"></i> New Change Request
    </a>
  </div>
</div>

<?php if (!empty($_SESSION['change_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['change_error']) ?></div>
  <?php unset($_SESSION['change_error']); ?>
<?php endif; ?>

<!-- Status Filter Tabs -->
<div class="filter-tabs" style="display:flex;gap:.25rem;flex-wrap:wrap;margin-bottom:1.25rem">
  <?php
    $tabs = [
        ''             => 'All',
        'draft'        => 'Draft',
        'submitted'    => 'Submitted',
        'under_review' => 'Under Review',
        'approved'     => 'Approved',
        'implementing' => 'Implementing',
        'implemented'  => 'Implemented',
        'rejected'     => 'Rejected',
        'closed'       => 'Closed',
    ];
    foreach ($tabs as $val => $label):
      $active = ($currentStatus === $val);
  ?>
    <a href="/change<?= $val ? '?status=' . urlencode($val) : '' ?>"
       class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-ghost' ?>"
       style="<?= $active ? '' : 'opacity:.75' ?>">
      <?= Security::h($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body p0">
    <?php if (empty($changeRequests)): ?>
      <div class="empty-state" style="padding:3rem">
        <i class="bi bi-arrow-repeat" style="font-size:2.5rem;opacity:.3"></i>
        <p>No change requests found.</p>
        <a href="/change/create" class="btn btn-primary btn-sm">Create First Change Request</a>
      </div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th style="width:60px">#</th>
            <th>Title</th>
            <th>Type</th>
            <th>Risk Level</th>
            <th>Status</th>
            <th>Submitter</th>
            <th>Impl. Date</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($changeRequests as $cr): ?>
            <?php
              // Type badge
              $typeColors = [
                  'normal'    => 'info',
                  'emergency' => 'danger',
                  'standard'  => 'success',
              ];
              $typeColor = $typeColors[$cr['change_type']] ?? 'secondary';

              // Risk badge
              $riskColors = [
                  'low'      => 'success',
                  'medium'   => 'warning',
                  'high'     => 'danger',
                  'critical' => 'danger',
              ];
              $riskColor = $riskColors[$cr['risk_level']] ?? 'secondary';

              // Status badge
              $statusColors = [
                  'draft'        => 'secondary',
                  'submitted'    => 'info',
                  'under_review' => 'warning',
                  'approved'     => 'success',
                  'rejected'     => 'danger',
                  'implementing' => 'purple',
                  'implemented'  => 'teal',
                  'closed'       => 'secondary',
              ];
              $statusColor = $statusColors[$cr['status']] ?? 'secondary';
            ?>
            <tr>
              <td class="text-muted text-sm">#<?= (int)$cr['id'] ?></td>
              <td>
                <a href="/change/<?= (int)$cr['id'] ?>" class="text-link fw-500">
                  <?= Security::h($cr['title']) ?>
                </a>
              </td>
              <td>
                <span class="badge badge-<?= $typeColor ?>">
                  <?= Security::h(ucfirst($cr['change_type'])) ?>
                </span>
              </td>
              <td>
                <span class="badge badge-<?= $riskColor ?>">
                  <?= Security::h(ucfirst($cr['risk_level'])) ?>
                </span>
              </td>
              <td>
                <span class="badge badge-<?= $statusColor ?>">
                  <?= Security::h(str_replace('_', ' ', ucfirst($cr['status']))) ?>
                </span>
              </td>
              <td class="text-sm"><?= Security::h($cr['submitter_name'] ?? '—') ?></td>
              <td class="text-sm text-muted">
                <?= $cr['implementation_date']
                    ? Security::h(date('M j, Y', strtotime($cr['implementation_date'])))
                    : '—' ?>
              </td>
              <td class="text-right">
                <a href="/change/<?= (int)$cr['id'] ?>" class="btn btn-ghost btn-sm">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
