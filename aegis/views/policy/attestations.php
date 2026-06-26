<?php
// $campaigns already set by controller
$pageTitle    = 'Attestation Campaigns';
$activeModule = 'policy';
$breadcrumbs  = [['Policies', '/policy'], ['Attestations', null]];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Policy Attestation Campaigns</h1>
    <p class="page-subtitle">Track user sign-off on policies across the organisation</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('policy.attest')): ?>
      <a href="/policy/attestations/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Campaign</a>
    <?php endif; ?>
    <a href="/my-attestations" class="btn btn-ghost"><i class="bi bi-person-check"></i> My Attestations</a>
  </div>
</div>

<?php if (!$campaigns): ?>
  <div class="empty-state card">
    <div class="empty-icon"><i class="bi bi-pen"></i></div>
    <h3>No attestation campaigns yet</h3>
    <p>Create a campaign to require users to read and acknowledge a policy.</p>
    <?php if (Auth::can('policy.attest')): ?>
      <a href="/policy/attestations/create" class="btn btn-primary">Create Campaign</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-body p0">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Campaign Title</th>
            <th scope="col">Policy</th>
            <th scope="col">Due Date</th>
            <th scope="col">Progress</th>
            <th scope="col">Status</th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($campaigns as $c):
            $attested = (int)($c['attested_count'] ?? 0);
            $total    = (int)($c['total_users'] ?? 0);
            $pct      = $total > 0 ? round($attested / $total * 100) : 0;
            $barColor = $pct >= 80 ? 'var(--success)' : ($pct >= 50 ? 'var(--warning)' : 'var(--danger)');
          ?>
            <tr>
              <td>
                <a href="/policy/attestations/<?= $c['id'] ?>" style="font-weight:600">
                  <?= Security::h($c['title']) ?>
                </a>
              </td>
              <td><?= Security::h($c['policy_title']) ?></td>
              <td>
                <?php if ($c['due_date']): ?>
                  <?php $overdue = strtotime($c['due_date']) < time() && $c['is_active']; ?>
                  <span <?= $overdue ? 'style="color:var(--danger);font-weight:600"' : '' ?>>
                    <?= date('M j, Y', strtotime($c['due_date'])) ?>
                    <?= $overdue ? '<i class="bi bi-exclamation-circle-fill"></i>' : '' ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td style="min-width:160px">
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="flex:1;background:var(--bg-subtle);border-radius:999px;height:7px;overflow:hidden">
                    <div style="width:<?= $pct ?>%;background:<?= $barColor ?>;height:100%;border-radius:999px"></div>
                  </div>
                  <span class="text-sm" style="white-space:nowrap;color:<?= $barColor ?>;font-weight:600">
                    <?= $attested ?>/<?= $total ?>
                  </span>
                </div>
              </td>
              <td>
                <?php if ($c['is_active']): ?>
                  <span class="badge badge-published">Active</span>
                <?php else: ?>
                  <span class="badge badge-archived">Inactive</span>
                <?php endif; ?>
              </td>
              <td>
                <a href="/policy/attestations/<?= $c['id'] ?>" class="btn btn-ghost btn-sm">
                  <i class="bi bi-eye"></i> View
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
