<?php
// Variables: $campaign, $attested, $pending
$totalUsers  = count($attested) + count($pending);
$attestedCnt = count($attested);
$pendingCnt  = count($pending);
$pct         = $totalUsers > 0 ? round($attestedCnt / $totalUsers * 100) : 0;
$barColor    = $pct >= 80 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626');
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($campaign['title']) ?></h1>
    <p class="page-subtitle">Attestation Campaign · <?= Security::h($campaign['policy_title']) ?></p>
  </div>
  <div class="page-actions">
    <button data-print class="btn btn-ghost"><i class="bi bi-printer"></i> Print</button>
    <a href="/policy/attestations" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> All Campaigns</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start">

  <!-- Left: Campaign details -->
  <div>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> Details</h3></div>
      <div class="card-body">
        <div class="detail-row"><span>Policy</span><strong><?= Security::h($campaign['policy_title']) ?></strong></div>
        <div class="detail-row">
          <span>Due Date</span>
          <strong>
            <?php if ($campaign['due_date']): ?>
              <?php $overdue = strtotime($campaign['due_date']) < time() && $campaign['is_active']; ?>
              <span <?= $overdue ? 'style="color:#dc2626"' : '' ?>>
                <?= date('M j, Y', strtotime($campaign['due_date'])) ?>
                <?= $overdue ? ' (Overdue)' : '' ?>
              </span>
            <?php else: ?>
              <span class="text-muted">No deadline</span>
            <?php endif; ?>
          </strong>
        </div>
        <div class="detail-row">
          <span>Status</span>
          <strong>
            <?= $campaign['is_active']
              ? '<span class="badge badge-published">Active</span>'
              : '<span class="badge badge-archived">Inactive</span>' ?>
          </strong>
        </div>
        <div class="detail-row">
          <span>Created</span>
          <strong><?= date('M j, Y', strtotime($campaign['created_at'])) ?></strong>
        </div>
      </div>
    </div>

    <!-- Stats card -->
    <div class="card" style="margin-top:16px">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-bar-chart-fill"></i> Progress</h3></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;text-align:center;margin-bottom:16px">
          <div>
            <div style="font-size:1.75rem;font-weight:700;color:var(--primary)"><?= $totalUsers ?></div>
            <div class="text-muted text-sm">Total</div>
          </div>
          <div>
            <div style="font-size:1.75rem;font-weight:700;color:#059669"><?= $attestedCnt ?></div>
            <div class="text-muted text-sm">Attested</div>
          </div>
          <div>
            <div style="font-size:1.75rem;font-weight:700;color:#dc2626"><?= $pendingCnt ?></div>
            <div class="text-muted text-sm">Pending</div>
          </div>
        </div>
        <div style="background:#e5e7eb;border-radius:999px;height:10px;overflow:hidden">
          <div style="width:<?= $pct ?>%;background:<?= $barColor ?>;height:100%;border-radius:999px;transition:width .3s"></div>
        </div>
        <div style="text-align:center;margin-top:8px;font-weight:600;color:<?= $barColor ?>"><?= $pct ?>% complete</div>
      </div>
    </div>
  </div>

  <!-- Right: Attested + Pending tables -->
  <div>
    <!-- Attested users -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-check-circle-fill" style="color:#059669"></i> Attested</h3>
        <span class="badge"><?= $attestedCnt ?></span>
      </div>
      <div class="card-body p0">
        <?php if ($attested): ?>
          <table class="table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Attested On</th>
                <th>IP Address</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($attested as $a): ?>
                <tr>
                  <td><i class="bi bi-person-check-fill" style="color:#059669"></i> <?= Security::h($a['user_name']) ?></td>
                  <td><?= Security::h($a['email']) ?></td>
                  <td><?= date('M j, Y g:i A', strtotime($a['attested_at'])) ?></td>
                  <td><span class="mono text-muted"><?= Security::h($a['ip_address'] ?? '—') ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div style="padding:24px;text-align:center;color:var(--text-muted)">
            <i class="bi bi-hourglass-split" style="font-size:1.5rem"></i>
            <p>No attestations yet.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Pending users -->
    <div class="card" style="margin-top:16px">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-clock-fill" style="color:#d97706"></i> Pending</h3>
        <span class="badge"><?= $pendingCnt ?></span>
      </div>
      <div class="card-body p0">
        <?php if ($pending): ?>
          <table class="table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pending as $p): ?>
                <tr>
                  <td><i class="bi bi-person-fill" style="color:#94a3b8"></i> <?= Security::h($p['name']) ?></td>
                  <td><?= Security::h($p['email']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div style="padding:24px;text-align:center;color:#059669">
            <i class="bi bi-check-circle-fill" style="font-size:1.5rem"></i>
            <p style="margin-top:8px;font-weight:600">All users have attested!</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
