<?php
$pageTitle    = 'Tags';
$activeModule = 'admin_tags';
$breadcrumbs  = [['Administration', '/admin'], ['Tags', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Tags</h1><p class="page-subtitle">Shared taxonomy applied across content</p></div>
</div>

<div class="iam" style="grid-template-columns:1fr 320px">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-tags-fill"></i> All Tags</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table" style="margin:0">
        <thead><tr><th>Name</th><th style="width:90px">Colour</th><th style="width:70px;text-align:right">Usage</th><th style="width:150px"></th></tr></thead>
        <tbody>
        <?php foreach ($tags as $t): ?>
          <tr>
            <td>
              <form method="POST" action="/admin/tags/<?= (int)$t['id'] ?>/update" id="tagform-<?= (int)$t['id'] ?>" class="form-row" style="align-items:center;gap:8px;margin:0">
                <?= Security::csrfField() ?>
                <input type="text" name="name" class="form-control" value="<?= Security::h($t['name']) ?>" required maxlength="80" style="max-width:220px">
            </td>
            <td><input type="color" name="color" class="form-control" value="<?= Security::h($t['color']) ?>" style="width:48px;padding:3px">
              </form>
            </td>
            <td style="text-align:right"><span class="badge badge-gray"><?= (int)$t['cnt'] ?></span></td>
            <td style="text-align:right;white-space:nowrap">
              <button type="submit" form="tagform-<?= (int)$t['id'] ?>" class="btn btn-sm btn-ghost" title="Save"><i class="bi bi-check-lg"></i></button>
              <form method="POST" action="/admin/tags/<?= (int)$t['id'] ?>/delete" style="display:inline;margin:0" data-confirm="Delete this tag? It will be removed from all content."><?= Security::csrfField() ?><button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tags): ?>
          <tr><td colspan="4" class="empty-row"><div class="empty-state-sm"><i class="bi bi-tags"></i><p>No tags yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-bottom:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-plus-lg"></i> New Tag</span></div></div>
    <div class="card-body">
      <form method="POST" action="/admin/tags">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label" for="tag_name">Name</label><input type="text" id="tag_name" name="name" class="form-control" required maxlength="80"></div>
        <div class="form-group"><label class="form-label" for="tag_color">Colour</label><input type="color" id="tag_color" name="color" class="form-control" style="max-width:64px;padding:4px" value="#64748b"></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary btn-full"><i class="bi bi-plus-lg"></i> Create Tag</button></div>
      </form>
    </div>
  </div>

  <?php if (count($tags) > 1): ?>
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-arrow-left-right"></i> Merge Tags</span></div></div>
    <div class="card-body">
      <form method="POST" action="/admin/tags/merge">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label" for="merge_source">Merge this tag…</label>
          <select id="merge_source" name="source_id" class="form-select" required>
            <option value="">Choose…</option>
            <?php foreach ($tags as $t): ?><option value="<?= (int)$t['id'] ?>"><?= Security::h($t['name']) ?> (<?= (int)$t['cnt'] ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label" for="merge_target">…into this tag</label>
          <select id="merge_target" name="target_id" class="form-select" required>
            <option value="">Choose…</option>
            <?php foreach ($tags as $t): ?><option value="<?= (int)$t['id'] ?>"><?= Security::h($t['name']) ?> (<?= (int)$t['cnt'] ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <p class="form-hint">All content tagged with the source tag is re-tagged to the target, then the source tag is deleted.</p>
        <div class="form-actions"><button type="submit" class="btn btn-ghost btn-full" data-confirm-click="Merge the source tag into the target and delete the source?"><i class="bi bi-arrow-left-right"></i> Merge Tags</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
