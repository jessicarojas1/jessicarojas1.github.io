<?php
$pageTitle    = 'Activity';
$activeModule = 'activity';
$breadcrumbs  = [['Activity', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title"><i class="bi bi-activity"></i> Recently Updated</h1><p class="page-subtitle">What's been happening across <?= $space ? Security::h($space['name']) : 'all spaces' ?>.</p></div>
  <div class="page-actions">
    <form method="GET" action="/activity" style="margin:0">
      <select name="space" class="form-select" data-auto-submit style="min-width:200px">
        <option value="">All spaces</option>
        <?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>" <?= ($space && (int)$space['id']===(int)$s['id'])?'selected':'' ?>><?= Security::h($s['name']) ?></option><?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-sm btn-ghost" type="submit">Filter</button></noscript>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php if (!$items): ?>
      <div class="empty-state-sm"><i class="bi bi-activity"></i><p>No recent activity yet.</p></div>
    <?php else: ?>
    <div class="activity-feed">
      <?php $lastDay = null; foreach ($items as $it):
        $day = date('Y-m-d', strtotime((string)$it['created_at']));
        if ($day !== $lastDay): $lastDay = $day; ?>
          <div class="form-hint" style="margin:14px 0 6px;font-weight:700;text-transform:uppercase;letter-spacing:.04em"><?= Security::h(View::fmtDate($it['created_at'], 'l, M j, Y')) ?></div>
        <?php endif; ?>
        <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-light)">
          <div style="width:30px;height:30px;border-radius:50%;background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;color:var(--primary);flex:none"><i class="bi <?= Security::h($it['icon']) ?>"></i></div>
          <div style="flex:1">
            <span style="font-weight:600"><?= Security::h($it['actor']) ?></span>
            <span class="form-hint"><?= Security::h($it['verb']) ?></span>
            <?php if ($it['link']): ?><a href="<?= Security::h($it['link']) ?>"><?= Security::h($it['target_title']) ?></a><?php else: ?><span><?= Security::h($it['target_title']) ?></span><?php endif; ?>
          </div>
          <div class="form-hint" style="white-space:nowrap"><?= Security::h(View::timeAgo($it['created_at'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
