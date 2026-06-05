<?php
$breadcrumbs = $breadcrumbs ?? [['BCP/DRP', null]];
ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Business Continuity Plans</h1>
    <p class="page-subtitle">DR plans, RTO/RPO targets and tabletop exercises</p>
  </div>
  <?php if (Auth::can('policy.write')): ?>
    <a href="/bcp/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New BCP Plan</a>
  <?php endif; ?>
</div>

<?php if (empty($plans)): ?>
  <div class="card"><div class="card-body text-muted" style="text-align:center;padding:48px">
    <i class="bi bi-shield-exclamation" style="font-size:48px;display:block;margin-bottom:16px;opacity:.3"></i>
    <p>No BCP plans yet. Create your first plan to get started.</p>
  </div></div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px">
    <?php foreach ($plans as $plan):
      $rtoColor = ($plan['rto_hours'] ?? 99) <= 4 ? 'var(--danger)' : (($plan['rto_hours'] ?? 99) <= 24 ? 'var(--warning)' : 'var(--success)');
      $rpoColor = ($plan['rpo_hours'] ?? 99) <= 1 ? 'var(--danger)' : (($plan['rpo_hours'] ?? 99) <= 4 ? 'var(--warning)' : 'var(--success)');
      $statusColors = ['draft'=>'#6b7280','active'=>'var(--success)','archived'=>'#9ca3af'];
      $sc = $statusColors[$plan['status']] ?? 'var(--text-muted)';
    ?>
      <div class="card" style="border-left:4px solid <?= $sc ?>">
        <div class="card-body">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
            <div>
              <div class="fw-600"><a href="/bcp/<?= (int)$plan['id'] ?>" style="text-decoration:none;color:inherit"><?= Security::h($plan['title']) ?></a></div>
              <div class="text-xs text-muted">v<?= Security::h($plan['version']) ?></div>
            </div>
            <span class="badge" style="background:<?= $sc ?>20;color:<?= $sc ?>"><?= Security::h(ucfirst($plan['status'])) ?></span>
          </div>
          <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
            <?php if ($plan['rto_hours']): ?>
              <span class="badge" style="background:<?= $rtoColor ?>20;color:<?= $rtoColor ?>">RTO ≤<?= (int)$plan['rto_hours'] ?>h</span>
            <?php endif; ?>
            <?php if ($plan['rpo_hours']): ?>
              <span class="badge" style="background:<?= $rpoColor ?>20;color:<?= $rpoColor ?>">RPO ≤<?= (int)$plan['rpo_hours'] ?>h</span>
            <?php endif; ?>
          </div>
          <div class="text-sm text-muted">
            <div>Owner: <?= Security::h($plan['owner_name'] ?? '—') ?></div>
            <div>Sections: <?= (int)$plan['section_count'] ?> &nbsp;|&nbsp; Exercises: <?= (int)$plan['exercise_count'] ?></div>
            <?php if ($plan['last_tested']): ?>
              <div>Last Tested: <?= date('M j, Y', strtotime($plan['last_tested'])) ?></div>
            <?php endif; ?>
            <?php if ($plan['next_test_date']): ?>
              <div>Next Test: <?= date('M j, Y', strtotime($plan['next_test_date'])) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php $content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php'; ?>
