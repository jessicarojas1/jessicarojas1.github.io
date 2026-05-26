<?php
$statusColors = ['pending' => '#f59e0b', 'approved' => '#22c55e', 'rejected' => '#ef4444'];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Pending Approvals</h1>
    <p class="page-subtitle">Actions waiting for your review and sign-off.</p>
  </div>
</div>

<?php if (empty($requests)): ?>
  <div class="empty-state">
    <i class="bi bi-check2-all"></i>
    <h3>No pending approvals</h3>
    <p>You have no approval requests waiting for your action.</p>
  </div>
<?php else: ?>
<div class="card">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Template</th>
        <th>Entity</th>
        <th>Step</th>
        <th>Requested By</th>
        <th>Due</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($requests as $r): ?>
        <?php
          $due = strtotime($r['due_at'] ?? '');
          $isOverdue = $due && $due < time();
          $entityLink = '/' . Security::h($r['entity_type']) . '/' . (int)$r['entity_id'];
        ?>
        <tr>
          <td class="text-muted text-sm">#<?= (int)$r['id'] ?></td>
          <td><span class="fw-600"><?= Security::h($r['template_name']) ?></span></td>
          <td>
            <a href="<?= $entityLink ?>" class="text-link">
              <?= Security::h(ucfirst($r['entity_type'])) ?> #<?= (int)$r['entity_id'] ?>
            </a>
          </td>
          <td><?= Security::h($r['step_label']) ?></td>
          <td><?= Security::h($r['requested_by_name'] ?? 'Unknown') ?></td>
          <td class="<?= $isOverdue ? 'text-danger' : 'text-muted' ?> text-sm">
            <?php if ($due): ?>
              <?= $isOverdue ? '<i class="bi bi-exclamation-triangle-fill"></i> ' : '' ?>
              <?= date('M j, Y g:ia', $due) ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <span class="badge" style="background:<?= $statusColors[$r['status']] ?? '#6b7280' ?>20;color:<?= $statusColors[$r['status']] ?? '#6b7280' ?>">
              <?= ucfirst(Security::h($r['status'])) ?>
            </span>
          </td>
          <td>
            <a href="/approvals/<?= (int)$r['id'] ?>/review" class="btn btn-sm btn-primary">Review</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
