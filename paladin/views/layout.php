<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $__brandName = Branding::name(); ?>
<title><?= Security::h($pageTitle ?? $__brandName) ?> — <?= Security::h($__brandName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="/public/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="/public/css/app.css?v=7">
<link rel="manifest" href="/public/manifest.json">
<meta name="csrf-token" content="<?= Security::generateCsrfToken() ?>">
<script nonce="<?= Security::nonce() ?>">(function(){var t=localStorage.getItem('paladin-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
<?= Branding::accentStyleTag() ?>
<?php $__customCss = Branding::customCss(); if ($__customCss !== ''): ?><style nonce="<?= Security::nonce() ?>"><?= $__customCss ?></style><?php endif; ?>
</head>
<body>

<?php $u = Auth::user(); ?>

<aside class="sidebar" id="sidebar">
  <a href="/" class="sidebar-brand" style="text-decoration:none">
    <?php $__logoData = Branding::logo(); ?>
    <?php if ($__logoData): ?>
      <img src="<?= Security::h($__logoData) ?>" alt="<?= Security::h($__brandName) ?> logo"
           class="brand-logo-img" data-logo-fallback
           style="width:36px;height:36px;object-fit:contain;border-radius:8px">
      <div class="brand-icon brand-logo-fallback" style="display:none"><i class="bi bi-journal-richtext"></i></div>
    <?php else: ?>
      <div class="brand-icon"><i class="bi bi-journal-richtext"></i></div>
    <?php endif; ?>
    <div class="brand-text">
      <span class="brand-name"><?= Security::h($__brandName) ?></span>
      <span class="brand-sub">Process · Approval · Library</span>
    </div>
  </a>

  <nav class="sidebar-nav">
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="overview"><span>Overview</span><i class="bi bi-chevron-down nav-acc-chevron"></i></button>
      <div class="nav-acc-body" id="nav-acc-overview">
        <a href="/" class="nav-item <?= ($activeModule ?? '')==='dashboard'?'active':'' ?>"><i class="bi bi-grid-1x2-fill"></i><span>Dashboard</span></a>
      </div>
    </div>

    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="library"><span>Library</span><i class="bi bi-chevron-down nav-acc-chevron"></i></button>
      <div class="nav-acc-body" id="nav-acc-library">
        <a href="/spaces"    class="nav-item <?= ($activeModule ?? '')==='spaces'?'active':'' ?>"><i class="bi bi-collection-fill"></i><span>Spaces</span></a>
        <a href="/documents" class="nav-item <?= ($activeModule ?? '')==='documents'?'active':'' ?>"><i class="bi bi-file-earmark-text-fill"></i><span>Documents</span></a>
        <a href="/processes" class="nav-item <?= ($activeModule ?? '')==='processes'?'active':'' ?>"><i class="bi bi-diagram-3-fill"></i><span>Processes</span></a>
        <a href="/templates" class="nav-item <?= ($activeModule ?? '')==='templates'?'active':'' ?>"><i class="bi bi-files"></i><span>Templates</span></a>
        <a href="/blog"      class="nav-item <?= ($activeModule ?? '')==='blog'?'active':'' ?>"><i class="bi bi-newspaper"></i><span>Blog</span></a>
      </div>
    </div>

    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="workflow"><span>Workflow</span><i class="bi bi-chevron-down nav-acc-chevron"></i></button>
      <div class="nav-acc-body" id="nav-acc-workflow">
        <a href="/approvals" class="nav-item <?= ($activeModule ?? '')==='approvals'?'active':'' ?>">
          <i class="bi bi-check2-square"></i><span>Approvals</span>
          <?php
            try {
              $pendingApprovals = Database::fetchOne(
                "SELECT COUNT(*) AS c FROM approval_requests ar
                 JOIN approval_request_steps ars ON ars.request_id = ar.id AND ars.step_number = ar.current_step
                 WHERE ar.status = 'pending' AND ars.status = 'pending'
                   AND (ars.required_user_id = ? OR ars.required_role = ? OR ? = 'admin')",
                [$u['id'], $u['role'], $u['role']]
              );
            } catch (Throwable) { $pendingApprovals = ['c' => 0]; }
            if (($pendingApprovals['c'] ?? 0) > 0):
          ?><span class="nav-badge"><?= (int)$pendingApprovals['c'] ?></span><?php endif; ?>
        </a>
        <a href="/workflows" class="nav-item <?= ($activeModule ?? '')==='workflows'?'active':'' ?>"><i class="bi bi-diagram-2-fill"></i><span>Workflows</span></a>
        <a href="/my-work"   class="nav-item <?= ($activeModule ?? '')==='work'?'active':'' ?>"><i class="bi bi-briefcase-fill"></i><span>My Work</span></a>
        <a href="/tasks"     class="nav-item <?= ($activeModule ?? '')==='tasks'?'active':'' ?>"><i class="bi bi-list-task"></i><span>Tasks</span></a>
        <a href="/calendar"  class="nav-item <?= ($activeModule ?? '')==='calendar'?'active':'' ?>"><i class="bi bi-calendar3"></i><span>Calendar</span></a>
        <?php if (Auth::can('document.publish')): ?>
        <a href="/campaigns" class="nav-item <?= ($activeModule ?? '')==='campaigns'?'active':'' ?>"><i class="bi bi-megaphone-fill"></i><span>Campaigns</span></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="insights"><span>Insights</span><i class="bi bi-chevron-down nav-acc-chevron"></i></button>
      <div class="nav-acc-body" id="nav-acc-insights">
        <a href="/reports" class="nav-item <?= ($activeModule ?? '')==='reports'?'active':'' ?>"><i class="bi bi-bar-chart-line-fill"></i><span>Reports</span></a>
        <a href="/activity" class="nav-item <?= ($activeModule ?? '')==='activity'?'active':'' ?>"><i class="bi bi-activity"></i><span>Activity</span></a>
        <a href="/search"  class="nav-item <?= ($activeModule ?? '')==='search'?'active':'' ?>"><i class="bi bi-search"></i><span>Search</span></a>
        <a href="/labels"  class="nav-item <?= ($activeModule ?? '')==='labels'?'active':'' ?>"><i class="bi bi-tags-fill"></i><span>Labels</span></a>
      </div>
    </div>

    <?php if (Auth::role() === 'admin'): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="administration"><span>Administration</span><i class="bi bi-chevron-down nav-acc-chevron"></i></button>
      <div class="nav-acc-body" id="nav-acc-administration">
        <a href="/admin"             class="nav-item <?= ($activeModule ?? '')==='admin'?'active':'' ?>"><i class="bi bi-speedometer2"></i><span>Overview</span></a>
        <a href="/admin/users"       class="nav-item <?= ($activeModule ?? '')==='admin_users'?'active':'' ?>"><i class="bi bi-people-fill"></i><span>Users</span></a>
        <a href="/admin/permissions" class="nav-item <?= ($activeModule ?? '')==='admin_permissions'?'active':'' ?>"><i class="bi bi-shield-lock-fill"></i><span>Permissions</span></a>
        <a href="/admin/roles"       class="nav-item <?= ($activeModule ?? '')==='admin_roles'?'active':'' ?>"><i class="bi bi-person-badge-fill"></i><span>Roles</span></a>
        <a href="/workflows"         class="nav-item <?= ($activeModule ?? '')==='admin_workflows'?'active':'' ?>"><i class="bi bi-diagram-2-fill"></i><span>Workflows</span></a>
        <a href="/admin/branding"    class="nav-item <?= ($activeModule ?? '')==='admin_branding'?'active':'' ?>"><i class="bi bi-palette-fill"></i><span>Branding</span></a>
        <a href="/admin/settings"    class="nav-item <?= ($activeModule ?? '')==='admin_settings'?'active':'' ?>"><i class="bi bi-gear-fill"></i><span>Settings</span></a>
        <a href="/admin/tags"        class="nav-item <?= ($activeModule ?? '')==='admin_tags'?'active':'' ?>"><i class="bi bi-tags-fill"></i><span>Tags</span></a>
        <a href="/admin/shortcuts"   class="nav-item <?= ($activeModule ?? '')==='admin_shortcuts'?'active':'' ?>"><i class="bi bi-link-45deg"></i><span>Shortcut Links</span></a>
        <a href="/admin/api-keys"    class="nav-item <?= ($activeModule ?? '')==='admin_api_keys'?'active':'' ?>"><i class="bi bi-key-fill"></i><span>API Keys</span></a>
        <a href="/admin/webhooks"    class="nav-item <?= ($activeModule ?? '')==='admin_webhooks'?'active':'' ?>"><i class="bi bi-broadcast"></i><span>Webhooks</span></a>
        <a href="/admin/retention"   class="nav-item <?= ($activeModule ?? '')==='admin_retention'?'active':'' ?>"><i class="bi bi-clock-history"></i><span>Retention Rules</span></a>
        <a href="/admin/numbering"   class="nav-item <?= ($activeModule ?? '')==='admin_numbering'?'active':'' ?>"><i class="bi bi-hash"></i><span>Doc Numbering</span></a>
        <a href="/admin/security"    class="nav-item <?= ($activeModule ?? '')==='admin_security'?'active':'' ?>"><i class="bi bi-shield-check"></i><span>Security</span></a>
        <a href="/admin/logs"        class="nav-item <?= ($activeModule ?? '')==='admin_logs'?'active':'' ?>"><i class="bi bi-journal-text"></i><span>Activity Logs</span></a>
        <a href="/admin/outbox"      class="nav-item <?= ($activeModule ?? '')==='admin_outbox'?'active':'' ?>"><i class="bi bi-envelope-paper"></i><span>Mail Outbox</span></a>
        <a href="/admin/saml"        class="nav-item <?= ($activeModule ?? '')==='admin_saml'?'active':'' ?>"><i class="bi bi-shield-lock-fill"></i><span>SAML SSO</span></a>
        <a href="/admin/oidc"        class="nav-item <?= ($activeModule ?? '')==='admin_oidc'?'active':'' ?>"><i class="bi bi-key-fill"></i><span>OIDC SSO</span></a>
        <a href="/admin/scim"        class="nav-item <?= ($activeModule ?? '')==='admin_scim'?'active':'' ?>"><i class="bi bi-people-fill"></i><span>SCIM</span></a>
        <a href="/admin/sessions"    class="nav-item <?= ($activeModule ?? '')==='admin_sessions'?'active':'' ?>"><i class="bi bi-hdd-network-fill"></i><span>Sessions</span></a>
        <a href="/admin/system"      class="nav-item <?= ($activeModule ?? '')==='admin_system'?'active':'' ?>"><i class="bi bi-cpu"></i><span>System Info</span></a>
      </div>
    </div>
    <?php endif; ?>

    <?php $__shortcuts = Branding::shortcutLinks(); if ($__shortcuts): ?>
    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="shortcuts"><span>Shortcuts</span><i class="bi bi-chevron-down nav-acc-chevron"></i></button>
      <div class="nav-acc-body" id="nav-acc-shortcuts">
        <?php foreach ($__shortcuts as $sc): ?>
          <a href="<?= Security::h($sc['url']) ?>" class="nav-item"<?= str_starts_with($sc['url'], 'http') ? ' target="_blank" rel="noopener"' : '' ?>><i class="bi <?= Security::h($sc['icon']) ?>"></i><span><?= Security::h($sc['label']) ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="nav-acc">
      <button type="button" class="nav-acc-header" data-acc="account"><span>Account</span><i class="bi bi-chevron-down nav-acc-chevron"></i></button>
      <div class="nav-acc-body" id="nav-acc-account">
        <a href="/profile/notifications" class="nav-item <?= ($activeModule ?? '')==='profile_notifications'?'active':'' ?>"><i class="bi bi-bell-fill"></i><span>Notifications</span></a>
        <a href="/profile/favorites"     class="nav-item <?= ($activeModule ?? '')==='profile_favorites'?'active':'' ?>"><i class="bi bi-star-fill"></i><span>My Favorites</span></a>
        <a href="/action-items"          class="nav-item <?= ($activeModule ?? '')==='action_items'?'active':'' ?>"><i class="bi bi-check2-square"></i><span>My Action Items</span></a>
        <a href="/profile/edit"          class="nav-item <?= ($activeModule ?? '')==='profile_edit'?'active':'' ?>"><i class="bi bi-person-fill-gear"></i><span>Edit Profile</span></a>
        <a href="/mfa/setup"             class="nav-item <?= ($activeModule ?? '')==='profile_mfa'?'active':'' ?>"><i class="bi bi-shield-lock-fill"></i><span>Two-Factor Auth</span></a>
        <a href="/profile/tokens"        class="nav-item <?= ($activeModule ?? '')==='profile_tokens'?'active':'' ?>"><i class="bi bi-key-fill"></i><span>Access Tokens</span></a>
        <a href="/profile/sessions"      class="nav-item <?= ($activeModule ?? '')==='profile_sessions'?'active':'' ?>"><i class="bi bi-laptop"></i><span>Sessions</span></a>
        <a href="/docs"                  class="nav-item <?= ($activeModule ?? '')==='docs'?'active':'' ?>"><i class="bi bi-book-fill"></i><span>Help &amp; Docs</span></a>
      </div>
    </div>
  </nav>

  <div class="sidebar-footer">
    <?php $__sbFooter = Branding::sidebarFooter(); if ($__sbFooter !== ''): ?>
      <div style="font-size:.72rem;color:var(--sidebar-text);opacity:.8;padding:0 4px 8px;text-align:center"><?= Security::h($__sbFooter) ?></div>
    <?php endif; ?>
    <div class="user-card">
      <div class="user-avatar"><?= strtoupper(substr((string)($u['name'] ?? '?'), 0, 1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= Security::h($u['name'] ?? '') ?></div>
        <div class="user-role"><?= Security::h(Auth::roleLabel($u['role'] ?? 'viewer')) ?></div>
      </div>
      <?php $__logoutAction = (!empty($_SESSION['saml_name_id']) && Saml::sloEnabled()) ? '/saml/logout' : '/logout'; ?>
      <form method="POST" action="<?= $__logoutAction ?>" style="display:inline;margin:0;padding:0">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <button type="submit" class="btn-logout" title="Logout" style="background:none;border:none;cursor:pointer;padding:0"><i class="bi bi-box-arrow-right"></i></button>
      </form>
    </div>
  </div>
</aside>
<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<div class="main-content" id="mainContent">
  <header class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" aria-label="Open menu" type="button"><i class="bi bi-list"></i></button>
      <div class="breadcrumb-area">
        <?php if (!empty($breadcrumbs)): ?>
          <?php foreach ($breadcrumbs as $i => [$label, $url]): ?>
            <?php if ($url): ?><a href="<?= Security::h($url) ?>" class="breadcrumb-link"><?= Security::h($label) ?></a>
            <?php else: ?><span class="breadcrumb-current"><?= Security::h($label) ?></span><?php endif; ?>
            <?php if ($i < count($breadcrumbs) - 1): ?><span class="breadcrumb-sep"><i class="bi bi-chevron-right"></i></span><?php endif; ?>
          <?php endforeach; ?>
        <?php else: ?><span class="breadcrumb-current"><?= Security::h($pageTitle ?? '') ?></span><?php endif; ?>
      </div>
    </div>
    <div class="topbar-right">
      <form method="GET" action="/search" class="topbar-search" style="margin:0">
        <i class="bi bi-search"></i>
        <input type="search" name="q" placeholder="Search the library…" aria-label="Search" value="<?= Security::h($_GET['q'] ?? '') ?>">
      </form>
      <?php
        $__create = [];
        if (Auth::can('page.create'))     $__create[] = ['/pages/templates',   'bi-file-richtext',        'Page'];
        if (Auth::can('document.create')) $__create[] = ['/documents/create',   'bi-file-earmark-text',    'Document'];
        if (Auth::can('page.create'))     $__create[] = ['/blog/create',        'bi-newspaper',            'Blog post'];
        if (Auth::can('space.create'))    $__create[] = ['/spaces/create',      'bi-collection',           'Space'];
      ?>
      <?php if ($__create): ?>
      <div class="topbar-create" style="position:relative">
        <button type="button" class="btn btn-sm btn-primary" data-menu-toggle="createMenu"><i class="bi bi-plus-lg"></i> Create</button>
        <div id="createMenu" class="topbar-menu" hidden style="position:absolute;right:0;top:calc(100% + 6px);z-index:60;background:var(--card-bg);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.22);min-width:190px;padding:6px">
          <?php foreach ($__create as [$href, $icon, $label]): ?>
            <a href="<?= $href ?>" class="topbar-menu-item" style="display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:7px;text-decoration:none;color:var(--text)"><i class="bi <?= $icon ?>" style="color:var(--primary)"></i> <?= $label ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <button id="themeToggle" class="theme-toggle" title="Toggle dark mode" type="button"><i class="bi bi-moon-fill" id="themeIcon"></i></button>
      <?php
      try { $unreadAlerts = Database::fetchOne("SELECT COUNT(*) AS c FROM alerts WHERE user_id = ? AND is_read = FALSE", [Auth::id()])['c'] ?? 0; }
      catch (Throwable) { $unreadAlerts = 0; }
      ?>
      <a href="/profile/notifications" class="alert-bell" id="alertBell" style="text-decoration:none">
        <i class="bi bi-bell<?= $unreadAlerts > 0 ? '-fill' : '' ?>"></i>
        <?php if ($unreadAlerts > 0): ?><span class="alert-badge"><?= min((int)$unreadAlerts, 99) ?></span><?php endif; ?>
      </a>
      <div class="topbar-user">
        <div class="user-avatar sm"><?= strtoupper(substr((string)($u['name'] ?? '?'), 0, 1)) ?></div>
        <span><?= Security::h(explode(' ', (string)($u['name'] ?? ''))[0]) ?></span>
      </div>
    </div>
  </header>

  <main class="page-content">
    <?php foreach (['success' => 'check-circle-fill', 'error' => 'exclamation-circle-fill', 'warning' => 'exclamation-triangle-fill'] as $__t => $__icon): ?>
      <?php if (!empty($_SESSION['flash_' . $__t])): ?>
        <div class="alert-box <?= $__t === 'error' ? 'error' : $__t ?>"><i class="bi bi-<?= $__icon ?>"></i> <?= Security::h($_SESSION['flash_' . $__t]) ?></div>
        <?php unset($_SESSION['flash_' . $__t]); ?>
      <?php endif; ?>
    <?php endforeach; ?>
    <?= $content ?? '' ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmRONQ7+/O31fY0B+Mzgj+qVXq0" crossorigin="anonymous" nonce="<?= Security::nonce() ?>"></script>
<script src="/public/vendor/chart.js/chart.umd.js" nonce="<?= Security::nonce() ?>"></script>
<script src="/public/js/app.js?v=12" nonce="<?= Security::nonce() ?>"></script>
</body>
</html>
