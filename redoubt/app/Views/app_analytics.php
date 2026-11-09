<?php
/** Program Analytics / Health. In scope: $user,$NONCE,$programs,$programId,$data. */
use Redoubt\Support\Security;

$title = 'Analytics';
$navActive = 'analytics';
$breadcrumbs = ['Home' => '/app', 'Analytics' => null];
require __DIR__ . '/partials/app_header.php';

$act = $data['activity'];
$max = max(1, ...array_map(static fn ($d) => $d['count'], $act));
$n = count($act);
$vw = 720; $vh = 180; $pad = 24; $bw = ($vw - $pad * 2) / $n;
$ad = $data['adoption'];
$maxAction = max(1, ...array_map(static fn ($a) => $a['count'], $data['actions'] ?: [['count' => 1]]));
$rv = $data['reviews'] ?? ['by_stage' => ['concept' => 0, 'wip' => 0, 'approved' => 0], 'open' => 0, 'overdue' => 0, 'due_soon' => 0, 'avg_cycle_days' => null, 'oldest' => []];
$stepLbl = ['author' => 'Author', 'peer' => 'Peer', 'qa' => 'QA', 'exec' => 'Exec', 'done' => 'Done'];
?>
<div class="page-header">
  <h1 class="page-title">Program Analytics &amp; Health</h1>
  <form method="get" action="/app/analytics" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto"><?php foreach ($programs as $pid => $label): ?><option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<div class="stat-grid">
  <?php foreach (['announcements' => 'Announcements', 'documents' => 'Documents', 'task_orders' => 'Task Orders', 'jobs' => 'Jobs', 'milestones' => 'Milestones', 'audit_events' => 'Audit Events'] as $k => $lbl): ?>
    <div class="stat-tile"><div class="stat-num"><?= (int) $data['content'][$k] ?></div><div class="stat-lbl"><?= Security::h($lbl) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="dash-cols">
  <div>
    <div class="card">
      <h3 class="dash-h">Activity — last 14 days</h3>
      <svg viewBox="0 0 <?= $vw ?> <?= $vh ?>" width="100%" role="img" aria-label="Daily activity" style="max-width:100%">
        <?php foreach ($act as $i => $d):
          $h = (int) round(($vh - $pad * 2) * $d['count'] / $max);
          $x = $pad + $i * $bw; $y = $vh - $pad - $h; ?>
          <rect x="<?= round($x + 3, 1) ?>" y="<?= $y ?>" width="<?= round($bw - 6, 1) ?>" height="<?= max(2, $h) ?>" rx="3" fill="var(--accent)"><title><?= Security::h($d['date']) ?>: <?= (int) $d['count'] ?></title></rect>
          <?php if ($i % 2 === 0): ?><text x="<?= round($x + $bw / 2, 1) ?>" y="<?= $vh - 6 ?>" text-anchor="middle" font-size="10" fill="var(--muted)"><?= Security::h(date('n/j', strtotime($d['date']))) ?></text><?php endif; ?>
        <?php endforeach; ?>
        <line x1="<?= $pad ?>" y1="<?= $vh - $pad ?>" x2="<?= $vw - $pad ?>" y2="<?= $vh - $pad ?>" stroke="var(--line)"/>
      </svg>
    </div>
    <div class="card">
      <h3 class="dash-h">Top activity (30 days)</h3>
      <?php if (($data['actions'] ?? []) === []): ?><div class="empty-state-sm">No activity yet.</div>
      <?php else: foreach ($data['actions'] as $a): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:6px 0">
          <span class="perm-key" style="width:150px;flex:0 0 auto"><?= Security::h($a['action']) ?></span>
          <span style="flex:1;height:10px;background:var(--bg);border-radius:6px;overflow:hidden"><span style="display:block;height:100%;width:<?= (int) round(100 * $a['count'] / $maxAction) ?>%;background:var(--accent)"></span></span>
          <strong style="width:40px;text-align:right"><?= (int) $a['count'] ?></strong>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <div>
    <div class="card">
      <h3 class="dash-h">Adoption (7-day active)</h3>
      <div style="display:flex;align-items:center;gap:18px">
        <div style="font-size:44px;font-weight:800;color:var(--accent)"><?= (int) $ad['percent'] ?>%</div>
        <div class="perm-key"><strong><?= (int) $ad['active7'] ?></strong> of <strong><?= (int) $ad['members'] ?></strong> members active in the last 7 days.</div>
      </div>
      <div style="margin-top:12px;height:12px;background:var(--bg);border-radius:8px;overflow:hidden"><div style="height:100%;width:<?= (int) $ad['percent'] ?>%;background:linear-gradient(90deg,var(--accent),var(--accent-2))"></div></div>
    </div>
    <div class="card">
      <h3 class="dash-h">Compliance</h3>
      <div class="feed-row"><span class="feed-title">Append-only audit events recorded</span><strong><?= (int) $data['content']['audit_events'] ?></strong></div>
      <div class="feed-row"><span class="feed-title">Full audit trail</span><a href="/app/admin/audit?program_id=<?= (int) $programId ?>">Open audit log →</a></div>
      <p class="perm-key" style="margin-top:8px">Every access, publish, provision and revoke is captured for CMMC / NIST 800-171 accountability.</p>
    </div>
  </div>
</div>

<div class="card">
  <h3 class="dash-h">Review &amp; Approval — SLA &amp; aging</h3>
  <div class="stat-grid" style="margin-bottom:12px">
    <div class="stat-tile"><div class="stat-num"><?= (int) $rv['open'] ?></div><div class="stat-lbl">Open</div></div>
    <div class="stat-tile"><div class="stat-num" style="color:<?= $rv['overdue'] > 0 ? 'var(--risk)' : 'inherit' ?>"><?= (int) $rv['overdue'] ?></div><div class="stat-lbl">Overdue</div></div>
    <div class="stat-tile"><div class="stat-num" style="color:<?= $rv['due_soon'] > 0 ? 'var(--warn)' : 'inherit' ?>"><?= (int) $rv['due_soon'] ?></div><div class="stat-lbl">Due ≤ 7d</div></div>
    <div class="stat-tile"><div class="stat-num"><?= $rv['avg_cycle_days'] === null ? '—' : Security::h((string) $rv['avg_cycle_days']) ?></div><div class="stat-lbl">Avg cycle (days)</div></div>
    <div class="stat-tile"><div class="stat-num"><?= (int) $rv['by_stage']['approved'] ?></div><div class="stat-lbl">Approved</div></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <span class="badge b-muted">Concept: <?= (int) $rv['by_stage']['concept'] ?></span>
    <span class="badge b-warn">WIP: <?= (int) $rv['by_stage']['wip'] ?></span>
    <span class="badge b-ok">Approved: <?= (int) $rv['by_stage']['approved'] ?></span>
    <a class="badge b-muted" href="/app/reviews?program_id=<?= (int) $programId ?>" style="text-decoration:none">Open the workflow →</a>
  </div>
  <?php if (($rv['oldest'] ?? []) === []): ?>
    <div class="empty-state-sm">No open review items — the pipeline is clear.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Oldest open items</th><th>Stage</th><th>At step</th><th>Age</th><th>Due</th></tr></thead>
      <tbody>
      <?php foreach ($rv['oldest'] as $o): ?>
        <tr>
          <td><strong><?= Security::h($o['title']) ?></strong></td>
          <td><span class="badge <?= $o['stage'] === 'wip' ? 'b-warn' : 'b-muted' ?>"><?= Security::h(ucfirst($o['stage'])) ?></span></td>
          <td class="perm-key"><?= Security::h($stepLbl[$o['current_step']] ?? $o['current_step']) ?></td>
          <td><?= (int) $o['days_open'] ?>d</td>
          <td>
            <?php if ($o['due_date'] === null): ?><span class="perm-key">—</span>
            <?php elseif ($o['overdue']): ?><span class="badge b-risk">Overdue · <?= Security::h((string) $o['due_date']) ?></span>
            <?php else: ?><span class="perm-key"><?= Security::h((string) $o['due_date']) ?></span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
