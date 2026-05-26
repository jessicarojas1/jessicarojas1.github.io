<?php
// Variables: $records (completed attestations), $pending (campaigns awaiting user attestation)
?>

<div class="page-header">
  <div>
    <h1 class="page-title">My Attestations</h1>
    <p class="page-subtitle">Policies you have signed off on, and any pending sign-offs</p>
  </div>
  <a href="/policy/attestations" class="btn btn-ghost"><i class="bi bi-people"></i> All Campaigns</a>
</div>

<!-- Pending / action-required section -->
<?php if ($pending): ?>
  <div class="card" style="border:2px solid #fbbf24;margin-bottom:24px">
    <div class="card-header" style="background:#fffbeb">
      <h3 class="card-title" style="color:#92400e">
        <i class="bi bi-exclamation-triangle-fill" style="color:#d97706"></i>
        Action Required — <?= count($pending) ?> Pending Attestation<?= count($pending) !== 1 ? 's' : '' ?>
      </h3>
    </div>
    <div class="card-body p0">
      <table class="table">
        <thead>
          <tr>
            <th>Campaign</th>
            <th>Policy</th>
            <th>Due Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $p): ?>
            <tr>
              <td><strong><?= Security::h($p['title']) ?></strong></td>
              <td><a href="/policy/<?= $p['policy_id'] ?>"><?= Security::h($p['policy_title']) ?></a></td>
              <td>
                <?php if ($p['due_date']): ?>
                  <?php $overdue = strtotime($p['due_date']) < time(); ?>
                  <span <?= $overdue ? 'style="color:#dc2626;font-weight:600"' : '' ?>>
                    <?= date('M j, Y', strtotime($p['due_date'])) ?>
                    <?= $overdue ? '<span class="badge badge-danger" style="margin-left:6px">Overdue</span>' : '' ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">No deadline</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="/policy/<?= $p['policy_id'] ?>/attest" class="btn btn-primary btn-sm">
                  <i class="bi bi-pen-fill"></i> Attest Now
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="alert-box success" style="margin-bottom:24px">
    <i class="bi bi-check-circle-fill"></i>
    <strong>All up to date!</strong> You have no pending attestations.
  </div>
<?php endif; ?>

<!-- Completed attestations -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-check-circle-fill" style="color:#059669"></i> Completed Attestations</h3>
    <span class="badge"><?= count($records) ?></span>
  </div>
  <div class="card-body p0">
    <?php if ($records): ?>
      <table class="table">
        <thead>
          <tr>
            <th>Policy</th>
            <th>Attested On</th>
            <th>IP Address</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r): ?>
            <tr>
              <td>
                <a href="/policy/<?= $r['policy_id'] ?>"><?= Security::h($r['policy_title']) ?></a>
              </td>
              <td><?= date('M j, Y g:i A', strtotime($r['attested_at'])) ?></td>
              <td><span class="mono text-muted"><?= Security::h($r['ip_address'] ?? '—') ?></span></td>
              <td class="text-muted"><?= $r['notes'] ? Security::h($r['notes']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="empty-state" style="padding:40px 24px">
        <div class="empty-icon"><i class="bi bi-pen"></i></div>
        <h3>No attestations yet</h3>
        <p>When you attest a policy, it will appear here as a permanent record.</p>
      </div>
    <?php endif; ?>
  </div>
</div>
