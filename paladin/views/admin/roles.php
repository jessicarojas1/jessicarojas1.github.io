<?php
$pageTitle    = 'Roles';
$activeModule = 'admin_roles';
$breadcrumbs  = [['Administration', '/admin'], ['Roles', null]];
ob_start();
$builtins = Auth::roleKeys();
?>
<div class="page-header">
  <div><h1 class="page-title">Roles</h1><p class="page-subtitle">Built-in roles plus your own custom permission sets</p></div>
  <div class="page-actions"><a href="/admin/roles/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Role</a></div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-person-badge-fill"></i> Custom Roles</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0">
      <thead><tr><th>Name</th><th>Key</th><th>Permissions</th><th>Users</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($custom as $r): ?>
        <tr>
          <td><a href="/admin/roles/<?= (int)$r['id'] ?>/edit" class="table-link"><?= Security::h($r['name']) ?></a><?php if ($r['description']): ?><div class="form-hint"><?= Security::h($r['description']) ?></div><?php endif; ?></td>
          <td><span class="chip"><?= Security::h($r['role_key']) ?></span></td>
          <td><span class="badge badge-blue"><?= (int)$r['perm_count'] ?> granted</span></td>
          <td><span class="badge badge-gray"><?= (int)$r['user_count'] ?></span></td>
          <td style="text-align:right;white-space:nowrap">
            <a href="/admin/roles/<?= (int)$r['id'] ?>/edit" class="btn btn-sm btn-ghost"><i class="bi bi-pencil"></i></a>
            <form method="POST" action="/admin/roles/<?= (int)$r['id'] ?>/delete" style="display:inline;margin:0" data-confirm="Delete this role? (Only allowed if no users are assigned.)"><?= Security::csrfField() ?><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$custom): ?>
        <tr><td colspan="5" class="empty-row"><div class="empty-state-sm"><i class="bi bi-person-badge"></i><p>No custom roles yet. Build one to tailor exactly what a group can do.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-shield-fill-check"></i> Built-in Roles</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table" style="margin:0">
      <thead><tr><th>Name</th><th>Key</th><th>Default permissions</th></tr></thead>
      <tbody>
      <?php foreach ($builtins as $k): $cnt = $k === 'admin' ? 'all' : count(Auth::roleDefaults($k)); ?>
        <tr><td><?= Security::h(Auth::roleLabel($k)) ?></td><td><span class="chip"><?= Security::h($k) ?></span></td><td><span class="badge badge-gray"><?= $k==='admin' ? 'Full access' : ((int)$cnt . ' granted') ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
