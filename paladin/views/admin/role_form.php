<?php
$editing      = !empty($role);
$pageTitle    = $editing ? 'Edit Role' : 'New Role';
$activeModule = 'admin_roles';
$breadcrumbs  = [['Administration', '/admin'], ['Roles', '/admin/roles'], [$editing ? $role['name'] : 'New', null]];
$action       = $editing ? '/admin/roles/' . (int)$role['id'] . '/edit' : '/admin/roles/create';
ob_start();
$totalActions = 0; foreach ($catalog as $a) $totalActions += count($a);
?>
<div class="page-header">
  <div><h1 class="page-title"><?= $editing ? 'Edit Role' : 'Create Role' ?></h1><p class="page-subtitle">Choose exactly which module actions this role grants</p></div>
  <div class="page-actions"><a href="/admin/roles" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a></div>
</div>

<form method="POST" action="<?= $action ?>" id="roleForm">
  <?= Security::csrfField() ?>
  <div class="card" style="margin-bottom:18px"><div class="card-body">
    <div class="form-row">
      <div class="form-group" style="flex:1"><label class="form-label">Role name *</label><input type="text" name="name" class="form-control" required value="<?= Security::h($role['name'] ?? '') ?>" placeholder="e.g. Quality Auditor"></div>
      <?php if ($editing): ?><div class="form-group" style="flex:0 0 220px"><label class="form-label">Key</label><input type="text" class="form-control" value="<?= Security::h($role['role_key']) ?>" disabled></div><?php endif; ?>
    </div>
    <div class="form-group"><label class="form-label">Description</label><input type="text" name="description" class="form-control" value="<?= Security::h($role['description'] ?? '') ?>" placeholder="What is this role for?"></div>
  </div></div>

  <div class="card">
    <div class="card-header">
      <div class="card-header-left"><span class="card-title"><i class="bi bi-sliders"></i> Permissions</span></div>
      <div style="display:flex;align-items:center;gap:10px">
        <span class="form-hint" id="permCount">0 / <?= $totalActions ?> granted</span>
        <button type="button" class="btn btn-sm btn-ghost" id="grantAll">Grant all</button>
        <button type="button" class="btn btn-sm btn-ghost" id="clearAll">Clear all</button>
      </div>
    </div>
    <div class="card-body">
      <?php foreach ($catalog as $module => $actions): [$icon,$color] = Auth::moduleMeta($module); ?>
      <div class="perm-module">
        <div class="perm-module-head">
          <span class="lib-card-icon" style="width:30px;height:30px;font-size:.95rem;background:<?= Security::h($color) ?>"><i class="bi <?= Security::h($icon) ?>"></i></span>
          <span style="text-transform:capitalize"><?= Security::h($module) ?></span>
          <span class="badge badge-gray mod-count" data-module="<?= Security::h($module) ?>" style="margin-left:8px">0/<?= count($actions) ?></span>
          <button type="button" class="btn btn-sm btn-ghost mod-grant" data-module="<?= Security::h($module) ?>" style="margin-left:auto">Grant</button>
          <button type="button" class="btn btn-sm btn-ghost mod-clear" data-module="<?= Security::h($module) ?>">Clear</button>
        </div>
        <div class="perm-actions">
          <?php foreach ($actions as $a): $perm = $module . '.' . $a; ?>
          <label class="perm-chk"><input type="checkbox" class="perm-box" data-module="<?= Security::h($module) ?>" name="perms[]" value="<?= Security::h($perm) ?>" <?= in_array($perm, $granted, true) ? 'checked' : '' ?>> <?= Security::h($a) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="form-actions" style="margin-top:18px">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> <?= $editing ? 'Save Role' : 'Create Role' ?></button>
    <a href="/admin/roles" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
(function(){
  var form = document.getElementById('roleForm');
  var boxes = function(){ return Array.prototype.slice.call(form.querySelectorAll('.perm-box')); };
  function refresh(){
    var all = boxes();
    var granted = all.filter(function(b){ return b.checked; }).length;
    document.getElementById('permCount').textContent = granted + ' / ' + all.length + ' granted';
    form.querySelectorAll('.mod-count').forEach(function(badge){
      var m = badge.getAttribute('data-module');
      var mods = all.filter(function(b){ return b.getAttribute('data-module') === m; });
      var on = mods.filter(function(b){ return b.checked; }).length;
      badge.textContent = on + '/' + mods.length;
    });
  }
  form.addEventListener('change', function(e){ if (e.target.classList.contains('perm-box')) refresh(); });
  document.getElementById('grantAll').addEventListener('click', function(){ boxes().forEach(function(b){ b.checked = true; }); refresh(); });
  document.getElementById('clearAll').addEventListener('click', function(){ boxes().forEach(function(b){ b.checked = false; }); refresh(); });
  form.querySelectorAll('.mod-grant').forEach(function(btn){ btn.addEventListener('click', function(){ var m=btn.getAttribute('data-module'); boxes().forEach(function(b){ if(b.getAttribute('data-module')===m) b.checked=true; }); refresh(); }); });
  form.querySelectorAll('.mod-clear').forEach(function(btn){ btn.addEventListener('click', function(){ var m=btn.getAttribute('data-module'); boxes().forEach(function(b){ if(b.getAttribute('data-module')===m) b.checked=false; }); refresh(); }); });
  refresh();
})();
</script>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
