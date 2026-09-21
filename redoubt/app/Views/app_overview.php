<?php
/** Program Overview hub. In scope: $user,$NONCE,$programs,$programId,$data. */
use Redoubt\Support\Security;

$title = 'Program';
$navActive = 'overview';
$breadcrumbs = ['Home' => '/app', 'Program' => null];
require __DIR__ . '/partials/app_header.php';
$p = $data['program'];
$pid = (int) $programId;
$resources = [
  ['📁', 'Documents', "/app/documents"], ['📄', 'Task Orders', "/app/task-orders"],
  ['💼', 'Jobs', "/app/jobs"], ['📅', 'Milestones', "/app/milestones"],
  ['👥', 'Directory', "/app/directory"], ['🔗', 'Quick Links', "/app/quick-links"],
  ['❓', 'FAQ', "/app/faq"], ['🔎', 'Search', "/app/search"],
  ['📊', 'Status Report', "/app/report"],
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($p['name'] ?? 'Program') ?></h1>
    <div class="perm-key" style="margin-top:2px">
      <?php if (!empty($p['customer'])): ?>Customer: <?= Security::h($p['customer']) ?><?php endif; ?>
      <?php if (!empty($p['contract_number'])): ?> · Contract: <?= Security::h($p['contract_number']) ?><?php endif; ?>
      <?php if (!empty($p['status'])): ?> · <span class="badge <?= $p['status'] === 'active' ? 'b-ok' : 'b-muted' ?>"><?= Security::h(ucfirst((string) $p['status'])) ?></span><?php endif; ?>
    </div>
  </div>
  <?php if (count($programs) > 1): ?>
  <form method="get" action="/app/overview" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto"><?php foreach ($programs as $id => $label): ?><option value="<?= (int) $id ?>"<?= $id === $pid ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3 class="dash-h">Program Resources</h3>
  <div class="quick-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
    <?php foreach ($resources as $r): ?>
      <a class="quick-card" href="<?= Security::h($r[2]) ?>?program_id=<?= $pid ?>"><?= Security::h($r[0]) ?> <?= Security::h($r[1]) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="dash-cols">
  <div>
    <div class="card">
      <h3 class="dash-h">Key Contacts <a class="dash-more" href="/app/directory?program_id=<?= $pid ?>">Directory</a></h3>
      <?php if ($data['pocs'] === []): ?><div class="empty-state-sm">No contacts available.</div>
      <?php else: foreach ($data['pocs'] as $c): ?>
        <div class="feed-row"><span class="feed-title"><strong><?= Security::h($c['name'] ?? '') ?></strong> <?php if (!empty($c['role_label'])): ?>· <?= Security::h($c['role_label']) ?><?php endif; ?></span><span class="perm-key"><?= Security::h($c['company'] ?? '') ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <div class="card">
      <h3 class="dash-h">Quick Links <a class="dash-more" href="/app/quick-links?program_id=<?= $pid ?>">All</a></h3>
      <?php if ($data['links'] === []): ?><div class="empty-state-sm">No quick links.</div>
      <?php else: foreach ($data['links'] as $l): ?>
        <div class="feed-row"><a class="feed-title" href="<?= Security::h($l['url']) ?>" target="_blank" rel="noopener">🔗 <?= Security::h($l['label']) ?> ↗</a></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <div>
    <div class="card">
      <h3 class="dash-h">Upcoming Milestones <a class="dash-more" href="/app/milestones?program_id=<?= $pid ?>">Calendar</a></h3>
      <?php if ($data['milestones'] === []): ?><div class="empty-state-sm">No upcoming milestones.</div>
      <?php else: foreach ($data['milestones'] as $m): ?>
        <div class="feed-row"><span class="ms-date"><?= Security::h($m['due_date'] ? date('M j', strtotime((string) $m['due_date'])) : '—') ?></span><span class="feed-title"><?= Security::h($m['title']) ?></span><span class="badge b-muted"><?= Security::h((string) $m['type']) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
    <div class="card">
      <h3 class="dash-h">FAQ <a class="dash-more" href="/app/faq?program_id=<?= $pid ?>">All</a></h3>
      <?php if ($data['faqs'] === []): ?><div class="empty-state-sm">No FAQ entries.</div>
      <?php else: foreach ($data['faqs'] as $f): ?>
        <div class="feed-row"><span class="feed-title">❓ <?= Security::h($f['question']) ?></span></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
