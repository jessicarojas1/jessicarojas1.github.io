<?php
/** Printable Program Status Report. In scope: $user,$NONCE,$programs,$programId,$data. */
use Redoubt\Support\Security;

$title = 'Program Status Report';
$navActive = '';
require __DIR__ . '/partials/app_header.php';
$p = $data['program'];
$b = $data['branding'];
$pid = (int) $programId;
?>
<div class="page-header no-print">
  <h1 class="page-title">Program Status Report</h1>
  <div style="display:flex;gap:8px;align-items:center">
    <?php if (count($programs) > 1): ?>
    <form method="get" action="/app/report" style="display:flex;gap:8px;align-items:center">
      <select name="program_id" style="width:auto"><?php foreach ($programs as $id => $label): ?><option value="<?= (int) $id ?>"<?= $id === $pid ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm" type="submit">Switch</button>
    </form>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" id="printBtn" type="button">🖨 Print / Save as PDF</button>
  </div>
</div>

<div class="card report">
  <div class="report-head">
    <div style="display:flex;align-items:center;gap:12px">
      <?php if (!empty($b['logoUrl'])): ?><img src="<?= Security::h($b['logoUrl']) ?>" alt="" style="height:40px"><?php endif; ?>
      <div>
        <div style="font-size:22px;font-weight:800"><?= Security::h($p['name'] ?? 'Program') ?></div>
        <div class="perm-key"><?= Security::h($b['displayName'] ?: 'REDOUBT') ?> — Program Status Report</div>
      </div>
    </div>
    <div class="perm-key" style="text-align:right">
      <?php if (!empty($p['customer'])): ?>Customer: <?= Security::h($p['customer']) ?><br><?php endif; ?>
      <?php if (!empty($p['contract_number'])): ?>Contract: <?= Security::h($p['contract_number']) ?><br><?php endif; ?>
      Generated: <?= Security::h($data['generated']) ?><br>By: <?= Security::h($data['by']) ?>
    </div>
  </div>

  <h3 class="dash-h" style="margin-top:18px">Open Task Orders</h3>
  <?php $tos = array_values(array_filter($data['taskorders'], static fn ($t) => in_array($t['status'] ?? '', ['active', 'awarded'], true))); ?>
  <?php if ($tos === []): ?><div class="empty-state-sm">None.</div>
  <?php else: ?><div class="tablewrap"><table><thead><tr><th>Number</th><th>Title</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($tos as $t): ?><tr><td><?= Security::h($t['number']) ?></td><td><?= Security::h($t['title'] ?? '') ?></td><td><span class="badge b-ok"><?= Security::h(ucfirst((string) $t['status'])) ?></span></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>

  <h3 class="dash-h" style="margin-top:18px">Upcoming Milestones</h3>
  <?php if ($data['milestones'] === []): ?><div class="empty-state-sm">None.</div>
  <?php else: ?><div class="tablewrap"><table><thead><tr><th>Due</th><th>Milestone</th><th>Type</th></tr></thead><tbody>
    <?php foreach ($data['milestones'] as $m): ?><tr><td><?= Security::h($m['due_date'] ? date('M j, Y', strtotime((string) $m['due_date'])) : '—') ?></td><td><?= Security::h($m['title']) ?></td><td><?= Security::h((string) $m['type']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>

  <h3 class="dash-h" style="margin-top:18px">Recent Announcements</h3>
  <?php if ($data['announcements'] === []): ?><div class="empty-state-sm">None.</div>
  <?php else: foreach ($data['announcements'] as $a): ?>
    <div class="feed-row"><span class="badge <?= in_array($a['priority'], ['high', 'critical'], true) ? 'b-warn' : 'b-muted' ?>"><?= Security::h(ucfirst((string) $a['priority'])) ?></span><span class="feed-title"><?= Security::h($a['title']) ?></span><?php if (empty($a['publish_at'])): ?><span class="badge b-muted">Draft</span><?php endif; ?></div>
  <?php endforeach; endif; ?>

  <h3 class="dash-h" style="margin-top:18px">Open Positions</h3>
  <?php if ($data['openjobs'] === []): ?><div class="empty-state-sm">None.</div>
  <?php else: foreach ($data['openjobs'] as $j): ?>
    <div class="feed-row"><span class="feed-title"><?= Security::h($j['title']) ?></span></div>
  <?php endforeach; endif; ?>

  <p class="perm-key" style="margin-top:18px">Figures reflect what the report author is authorized to see. Pre-decisional — mark and handle per program classification guidance.</p>
</div>

<script nonce="<?= Security::h($NONCE) ?>">
  document.getElementById('printBtn')?.addEventListener('click', function () { window.print(); });
</script>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
