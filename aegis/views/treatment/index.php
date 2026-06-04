<?php
// Strategy badge colors
$strategyColors = [
    'mitigate' => ['bg' => '#3b82f620', 'color' => '#3b82f6', 'border' => '#3b82f640'],
    'transfer' => ['bg' => '#8b5cf620', 'color' => '#8b5cf6', 'border' => '#8b5cf640'],
    'accept'   => ['bg' => '#f59e0b20', 'color' => '#f59e0b', 'border' => '#f59e0b40'],
    'avoid'    => ['bg' => '#ef444420', 'color' => '#ef4444', 'border' => '#ef444440'],
];
// Status badge styles
$statusStyles = [
    'draft'     => ['bg' => '#a1a1aa20', 'color' => '#a1a1aa', 'border' => '#a1a1aa40'],
    'active'    => ['bg' => 'rgba(22, 163, 74, .08)', 'color' => 'var(--primary)', 'border' => 'rgba(22, 163, 74, .20)'],
    'completed' => ['bg' => '#05966920', 'color' => '#059669', 'border' => '#05966940'],
    'cancelled' => ['bg' => '#a1a1aa20', 'color' => '#a1a1aa', 'border' => '#a1a1aa40'],
];
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Treatment Plans</h1>
    <p class="page-subtitle">Structured plans with milestone tracking for each risk.</p>
  </div>
  <div class="page-actions">
    <a href="/risk" class="btn btn-ghost"><i class="bi bi-exclamation-triangle-fill"></i> Risk Register</a>
  </div>
</div>

<!-- Stat chips -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px">
  <div class="stat-chip" style="background:rgba(22, 163, 74, .08);border:1px solid rgba(22, 163, 74, .20);border-radius:10px;padding:12px 20px;min-width:130px">
    <div style="font-size:24px;font-weight:700;color:var(--primary)"><?= (int)($stats['active_count'] ?? 0) ?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Active Plans</div>
  </div>
  <div class="stat-chip" style="background:#05966920;border:1px solid #05966940;border-radius:10px;padding:12px 20px;min-width:130px">
    <div style="font-size:24px;font-weight:700;color:#059669"><?= (int)($stats['completed_count'] ?? 0) ?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Completed Plans</div>
  </div>
  <div class="stat-chip" style="background:#ef444420;border:1px solid #ef444440;border-radius:10px;padding:12px 20px;min-width:130px">
    <div style="font-size:24px;font-weight:700;color:#ef4444"><?= (int)($stats['overdue_count'] ?? 0) ?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Overdue Plans</div>
  </div>
</div>

<?php if (empty($plans)): ?>
  <div class="card">
    <div class="card-body" style="text-align:center;padding:64px 20px">
      <i class="bi bi-shield-check" style="font-size:48px;color:var(--border);display:block;margin-bottom:16px"></i>
      <h3 style="margin:0 0 8px;color:var(--text-muted)">No treatment plans yet</h3>
      <p style="color:var(--text-muted);margin:0 0 20px">
        Create treatment plans from individual risks in the
        <a href="/risk" style="color:var(--primary)">Risk Register</a>.
      </p>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-body" style="padding:0">
      <table class="data-table">
        <thead>
          <tr>
            <th>Risk</th>
            <th>Plan Title</th>
            <th>Strategy</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Owner</th>
            <th>Target Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($plans as $plan):
            $sc  = $strategyColors[$plan['strategy']] ?? $strategyColors['mitigate'];
            $st  = $statusStyles[$plan['status']] ?? $statusStyles['draft'];
            $total     = (int)$plan['total_milestones'];
            $completed = (int)$plan['completed_milestones'];
            $pct       = $total > 0 ? (int)round(($completed / $total) * 100) : 0;
            $overdue   = $plan['target_date'] && $plan['target_date'] < date('Y-m-d') && $plan['status'] === 'active';
          ?>
          <tr>
            <td>
              <a href="/risk/<?= (int)$plan['risk_id'] ?>" style="color:var(--primary);font-size:13px">
                <?= Security::h($plan['risk_title']) ?>
              </a>
            </td>
            <td style="font-weight:500"><?= Security::h($plan['title']) ?></td>
            <td>
              <span class="status-chip" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['border'] ?>">
                <?= ucfirst(Security::h($plan['strategy'])) ?>
              </span>
            </td>
            <td>
              <span class="status-chip" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
                <?= ucfirst(Security::h($plan['status'])) ?>
              </span>
            </td>
            <td style="min-width:140px">
              <?php if ($total > 0): ?>
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="flex:1;height:6px;background:var(--border);border-radius:4px;overflow:hidden">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 100 ? '#059669' : 'var(--primary)' ?>;border-radius:4px;transition:width .3s"></div>
                  </div>
                  <span style="font-size:11px;color:var(--text-muted);white-space:nowrap"><?= $completed ?>/<?= $total ?></span>
                </div>
              <?php else: ?>
                <span style="font-size:12px;color:var(--text-muted)">No milestones</span>
              <?php endif; ?>
            </td>
            <td class="text-sm"><?= Security::h($plan['owner_name'] ?? '—') ?></td>
            <td class="text-sm <?= $overdue ? 'text-danger' : '' ?>">
              <?php if ($plan['target_date']): ?>
                <?= date('M j, Y', strtotime($plan['target_date'])) ?>
                <?php if ($overdue): ?><i class="bi bi-exclamation-circle-fill" style="color:#ef4444;margin-left:4px"></i><?php endif; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <a href="/treatment/<?= (int)$plan['id'] ?>" class="btn btn-sm btn-secondary">
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
