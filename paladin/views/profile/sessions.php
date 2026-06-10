<?php
$pageTitle    = 'My Sessions';
$activeModule = 'profile_sessions';
$breadcrumbs  = [['My Profile', null], ['Sessions', null]];
ob_start();
// Lightweight device/browser hint from the user agent.
$describe = function (?string $ua): string {
    $ua = (string)$ua;
    $os = 'Unknown OS';
    foreach (['Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Macintosh' => 'macOS', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Linux' => 'Linux'] as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $os = $label; break; }
    }
    $br = 'browser';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $br = $label; break; }
    }
    return $br . ' on ' . $os;
};
?>
<div class="page-header">
  <div><h1 class="page-title">Active sessions</h1><p class="page-subtitle">Where you're signed in. End any session you don't recognise.</p></div>
  <?php if (count($sessions) > 1): ?>
  <div class="page-actions">
    <form method="POST" action="/profile/sessions/revoke-others" style="margin:0">
      <?= Security::csrfField() ?>
      <button class="btn btn-danger" type="submit" data-confirm-click="Sign out of all other sessions?"><i class="bi bi-box-arrow-right"></i> Sign out other sessions</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table" style="margin:0">
    <thead><tr><th>Device</th><th>IP address</th><th>Last active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $s): $isCurrent = $s['id'] === $current; ?>
      <tr>
        <td><i class="bi bi-display"></i> <?= Security::h($describe($s['user_agent'])) ?></td>
        <td class="form-hint"><?= Security::h($s['ip_address'] ?: '—') ?></td>
        <td class="form-hint"><?= Security::h(View::timeAgo($s['last_seen_at'])) ?></td>
        <td style="text-align:right"><?php if ($isCurrent): ?><span class="badge badge-green">This device</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sessions): ?>
      <tr><td colspan="4" class="empty-row"><div class="empty-state-sm"><i class="bi bi-laptop"></i><p>No tracked sessions.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
