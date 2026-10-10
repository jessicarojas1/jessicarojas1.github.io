<?php
/**
 * Shared authenticated-app header/layout open.
 * Expects: $NONCE (string), $user (array), $title (string), $navActive (string),
 *          optionally $breadcrumbs (array<string,?string> label => href|null).
 */
use Verity\Support\Security;
use Verity\Support\Authorize;
use Verity\Support\Settings;
use Verity\Support\Db;

$NONCE = $NONCE ?? '';
$title = $title ?? 'Verity';
$navActive = $navActive ?? '';
$user = $user ?? [];
$breadcrumbs = $breadcrumbs ?? [];

$brand = Db::isConfigured() ? Settings::branding() : ['logoUrl' => null, 'displayName' => null, 'accent' => null];
$brandName = $brand['displayName'] ?: 'Verity';

$can = static fn (string $perm): bool => Authorize::can($user, $perm);
$showDashboard = $can('dashboard.view');
$showIdentities = $can('identity.view') || $can('identity.view.reports');
$showUnmatched = $can('account.view.unmatched');
$showMatrix = $can('matrix.view.enterprise') || $can('matrix.view.supervisor') || $can('matrix.view.application.owned') || $can('matrix.view.privileged');
$showApplications = $can('application.view') || $can('application.view.owned');
$showCampaigns = $can('campaign.view') || $can('campaign.manage');
$showMyReviews = $can('campaign.review');
$showDynamicFields = $can('dynamicfield.manage');
$showIam = $can('iam.view');
$showAudit = $can('audit.view');
$showSettings = $can('settings.manage');
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
    <?php if ($brand['logoUrl']): ?><img src="<?= Security::h($brand['logoUrl']) ?>" alt="" class="brand-logo"><?php else: ?><span class="logo" aria-hidden="true">V</span><?php endif; ?>
    <?= Security::h($brandName) ?>
  </a>
  <nav class="nav">
    <?php if ($showDashboard): ?><a href="/app"<?= $navActive === 'home' ? ' class="active"' : '' ?>>Dashboard</a><?php endif; ?>
    <?php if ($showIdentities): ?><a href="/app/identities"<?= $navActive === 'identities' ? ' class="active"' : '' ?>>Identities</a><?php endif; ?>
    <?php if ($showUnmatched): ?><a href="/app/accounts/unmatched"<?= $navActive === 'unmatched' ? ' class="active"' : '' ?>>Unmatched Accounts</a><?php endif; ?>
    <?php if ($showMatrix): ?><a href="/app/matrix"<?= $navActive === 'matrix' ? ' class="active"' : '' ?>>Access Matrix</a><?php endif; ?>
    <?php if ($showApplications): ?><a href="/app/applications"<?= $navActive === 'applications' ? ' class="active"' : '' ?>>Applications</a><?php endif; ?>
    <?php if ($showCampaigns): ?><a href="/app/campaigns"<?= $navActive === 'campaigns' ? ' class="active"' : '' ?>>Campaigns</a><?php endif; ?>
    <?php if ($showMyReviews): ?><a href="/app/campaigns/my-reviews"<?= $navActive === 'myreviews' ? ' class="active"' : '' ?>>My Reviews</a><?php endif; ?>
    <?php if ($showDynamicFields): ?><a href="/app/admin/dynamic-fields"<?= $navActive === 'dynamicfields' ? ' class="active"' : '' ?>>Dynamic Fields</a><?php endif; ?>
    <?php if ($showAudit): ?><a href="/app/admin/audit"<?= $navActive === 'audit' ? ' class="active"' : '' ?>>Audit</a><?php endif; ?>
    <?php if ($showIam): ?><a href="/app/admin/iam"<?= $navActive === 'iam' ? ' class="active"' : '' ?>>Access &amp; Security</a><?php endif; ?>
    <?php if ($showSettings): ?><a href="/app/admin/settings"<?= $navActive === 'settings' ? ' class="active"' : '' ?>>Settings</a><?php endif; ?>
  </nav>
  <div class="who">
    <a href="/app/profile" class="who-name"><?= Security::h($user['name'] ?? 'User') ?></a>
    <a href="/auth/logout">Sign out</a>
  </div>
</header>
<main class="wrap">
  <?php if ($breadcrumbs): ?>
  <nav class="breadcrumbs" aria-label="Breadcrumb">
    <?php $i = 0; $n = count($breadcrumbs); foreach ($breadcrumbs as $label => $href): $i++; ?>
      <?php if ($href && $i < $n): ?><a href="<?= Security::h($href) ?>"><?= Security::h($label) ?></a> <span aria-hidden="true">&rsaquo;</span> <?php else: ?><span aria-current="page"><?= Security::h($label) ?></span><?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
