<?php
/** Shared authenticated-app layout close. */
use Redoubt\Support\Security;
$NONCE = $NONCE ?? '';
?>
</main>
<div id="toast" class="toast" role="status" aria-live="polite"></div>

<?php
// Command palette (⌘K / Ctrl-K). Destinations are limited to what this user may access.
$pid = isset($programId) ? (int) $programId : (int) array_key_first($user['memberships'] ?? [0 => null]);
$nav = [['label' => 'Home / Dashboard', 'url' => '/app']];
if (!empty($hasProgram)) {
    $nav[] = ['label' => 'Program Overview', 'url' => '/app/overview'];
    $nav[] = ['label' => 'Search', 'url' => '/app/search'];
    $nav[] = ['label' => 'Program Assistant', 'url' => '/app/assistant'];
    $nav[] = ['label' => 'Notifications', 'url' => '/app/notifications'];
    $nav[] = ['label' => 'Status Report', 'url' => '/app/report'];
}
if (!empty($showAnn)) { $nav[] = ['label' => 'Announcements', 'url' => '/app/announcements']; }
if (!empty($showDocs)) { $nav[] = ['label' => 'Documents', 'url' => '/app/documents']; }
if (!empty($showTo)) { $nav[] = ['label' => 'Task Orders', 'url' => '/app/task-orders']; }
if (!empty($showJobs)) { $nav[] = ['label' => 'Jobs', 'url' => '/app/jobs']; }
if (!empty($showDir)) { $nav[] = ['label' => 'Directory', 'url' => '/app/directory']; }
if (!empty($showMilestones)) { $nav[] = ['label' => 'Milestones', 'url' => '/app/milestones']; }
if (!empty($hasProgram)) { $nav[] = ['label' => 'Quick Links', 'url' => '/app/quick-links']; $nav[] = ['label' => 'FAQ', 'url' => '/app/faq']; }
if (!empty($showContent)) { $nav[] = ['label' => 'Content Administration', 'url' => '/app/admin/content']; }
if (!empty($showOnboard)) { $nav[] = ['label' => 'Onboarding & Access', 'url' => '/app/admin/access']; }
if (!empty($showIam)) { $nav[] = ['label' => 'Access & Security (IAM)', 'url' => '/app/admin/iam']; }
if (!empty($showAnalytics)) { $nav[] = ['label' => 'Analytics & Health', 'url' => '/app/analytics']; $nav[] = ['label' => 'Audit Log', 'url' => '/app/admin/audit']; }
if (!empty($showSettings)) { $nav[] = ['label' => 'Settings & Branding', 'url' => '/app/admin/settings']; }
?>
<div id="palette" class="palette" hidden aria-hidden="true">
  <div class="palette-box" role="dialog" aria-label="Command palette">
    <input id="paletteInput" type="text" placeholder="Jump to… or type a question, then Enter" autocomplete="off">
    <ul id="paletteList"></ul>
    <div class="palette-hint">↑↓ to move · Enter to open · Esc to close</div>
  </div>
</div>
<script nonce="<?= Security::h($NONCE) ?>">
  window.REDOUBT_NAV = <?= Security::jsonForScript($nav) ?>;
  window.REDOUBT_PID = <?= (int) $pid ?>;
</script>
<script src="/assets/palette.js" nonce="<?= Security::h($NONCE) ?>"></script>
<?php if (!empty($appScript)): ?>
<script src="<?= Security::h($appScript) ?>" nonce="<?= Security::h($NONCE) ?>"></script>
<?php endif; ?>
</body>
</html>
