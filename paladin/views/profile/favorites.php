<?php
$pageTitle    = 'My Favorites';
$activeModule = 'profile_favorites';
$breadcrumbs  = [['Account', null], ['My Favorites', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">My Favorites &amp; Watches</h1><p class="page-subtitle">Your starred spaces and pages, and the content you're watching for changes.</p></div>
</div>

<div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-star-fill"></i> Favorite spaces</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0"><tbody>
        <?php foreach ($favSpaces as $s): ?>
          <tr><td><a href="/spaces/<?= (int)$s['id'] ?>"><i class="bi bi-collection-fill"></i> <?= Security::h($s['name']) ?></a> <span class="chip"><?= Security::h($s['space_key']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$favSpaces): ?><tr><td class="empty-row"><div class="empty-state-sm"><i class="bi bi-star"></i><p>No favorite spaces yet.</p></div></td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-star-fill"></i> Favorite pages</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0"><tbody>
        <?php foreach ($favPages as $p): ?>
          <tr><td><a href="/pages/<?= (int)$p['id'] ?>"><i class="bi bi-file-earmark-text"></i> <?= Security::h($p['title']) ?></a> <span class="form-hint"><?= Security::h($p['space_name'] ?? '') ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$favPages): ?><tr><td class="empty-row"><div class="empty-state-sm"><i class="bi bi-star"></i><p>No favorite pages yet.</p></div></td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-eye-fill"></i> Watched pages</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0"><tbody>
        <?php foreach ($watchedPages as $p): ?>
          <tr><td><a href="/pages/<?= (int)$p['id'] ?>"><i class="bi bi-file-earmark-text"></i> <?= Security::h($p['title']) ?></a> <span class="form-hint"><?= Security::h($p['space_name'] ?? '') ?> · updated <?= Security::h(View::timeAgo($p['updated_at'])) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$watchedPages): ?><tr><td class="empty-row"><div class="empty-state-sm"><i class="bi bi-eye"></i><p>Not watching any pages.</p></div></td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-eye-fill"></i> Watched spaces</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0"><tbody>
        <?php foreach ($watchedSpaces as $s): ?>
          <tr><td><a href="/spaces/<?= (int)$s['id'] ?>"><i class="bi bi-collection-fill"></i> <?= Security::h($s['name']) ?></a> <span class="chip"><?= Security::h($s['space_key']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$watchedSpaces): ?><tr><td class="empty-row"><div class="empty-state-sm"><i class="bi bi-eye"></i><p>Not watching any spaces.</p></div></td></tr><?php endif; ?>
      </tbody></table>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
