<?php
$statusLabels = [
    'operational'        => ['Operational',        'badge-success'],
    'under_development'  => ['Under Development',  'badge-warning'],
    'major_modification' => ['Major Modification',  'badge-info'],
    'other'              => ['Other',               'badge-secondary'],
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">System Security Plans</h1>
    <p class="page-subtitle">Formal security documentation addressing controls on a per-system basis</p>
  </div>
  <a href="/ssp/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New SSP</a>
</div>

<?php if (empty($plans)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-file-earmark-lock2-fill" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No System Security Plans</h3>
  <p style="color:var(--text-muted);margin-bottom:20px;">Create an SSP to document security controls for a specific system.</p>
  <a href="/ssp/create" class="btn btn-primary">Create First SSP</a>
</div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>Title</th>
        <th>System Name</th>
        <th>Status</th>
        <th>Packages</th>
        <th>Created By</th>
        <th>Last Updated</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($plans as $plan):
      [$statusLabel, $statusClass] = $statusLabels[$plan['operational_status']] ?? ['Unknown','badge-secondary'];
    ?>
      <tr>
        <td><a href="/ssp/<?= (int)$plan['id'] ?>" style="font-weight:600;"><?= Security::h($plan['title']) ?></a></td>
        <td><?= Security::h($plan['system_name'] ?: '—') ?></td>
        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
        <td><?= (int)$plan['package_count'] ?></td>
        <td><?= Security::h($plan['created_by_name'] ?? '—') ?></td>
        <td><?= $plan['updated_at'] ? date('M j, Y', strtotime($plan['updated_at'])) : '—' ?></td>
        <td>
          <a href="/ssp/<?= (int)$plan['id'] ?>" class="btn btn-sm btn-secondary">View</a>
          <a href="/ssp/<?= (int)$plan['id'] ?>/generate" class="btn btn-sm btn-primary" target="_blank"><i class="bi bi-file-earmark-text"></i> Generate</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
