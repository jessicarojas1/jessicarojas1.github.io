<?php
$pageTitle    = 'Shortcut Links';
$activeModule = 'admin_shortcuts';
$breadcrumbs  = [['Administration', '/admin'], ['Shortcut Links', null]];
ob_start();
$icons = ['bi-link-45deg','bi-box-arrow-up-right','bi-book','bi-life-preserver','bi-people','bi-graph-up','bi-shield-check','bi-gear','bi-building','bi-globe'];
?>
<div class="page-header"><div><h1 class="page-title">Shortcut Links</h1><p class="page-subtitle">Quick links shown in the sidebar for everyone (intranet, help desk, policies…)</p></div></div>

<div class="iam" style="grid-template-columns:1fr 340px">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-link-45deg"></i> Links</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0"><thead><tr><th>Label</th><th>URL</th><th style="width:60px"></th></tr></thead><tbody>
        <?php foreach ($links as $l): ?>
          <tr>
            <td><i class="bi <?= Security::h($l['icon']) ?>"></i> <?= Security::h($l['label']) ?></td>
            <td class="form-hint" style="word-break:break-all"><?= Security::h($l['url']) ?></td>
            <td style="text-align:right"><form method="POST" action="/admin/shortcuts/<?= (int)$l['id'] ?>/delete" style="margin:0" data-confirm="Remove this shortcut?"><?= Security::csrfField() ?><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i></button></form></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$links): ?><tr><td colspan="3" class="empty-row"><div class="empty-state-sm"><i class="bi bi-link-45deg"></i><p>No shortcut links yet.</p></div></td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-plus-lg"></i> New Shortcut</span></div></div>
    <div class="card-body">
      <form method="POST" action="/admin/shortcuts">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label">Label</label><input type="text" name="label" class="form-control" maxlength="80" required></div>
        <div class="form-group"><label class="form-label">URL</label><input type="text" name="url" class="form-control" placeholder="https://… or /path" required></div>
        <div class="form-group"><label class="form-label">Icon</label><select name="icon" class="form-select"><?php foreach ($icons as $ic): ?><option value="<?= $ic ?>"><?= $ic ?></option><?php endforeach; ?></select></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary btn-full"><i class="bi bi-plus-lg"></i> Add Shortcut</button></div>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
