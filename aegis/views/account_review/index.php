<?php ob_start(); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Account Reviews</h1>
    <p class="page-subtitle">Periodic access certification — approve or revoke user accounts</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('admin')): ?>
    <a href="/account-reviews/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Review</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div class="card">
  <div class="card-body" style="padding:0">
    <?php if ($reviews): ?>
    <table class="table">
      <thead>
        <tr>
          <th>Campaign</th>
          <th>Reviewer</th>
          <th>Due Date</th>
          <th>Progress</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reviews as $r):
          $total    = (int)$r['total_items'];
          $reviewed = (int)$r['reviewed_items'];
          $pct      = $total > 0 ? round(($reviewed / $total) * 100) : 0;
          $statusColors = ['pending'=>'gray','in_progress'=>'blue','complete'=>'green','cancelled'=>'red'];
          $sc = $statusColors[$r['status']] ?? 'gray';
        ?>
        <tr>
          <td>
            <a href="/account-reviews/<?= (int)$r['id'] ?>" style="font-weight:600;color:var(--primary);text-decoration:none">
              <?= Security::h($r['title']) ?>
            </a>
            <?php if ($r['scope']): ?>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= Security::h(substr($r['scope'],0,70)) ?></div>
            <?php endif; ?>
          </td>
          <td><?= $r['reviewer_name'] ? Security::h($r['reviewer_name']) : '<span style="color:var(--text-muted)">Unassigned</span>' ?></td>
          <td><?= $r['due_date'] ? date('M j, Y', strtotime($r['due_date'])) : '—' ?></td>
          <td style="min-width:140px">
            <?php if ($total > 0): ?>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                <div style="width:<?= $pct ?>%;height:100%;background:var(--primary);border-radius:3px"></div>
              </div>
              <span style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?= $reviewed ?>/<?= $total ?></span>
            </div>
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:12px">No items</span>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= $sc ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
          <td class="text-right">
            <a href="/account-reviews/<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state" style="padding:60px 20px">
      <div class="empty-icon"><i class="bi bi-person-check"></i></div>
      <h3>No account reviews</h3>
      <p>Create a review campaign to certify that user access across your systems is appropriate.</p>
      <?php if (Auth::can('admin')): ?>
      <a href="/account-reviews/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Review</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
