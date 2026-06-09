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
      <table class="table table-hover" style="margin:0">
        <thead><tr><th>Tag</th><th>Colour</th><th style="text-align:right">Usage</th></tr></thead>
        <tbody>
        <?php foreach ($tags as $t): ?>
          <tr>
            <td><span class="chip" style="border-left:3px solid <?= Security::h($t['color']) ?>"><?= Security::h($t['name']) ?></span></td>
            <td><span style="display:inline-block;width:18px;height:18px;border-radius:4px;vertical-align:middle;background:<?= Security::h($t['color']) ?>"></span> <span class="form-hint"><?= Security::h($t['color']) ?></span></td>
            <td style="text-align:right"><span class="badge badge-gray"><?= (int)$t['cnt'] ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$tags): ?>
          <tr><td colspan="3" class="empty-row"><div class="empty-state-sm"><i class="bi bi-tags"></i><p>No tags yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
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
</div>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
