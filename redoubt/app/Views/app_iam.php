<?php
/**
 * Two-pane IAM console. Provided by IamController::index().
 * In scope: $user, $NONCE, $programs (id=>label), $selected (int),
 *           $catalog (modules), $csrf (string).
 */
use Redoubt\Support\Security;

$title = 'Access & Security';
$navActive = 'iam';
$breadcrumbs = ['Home' => '/app', 'Access & Security' => null];
$appScript = '/assets/iam.js';

$bootstrap = [
    'programId' => $selected,
    'csrf'      => $csrf,
    'catalog'   => $catalog,
    'total'     => \Redoubt\Support\PermissionCatalog::total(),
    'endpoints' => [
        'users' => '/app/admin/iam/users',
        'user'  => '/app/admin/iam/user',
        'save'  => '/app/admin/iam/save',
    ],
];
require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <h1 class="page-title">Access &amp; Security</h1>
  <form method="get" action="/app/admin/iam" id="programForm">
    <label style="display:inline;margin:0 8px 0 0">Program</label>
    <select name="program_id" id="programSelect" style="width:auto;display:inline-block">
      <?php foreach ($programs as $pid => $label): ?>
        <option value="<?= (int) $pid ?>"<?= $pid === $selected ? ' selected' : '' ?>><?= Security::h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="iam">
  <aside class="iam-users">
    <input class="iam-search" id="userSearch" type="search" placeholder="Search users…" autocomplete="off">
    <div id="userList"><div class="empty-state-sm">Loading users…</div></div>
  </aside>

  <section class="iam-editor">
    <div class="iam-toolbar">
      <div>
        <strong id="editorTitle">Select a user</strong>
        <span id="editorSub" class="perm-key"></span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-sm" id="expandAll" type="button">Expand all</button>
        <button class="btn btn-sm" id="collapseAll" type="button">Collapse all</button>
        <button class="btn btn-primary btn-sm" id="saveBtn" type="button" disabled>Save changes</button>
      </div>
    </div>
    <div id="editor"><div class="empty-state-sm">Choose a user on the left to view and edit permissions.</div></div>
    <div class="legend">
      <span><i class="dot dot-role"></i> Role default</span>
      <span><i class="dot dot-grant"></i> Explicit grant</span>
      <span><i class="dot dot-none"></i> Denied / none</span>
      <span id="permCount"></span>
    </div>
  </section>
</div>

<script nonce="<?= Security::h($NONCE) ?>">
  window.REDOUBT_IAM = <?= Security::jsonForScript($bootstrap) ?>;
</script>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
