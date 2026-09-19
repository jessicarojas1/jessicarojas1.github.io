<?php
/**
 * Authenticated application home. Provided by AppController::home().
 * $user, $capabilities, $NONCE in scope.
 */
use Redoubt\Support\Security;

$user = $user ?? [];
$capabilities = $capabilities ?? [];
$memberships = $user['memberships'] ?? [];
$usPerson = $user['is_us_person'] ?? null;

$title = 'Home';
$navActive = 'home';
require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header"><h1 class="page-title">Welcome, <?= Security::h($user['name'] ?? 'User') ?></h1></div>

<div class="card">
  <?php if ($usPerson === true): ?>
    <span class="badge b-ok">US person — export-controlled access eligible</span>
  <?php elseif ($usPerson === false): ?>
    <span class="badge b-warn">Non-US person — ITAR/EAR zones blocked</span>
  <?php else: ?>
    <span class="badge b-muted">US-person status not yet verified</span>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;font-size:16px">Your programs &amp; effective permissions</h3>
  <?php if ($memberships === []): ?>
    <div class="empty-state-sm">You are signed in, but you have no program access yet. A program administrator must assign you to a program and role — server-side authorization shows no data without an explicit grant.</div>
  <?php else: ?>
    <?php foreach ($memberships as $pid => $m): ?>
      <div style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;background:var(--bg)">
        <div style="font-weight:700">Program #<?= (int) $pid ?> — roles: <?= Security::h(implode(', ', $m['roles'] ?? [])) ?></div>
        <div class="perm-key" style="margin-top:6px"><?= Security::h(implode('  ·  ', $capabilities[$pid] ?? [])) ?: 'no permissions' ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
