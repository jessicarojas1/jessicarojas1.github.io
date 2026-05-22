<?php
$pageTitle    = 'Permissions';
$activeModule = 'admin_permissions';
$breadcrumbs  = [['Admin','/admin'],['Permissions',null]];
ob_start();
?>

<?php if (!empty($_GET['saved'])): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Permissions updated for user #<?= (int)$_GET['saved'] ?>.</div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <div class="alert-box danger"><i class="bi bi-exclamation-triangle-fill"></i> Invalid user or action.</div>
<?php endif; ?>

<div class="page-header">
  <h1 class="page-title">Permission Management</h1>
  <span class="badge badge-blue" style="font-size:13px">Admin Only</span>
</div>

<!-- Legend -->
<div class="card" style="margin-bottom:20px">
  <div class="card-body" style="display:flex;gap:32px;flex-wrap:wrap;align-items:center">
    <div style="display:flex;align-items:center;gap:8px">
      <span class="perm-dot perm-dot-role"></span>
      <span class="text-sm text-muted">Granted by role (default)</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <span class="perm-dot perm-dot-explicit"></span>
      <span class="text-sm text-muted">Explicitly granted</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <span class="perm-dot perm-dot-none"></span>
      <span class="text-sm text-muted">Not granted</span>
    </div>
    <div class="text-sm text-muted" style="margin-left:auto"><i class="bi bi-info-circle"></i> Admin users always have full access and are not listed here.</div>
  </div>
</div>

<?php if (!$users): ?>
  <div class="card"><div class="card-body"><div class="empty-state-sm"><i class="bi bi-people"></i><p>No non-admin users found.</p></div></div></div>
<?php else: ?>

<!-- Permission Matrix -->
<div class="card">
  <div class="card-body p0">
    <div class="perm-table-wrap">
      <table class="table perm-table">
        <thead>
          <tr>
            <th class="perm-user-col" rowspan="2">User</th>
            <?php foreach ($modules as $mod): ?>
              <th colspan="3" class="perm-module-header perm-module-<?= $mod ?>">
                <i class="bi bi-<?= ['compliance'=>'shield-check','audit'=>'clipboard2-check','policy'=>'file-text','risk'=>'exclamation-diamond'][$mod] ?>"></i>
                <?= ucfirst($mod) ?>
              </th>
            <?php endforeach; ?>
            <th class="perm-action-col" rowspan="2">Save</th>
          </tr>
          <tr>
            <?php foreach ($modules as $mod): ?>
              <?php foreach ($permTypes as $pType): ?>
                <th class="perm-sub-header" title="<?= ucfirst($mod) ?> — <?= ucfirst($pType) ?>">
                  <button class="perm-col-all btn-unstyled" data-module="<?= $mod ?>" data-perm="<?= $pType ?>" title="Toggle all <?= $pType ?> in <?= $mod ?>">
                    <?= strtoupper($pType[0]) ?>
                  </button>
                </th>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
            $uid = $u['id'];
            $userGrants = $grants[$uid] ?? [];
            $roleDef = $roleDefaults[$u['role']] ?? [];
          ?>
          <tr class="perm-row" id="perm-row-<?= $uid ?>">
            <td class="perm-user-cell">
              <div class="user-avatar-sm"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
              <div>
                <div class="fw-600 text-sm"><?= Security::h($u['name']) ?></div>
                <div class="text-muted text-xs"><?= Security::h($u['email']) ?></div>
                <span class="role-badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span>
              </div>
            </td>
            <?php foreach ($modules as $mod): ?>
              <?php foreach ($permTypes as $pType): ?>
                <?php
                  $roleHas     = in_array($pType, $roleDef[$mod] ?? []);
                  $explicitHas = isset($userGrants[$mod][$pType]);
                  $checked     = $roleHas || $explicitHas;
                ?>
                <td class="perm-cell <?= $roleHas ? 'perm-cell-role' : ($explicitHas ? 'perm-cell-explicit' : '') ?>">
                  <label class="perm-label" title="<?= ucfirst($mod) ?> — <?= ucfirst($pType) ?>">
                    <input
                      type="checkbox"
                      class="perm-checkbox"
                      data-user="<?= $uid ?>"
                      data-module="<?= $mod ?>"
                      data-perm="<?= $pType ?>"
                      data-role-default="<?= $roleHas ? '1' : '0' ?>"
                      <?= $checked ? 'checked' : '' ?>
                    >
                    <?php if ($roleHas && !$explicitHas): ?>
                      <span class="perm-indicator perm-indicator-role" title="From role"></span>
                    <?php elseif ($explicitHas): ?>
                      <span class="perm-indicator perm-indicator-explicit" title="Explicit grant"></span>
                    <?php endif; ?>
                  </label>
                </td>
              <?php endforeach; ?>
            <?php endforeach; ?>
            <td class="perm-action-cell">
              <form method="POST" action="/admin/permissions/<?= $uid ?>/update" class="perm-form" id="perm-form-<?= $uid ?>">
                <?= Security::csrfField() ?>
                <!-- permissions[] populated via JS on submit -->
                <button type="submit" class="btn btn-primary btn-sm perm-save-btn" title="Save permissions for <?= Security::h($u['name']) ?>">
                  <i class="bi bi-floppy"></i> Save
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Module descriptions -->
<div class="two-col-layout" style="margin-top:20px">
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle"></i> Permission Levels</h3></div>
    <div class="card-body">
      <div class="perm-level-item">
        <span class="badge badge-blue">Read</span>
        <div class="text-sm text-muted">View lists, records, and details. No ability to create or modify.</div>
      </div>
      <div class="perm-level-item">
        <span class="badge badge-green">Write</span>
        <div class="text-sm text-muted">Create new records within the module.</div>
      </div>
      <div class="perm-level-item">
        <span class="badge badge-orange">Edit</span>
        <div class="text-sm text-muted">Modify, update, approve, or delete existing records.</div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-people"></i> Role Defaults</h3></div>
    <div class="card-body">
      <?php
      $roleLabels = ['manager' => 'Manager', 'auditor' => 'Auditor', 'analyst' => 'Analyst', 'viewer' => 'Viewer'];
      foreach ($roleLabels as $role => $label):
        $roleDef = $roleDefaults[$role];
        $permsFlat = [];
        foreach ($roleDef as $mod => $pts) {
          foreach ($pts as $pt) $permsFlat[] = ucfirst($mod) . '.' . strtoupper($pt[0]);
        }
      ?>
        <div class="perm-level-item">
          <span class="role-badge role-<?= $role ?>"><?= $label ?></span>
          <div class="text-sm text-muted"><?= implode(', ', $permsFlat) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php endif; ?>

<script>
// Collect checked permissions and inject hidden inputs on form submit
document.querySelectorAll('.perm-form').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    const uid = form.id.replace('perm-form-', '');
    // Remove any existing permission inputs
    form.querySelectorAll('input[name="permissions[]"]').forEach(function(i) { i.remove(); });

    document.querySelectorAll('.perm-checkbox[data-user="' + uid + '"]').forEach(function(cb) {
      if (cb.checked) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'permissions[]';
        input.value = cb.dataset.module + '.' + cb.dataset.perm;
        form.appendChild(input);
      }
    });
  });
});

// Visual feedback: mark cell dirty on change
document.querySelectorAll('.perm-checkbox').forEach(function(cb) {
  cb.addEventListener('change', function() {
    const cell = cb.closest('td');
    cell.classList.remove('perm-cell-role', 'perm-cell-explicit');
    if (cb.checked) {
      cell.classList.add('perm-cell-explicit');
    }
    // Mark row as modified
    const row = cb.closest('tr');
    if (row) row.classList.add('perm-row-modified');
  });
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
