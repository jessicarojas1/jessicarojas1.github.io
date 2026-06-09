<?php
$editing      = !empty($post);
$pageTitle    = $editing ? 'Edit Blog Post' : 'New Blog Post';
$activeModule = 'blog';
$breadcrumbs  = [['Blog', '/blog'], [$editing ? 'Edit' : 'New', null]];
$action       = $editing ? '/blog/' . (int)$post['id'] . '/edit' : '/blog/create';
$preSpace     = (int)($_GET['space'] ?? ($post['space_id'] ?? 0));
ob_start();
?>
<div class="page-header"><div><h1 class="page-title"><?= $editing ? 'Edit Blog Post' : 'Write Blog Post' ?></h1></div></div>

<div class="card"><div class="card-body">
  <form method="POST" action="<?= $action ?>">
    <?= Security::csrfField() ?>
    <div class="form-row">
      <div class="form-group" style="flex:2"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" required value="<?= Security::h($post['title'] ?? '') ?>" placeholder="Post title"></div>
      <div class="form-group" style="flex:1"><label class="form-label">Space *</label>
        <select name="space_id" class="form-select" required>
          <option value="">Select…</option>
          <?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $preSpace==$s['id']?'selected':'' ?>><?= Security::h($s['space_key'] . ' — ' . $s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:0 0 150px"><label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="draft" <?= (($post['status'] ?? 'draft')==='draft')?'selected':'' ?>>Draft</option>
          <?php if (Auth::can('page.publish')): ?><option value="published" <?= (($post['status'] ?? '')==='published')?'selected':'' ?>>Published</option><?php endif; ?>
        </select>
      </div>
    </div>
    <div class="form-group"><label class="form-label">Content</label>
      <?php $wId='blogbody'; $wName='body'; $wValue=$post['body'] ?? ''; require PALADIN_ROOT . '/views/partials/wysiwyg.php'; ?>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> <?= $editing ? 'Save' : 'Create Post' ?></button>
      <a href="<?= $editing ? '/blog/' . (int)$post['id'] : '/blog' ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
