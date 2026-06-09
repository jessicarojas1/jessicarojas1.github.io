<?php
$editing      = !empty($space);
$pageTitle    = $editing ? 'Edit Space' : 'New Space';
$activeModule = 'spaces';
$breadcrumbs  = [['Spaces', '/spaces'], [$editing ? 'Edit' : 'New', null]];
$action       = $editing ? '/spaces/' . (int)$space['id'] . '/edit' : '/spaces/create';
ob_start();
$icons = ['bi-folder2-open','bi-people','bi-building','bi-diagram-3','bi-kanban','bi-patch-check','bi-gear-wide-connected','bi-shield-lock','bi-journal-richtext','bi-briefcase'];
?>
<div class="page-header"><div><h1 class="page-title"><?= $editing ? 'Edit Space' : 'Create Space' ?></h1></div></div>

<div class="card form-page">
  <div class="card-body">
    <form method="POST" action="<?= $action ?>">
      <?= Security::csrfField() ?>
      <div class="form-row">
        <div class="form-group" style="flex:0 0 160px"><label class="form-label">Space Key *</label>
          <input type="text" name="space_key" class="form-control" maxlength="20" required value="<?= Security::h($space['space_key'] ?? '') ?>" <?= $editing ? 'readonly' : '' ?> placeholder="QMS">
          <div class="form-hint">Short uppercase identifier</div>
        </div>
        <div class="form-group" style="flex:1"><label class="form-label">Name *</label>
          <input type="text" name="name" class="form-control" required value="<?= Security::h($space['name'] ?? '') ?>" placeholder="Quality Management System">
        </div>
      </div>
      <div class="form-group"><label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3"><?= Security::h($space['description'] ?? '') ?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Type</label>
          <select name="type" class="form-select">
            <?php foreach (View::spaceTypes() as $t): ?><option value="<?= $t ?>" <?= ($space['type'] ?? 'team')===$t?'selected':'' ?>><?= ucfirst($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Icon</label>
          <select name="icon" class="form-select">
            <?php foreach ($icons as $ic): ?><option value="<?= $ic ?>" <?= ($space['icon'] ?? 'bi-folder2-open')===$ic?'selected':'' ?>><?= $ic ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex:0 0 140px"><label class="form-label">Color</label>
          <input type="color" name="color" class="form-control" value="<?= Security::h($space['color'] ?? '#2563eb') ?>" style="height:42px;padding:4px">
        </div>
      </div>
      <div class="form-group">
        <label class="perm-chk"><input type="checkbox" name="is_private" value="1" <?= !empty($space['is_private'])?'checked':'' ?>> Private space (restricted to members)</label>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> <?= $editing ? 'Save Changes' : 'Create Space' ?></button>
        <a href="<?= $editing ? '/spaces/' . (int)$space['id'] : '/spaces' ?>" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
