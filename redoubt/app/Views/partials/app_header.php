<?php
/**
 * Shared authenticated-app header/layout open.
 * Expects: $NONCE (string), $user (array), $title (string), $navActive (string),
 *          optionally $breadcrumbs (array<string,?string> label => href|null).
 */
use Redoubt\Support\Security;
use Redoubt\Support\Authorize;
use Redoubt\Support\Settings;

$NONCE = $NONCE ?? '';
$title = $title ?? 'REDOUBT';
$navActive = $navActive ?? '';
$user = $user ?? [];
$breadcrumbs = $breadcrumbs ?? [];

// Per-program branding is applied live when a program context is present.
$brand = (isset($programId) && \Redoubt\Support\Db::isConfigured())
    ? Settings::branding((int) $programId)
    : ['logoUrl' => null, 'displayName' => null, 'accent' => null];
$brandName = $brand['displayName'] ?: 'REDOUBT';
$unread = (!empty($user['id']) && \Redoubt\Support\Db::isConfigured())
    ? \Redoubt\Support\Notifications::unreadCount((int) $user['id']) : 0;

// Permission-aware nav: show a link if the user holds the permission in ANY program.
$canAny = static function (array $user, string $perm): bool {
    foreach (array_keys($user['memberships'] ?? []) as $pid) {
        if (Authorize::can($user, $perm, ['program_id' => (int) $pid])) {
            return true;
        }
    }
    return false;
};
$showIam = $canAny($user, 'access.view');
$showAnn = $canAny($user, 'announcement.view');
$showDocs = $canAny($user, 'document.view');
$showTo = $canAny($user, 'taskorder.view');
$showJobs = $canAny($user, 'job.view');
$showDir = $canAny($user, 'directory.view');
$hasProgram = ($user['memberships'] ?? []) !== [];
$showContent = $canAny($user, 'announcement.create') || $canAny($user, 'document.create')
    || $canAny($user, 'taskorder.create') || $canAny($user, 'job.create') || $canAny($user, 'contact.manage');
$showSettings = $canAny($user, 'branding.manage') || $canAny($user, 'program.config');
$showOnboard = $canAny($user, 'access.request') || $canAny($user, 'access.view');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Security::h($title) ?> — <?= Security::h($brandName) ?></title>
<link rel="stylesheet" href="/assets/app.css">
<?php if ($brand['accent']): ?><style nonce="<?= Security::h($NONCE) ?>">:root{--accent:<?= Security::h($brand['accent']) ?>}</style><?php endif; ?>
</head>
<body>
<header class="top">
  <a class="brand" href="/app">
    <?php if ($brand['logoUrl']): ?><img src="<?= Security::h($brand['logoUrl']) ?>" alt="" style="height:26px;width:auto;border-radius:6px"><?php else: ?><span class="logo"></span><?php endif; ?>
    <?= Security::h($brandName) ?>
  </a>
  <nav class="nav">
    <a href="/app"<?= $navActive === 'home' ? ' class="active"' : '' ?>>Home</a>
    <?php if ($showAnn): ?><a href="/app/announcements"<?= $navActive === 'announcements' ? ' class="active"' : '' ?>>Announcements</a><?php endif; ?>
    <?php if ($showDocs): ?><a href="/app/documents"<?= $navActive === 'documents' ? ' class="active"' : '' ?>>Documents</a><?php endif; ?>
    <?php if ($showTo): ?><a href="/app/task-orders"<?= $navActive === 'taskorders' ? ' class="active"' : '' ?>>Task Orders</a><?php endif; ?>
    <?php if ($showJobs): ?><a href="/app/jobs"<?= $navActive === 'jobs' ? ' class="active"' : '' ?>>Jobs</a><?php endif; ?>
    <?php if ($showDir): ?><a href="/app/directory"<?= $navActive === 'directory' ? ' class="active"' : '' ?>>Directory</a><?php endif; ?>
    <?php if ($hasProgram): ?><a href="/app/search"<?= $navActive === 'search' ? ' class="active"' : '' ?>>Search</a><?php endif; ?>
    <?php if ($showContent): ?><a href="/app/admin/content"<?= $navActive === 'content' ? ' class="active"' : '' ?>>Content</a><?php endif; ?>
    <?php if ($showOnboard): ?><a href="/app/admin/access"<?= $navActive === 'onboarding' ? ' class="active"' : '' ?>>Onboarding</a><?php endif; ?>
    <?php if ($showIam): ?><a href="/app/admin/iam"<?= $navActive === 'iam' ? ' class="active"' : '' ?>>Access &amp; Security</a><?php endif; ?>
    <?php if ($showSettings): ?><a href="/app/admin/settings"<?= $navActive === 'settings' ? ' class="active"' : '' ?>>Settings</a><?php endif; ?>
    <a href="/about" title="Program documentation">Docs</a>
  </nav>
  <div class="who">
    <a href="/app/notifications" title="Notifications" style="margin-right:12px">🔔<?php if ($unread > 0): ?> <span class="badge b-warn" style="font-size:10px"><?= (int) $unread ?></span><?php endif; ?></a>
    <?= Security::h($user['name'] ?? 'User') ?><a href="/auth/logout">Sign out</a>
  </div>
</header>
<main class="wrap">
  <?php if ($breadcrumbs): ?>
  <nav class="breadcrumbs">
    <?php $i = 0; $n = count($breadcrumbs); foreach ($breadcrumbs as $label => $href): $i++; ?>
      <?php if ($href && $i < $n): ?><a href="<?= Security::h($href) ?>"><?= Security::h($label) ?></a> › <?php else: ?><?= Security::h($label) ?><?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
