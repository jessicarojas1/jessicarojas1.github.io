<?php
/** Leadership portfolio roll-up. In scope: $user,$NONCE,$rows,$totals. */
use Redoubt\Support\Security;

$title = 'Portfolio';
$navActive = 'portfolio';
$breadcrumbs = ['Home' => '/app', 'Portfolio' => null];
require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header"><h1 class="page-title">Program Portfolio</h1></div>

<div class="stat-grid">
  <div class="stat-tile"><div class="stat-num"><?= (int) $totals['programs'] ?></div><div class="stat-lbl">Programs</div></div>
  <div class="stat-tile"><div class="stat-num"><?= (int) $totals['members'] ?></div><div class="stat-lbl">Members</div></div>
  <div class="stat-tile"><div class="stat-num"><?= (int) $totals['open_jobs'] ?></div><div class="stat-lbl">Open Jobs</div></div>
  <div class="stat-tile"><div class="stat-num"><?= (int) $totals['milestones'] ?></div><div class="stat-lbl">Upcoming Dates</div></div>
  <div class="stat-tile"><div class="stat-num"><?= (int) $totals['pending'] ?></div><div class="stat-lbl">Pending Access</div></div>
</div>

<div class="card">
  <h3 class="dash-h">All programs</h3>
  <div class="tablewrap"><table>
    <thead><tr><th>Program</th><th>Customer / Contract</th><th>Status</th><th>Members</th><th>Open jobs</th><th>Upcoming</th><th>Docs</th><th>7-day activity</th><th>Pending</th><th style="width:1%"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= Security::h($r['name']) ?></strong></td>
        <td class="perm-key"><?= Security::h((string) ($r['customer'] ?: '—')) ?><?php if (!empty($r['contract'])): ?> · <?= Security::h($r['contract']) ?><?php endif; ?></td>
        <td><span class="badge <?= $r['status'] === 'active' ? 'b-ok' : 'b-muted' ?>"><?= Security::h(ucfirst((string) $r['status'])) ?></span></td>
        <td><?= (int) $r['members'] ?></td>
        <td><?= (int) $r['open_jobs'] ?></td>
        <td><?= (int) $r['milestones'] ?></td>
        <td><?= (int) $r['documents'] ?></td>
        <td><?= (int) $r['activity7'] ?></td>
        <td><?= $r['pending'] === null ? '—' : (int) $r['pending'] ?></td>
        <td><a class="btn btn-sm btn-primary" href="/app?program_id=<?= (int) $r['id'] ?>">Open →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="perm-key" style="margin-top:8px">Counts cover every program you belong to. Pending-access shows only where you may view access; open a program for its full role-aware dashboard.</p>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
