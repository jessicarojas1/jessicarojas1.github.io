<?php
/**
 * Executive home dashboard. Provided by AppController::home().
 * In scope: $user, $NONCE, $programs (id=>name), $programId (int), $data (array|null).
 */
use Redoubt\Support\Security;

$title = 'Dashboard';
$navActive = 'home';
require __DIR__ . '/partials/app_header.php';

$name = Security::h($user['name'] ?? 'User');
$usPerson = $user['is_us_person'] ?? null;
?>
<?php if ($data === null): ?>
  <div class="page-header"><h1 class="page-title">Welcome, <?= $name ?></h1></div>
  <div class="card"><div class="empty-state-sm">You are signed in, but you have no program access yet. A program administrator must assign you to a program and role. Server-side authorization shows no data without an explicit grant.</div></div>
<?php else: $p = $data['program']; ?>
  <div class="page-header">
    <div>
      <h1 class="page-title">Welcome back, <?= $name ?></h1>
      <div class="perm-key" style="margin-top:2px">
        <strong><?= Security::h($p['name'] ?? 'Program') ?></strong>
        <?php if (!empty($p['customer'])): ?> · Customer: <?= Security::h($p['customer']) ?><?php endif; ?>
        <?php if (!empty($p['contract_number'])): ?> · Contract: <?= Security::h($p['contract_number']) ?><?php endif; ?>
        <?php if (!empty($p['status'])): ?> · <span class="badge <?= $p['status'] === 'active' ? 'b-ok' : 'b-muted' ?>"><?= Security::h(ucfirst((string) $p['status'])) ?></span><?php endif; ?>
      </div>
    </div>
    <?php if (count($programs) > 1): ?>
    <form method="get" action="/app" style="display:flex;gap:8px;align-items:center">
      <select name="program_id" style="width:auto">
        <?php foreach ($programs as $pid => $label): ?><option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm" type="submit">Switch</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($usPerson === false): ?><div class="card" style="border-color:var(--warn)"><span class="badge b-warn">Non-US person</span> Export-controlled (ITAR/EAR) content is hidden from your view.</div><?php endif; ?>

  <!-- KPI tiles -->
  <div class="stat-grid">
    <?php foreach ($data['stats'] as $s): ?>
      <a class="stat-tile" href="<?= Security::h($s['url']) ?>?program_id=<?= (int) $programId ?>">
        <div class="stat-icon"><?= Security::h($s['icon']) ?></div>
        <div class="stat-num"><?= (int) $s['count'] ?></div>
        <div class="stat-lbl"><?= Security::h($s['label']) ?></div>
      </a>
    <?php endforeach; ?>
    <a class="stat-tile" href="/app/notifications">
      <div class="stat-icon">🔔</div>
      <div class="stat-num"><?= (int) $data['unread'] ?></div>
      <div class="stat-lbl">Unread</div>
    </a>
  </div>

  <div class="dash-cols">
    <div>
      <!-- My Actions -->
      <div class="card">
        <h3 class="dash-h">My Actions</h3>
        <?php if ($data['actions'] === []): ?>
          <div class="empty-state-sm">You're all caught up. 🎯</div>
        <?php else: foreach ($data['actions'] as $a): ?>
          <a class="action-row" href="<?= Security::h($a['url']) ?>?program_id=<?= (int) $programId ?>">
            <span class="badge b-warn"><?= (int) $a['count'] ?></span>
            <span><?= Security::h($a['label']) ?></span>
            <span class="action-go">→</span>
          </a>
        <?php endforeach; endif; ?>
      </div>

      <!-- Recent announcements -->
      <div class="card">
        <h3 class="dash-h">Recent Announcements <a class="dash-more" href="/app/announcements?program_id=<?= (int) $programId ?>">View all</a></h3>
        <?php if ($data['announcements'] === []): ?>
          <div class="empty-state-sm">No announcements.</div>
        <?php else: foreach ($data['announcements'] as $an): ?>
          <div class="feed-row">
            <?php $pc = ['critical' => 'b-warn', 'high' => 'b-warn']; ?>
            <span class="badge <?= $pc[$an['priority']] ?? 'b-muted' ?>"><?= Security::h(ucfirst((string) $an['priority'])) ?></span>
            <span class="feed-title"><?= Security::h($an['title']) ?></span>
            <?php if (empty($an['publish_at'])): ?><span class="badge b-muted">Draft</span><?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div>
      <!-- Upcoming milestones -->
      <div class="card">
        <h3 class="dash-h">Upcoming Milestones <a class="dash-more" href="/app/milestones?program_id=<?= (int) $programId ?>">Calendar</a></h3>
        <?php if ($data['milestones'] === []): ?>
          <div class="empty-state-sm">No upcoming milestones.</div>
        <?php else: foreach ($data['milestones'] as $m): ?>
          <div class="feed-row">
            <span class="ms-date"><?= Security::h($m['due_date'] ? date('M j', strtotime((string) $m['due_date'])) : '—') ?></span>
            <span class="feed-title"><?= Security::h($m['title']) ?></span>
            <span class="badge <?= ($m['days_out'] !== null && $m['days_out'] <= 7) ? 'b-warn' : 'b-muted' ?>"><?= Security::h((string) $m['type']) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Quick access -->
      <div class="card">
        <h3 class="dash-h">Quick Access</h3>
        <div class="quick-grid">
          <?php foreach ($data['stats'] as $s): ?>
            <a class="quick-card" href="<?= Security::h($s['url']) ?>?program_id=<?= (int) $programId ?>"><?= Security::h($s['icon']) ?> <?= Security::h($s['label']) ?></a>
          <?php endforeach; ?>
          <a class="quick-card" href="/app/search?program_id=<?= (int) $programId ?>">🔎 Search</a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
