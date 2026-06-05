<?php if (!defined('AEGIS_ROOT')) { http_response_code(403); exit; }
$breadcrumbs = [['Admin', '/admin'], ['Tags', null]];
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success"><?= Security::h($_SESSION['flash_success']) ?><?php unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error"><?= Security::h($_SESSION['flash_error']) ?><?php unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Tag Management</h1>
    <p class="page-subtitle">Create and manage tags for risks, vendors, assets, and more</p>
  </div>
</div>

<div class="row" style="gap:1.5rem;display:flex;align-items:flex-start">

  <!-- Create tag form -->
  <div style="width:320px;flex-shrink:0">
    <div class="card">
      <div class="card-header"><h3 class="card-title">New Tag</h3></div>
      <div class="card-body">
        <form method="POST" action="/admin/tags/create">
          <?= Security::csrfField() ?>
          <div class="form-group" style="margin-bottom:1rem">
            <label class="form-label">Name <span style="color:var(--danger)">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="50" placeholder="e.g. High Priority">
          </div>
          <div class="form-group" style="margin-bottom:1.5rem">
            <label class="form-label">Color</label>
            <div style="display:flex;gap:.75rem;align-items:center">
              <input type="color" name="color" value="var(--primary)" style="width:48px;height:38px;padding:2px;border:1px solid var(--border-color);border-radius:6px;cursor:pointer">
              <span style="font-size:.85rem;color:var(--text-muted)">Pick a badge color</span>
            </div>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%">Create Tag</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Tags table -->
  <div style="flex:1;min-width:0">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">All Tags <span class="badge-count"><?= count($tags) ?></span></h3>
      </div>
      <?php if (empty($tags)): ?>
        <div class="empty-state-sm"><i class="bi bi-tags-fill"></i><p>No tags yet. Create a tag to get started.</p></div>
      <?php else: ?>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>Tag</th>
                <th>Color</th>
                <th>Usage</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tags as $tag): ?>
                <tr>
                  <td>
                    <span style="display:inline-flex;align-items:center;gap:.4rem;background:<?= Security::h($tag['color']) ?>22;color:<?= Security::h($tag['color']) ?>;padding:.25rem .65rem;border-radius:99px;font-size:.8rem;font-weight:600;border:1px solid <?= Security::h($tag['color']) ?>55">
                      <span style="width:8px;height:8px;border-radius:50%;background:<?= Security::h($tag['color']) ?>"></span>
                      <?= Security::h($tag['name']) ?>
                    </span>
                  </td>
                  <td style="font-family:monospace;font-size:.85rem"><?= Security::h($tag['color']) ?></td>
                  <td><?= (int)$tag['usage_count'] ?> entit<?= $tag['usage_count'] == 1 ? 'y' : 'ies' ?></td>
                  <td>
                    <?php if ((int)$tag['usage_count'] === 0): ?>
                      <form method="POST" action="/admin/tags/<?= (int)$tag['id'] ?>/delete" style="display:inline" data-confirm="Delete this tag?">
                        <?= Security::csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                      </form>
                    <?php else: ?>
                      <span style="font-size:.8rem;color:var(--text-muted)">In use</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script nonce="<?= Security::nonce() ?>">
document.querySelectorAll('form[data-confirm]').forEach(function(f) {
  f.addEventListener('submit', function(e) {
    if (!confirm(f.dataset.confirm)) e.preventDefault();
  });
});
</script>
