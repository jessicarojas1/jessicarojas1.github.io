<?php
$pageTitle    = $space['name'];
$activeModule = 'spaces';
$breadcrumbs  = [['Spaces', '/spaces'], [$space['space_key'], null]];
ob_start();

// Build page tree
$byParent = [];
foreach ($pages as $p) { $byParent[$p['parent_id'] ?? 0][] = $p; }
function renderTree(array $byParent, $parent, $depth = 0) {
    if (empty($byParent[$parent ?? 0])) return;
    echo '<ul' . ($depth === 0 ? ' class="page-tree"' : '') . '>';
    foreach ($byParent[$parent ?? 0] as $p) {
        echo '<li><a href="/pages/' . (int)$p['id'] . '"><i class="bi bi-file-richtext"></i> ' . Security::h($p['title']) . ' ' . View::statusBadge($p['status']) . '</a>';
        renderTree($byParent, $p['id'], $depth + 1);
        echo '</li>';
    }
    echo '</ul>';
}
?>
<div class="page-header">
  <div style="display:flex;align-items:center;gap:14px">
    <div class="lib-card-icon" style="background:<?= Security::h($space['color'] ?: '#2563eb') ?>;width:52px;height:52px;font-size:1.6rem"><i class="bi <?= Security::h($space['icon'] ?: 'bi-folder2-open') ?>"></i></div>
    <div>
      <h1 class="page-title"><?= Security::h($space['name']) ?> <span class="chip"><?= Security::h($space['space_key']) ?></span></h1>
      <p class="page-subtitle"><?= Security::h(ucfirst($space['type'])) ?> space · Owner: <?= Security::h($space['owner_name'] ?: '—') ?><?= $space['is_private'] ? ' · 🔒 Private' : '' ?></p>
    </div>
  </div>
  <div class="page-actions">
    <form method="POST" action="/spaces/<?= (int)$space['id'] ?>/favorite" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-ghost" type="submit"><i class="bi bi-star<?= $isFav?'-fill':'' ?>"></i> <?= $isFav?'Favorited':'Favorite' ?></button></form>
    <form method="POST" action="/spaces/<?= (int)$space['id'] ?>/watch" style="margin:0"><?= Security::csrfField() ?><button class="btn btn-ghost" type="submit"><i class="bi bi-eye<?= $isWatching?'-fill':'' ?>"></i> <?= $isWatching?'Watching':'Watch' ?></button></form>
    <?php if (Auth::can('page.create')): ?><a href="/spaces/<?= (int)$space['id'] ?>/pages/create" class="btn btn-ghost"><i class="bi bi-plus-lg"></i> Page</a><?php endif; ?>
    <?php if (Auth::can('space.edit')): ?><a href="/spaces/<?= (int)$space['id'] ?>/edit" class="btn btn-ghost"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
  </div>
</div>

<?php if ($space['description']): ?><div class="card" style="margin-bottom:18px"><div class="card-body"><p style="margin:0;color:var(--text-muted)"><?= Security::h($space['description']) ?></p></div></div><?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-list-nested"></i> Page Tree</span></div></div>
    <div class="card-body">
      <?php if ($pages): renderTree($byParent, 0); else: ?><div class="empty-state-sm">No pages yet.</div><?php endif; ?>
    </div>
    <div class="card-header" style="border-top:1px solid var(--border-light)"><div class="card-header-left"><span class="card-title"><i class="bi bi-people"></i> Members</span></div></div>
    <div class="card-body">
      <?php foreach ($members as $m): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:6px 0"><?= View::avatar($m['name']) ?><div><div style="font-weight:600;font-size:.85rem"><?= Security::h($m['name']) ?></div><div class="form-hint"><?= Security::h(ucfirst($m['role'])) ?></div></div></div>
      <?php endforeach; ?>
      <?php if (!$members): ?><div class="empty-state-sm">No members.</div><?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-file-earmark-text"></i> Documents (<?= count($documents) ?>)</span></div><?php if (Auth::can('document.create')): ?><a href="/documents/create?space=<?= (int)$space['id'] ?>" class="btn btn-sm btn-ghost">Add</a><?php endif; ?></div>
      <div class="card-body" style="padding:0">
        <table class="table table-hover" style="margin:0">
          <tbody>
          <?php foreach ($documents as $d): ?>
            <tr><td style="width:90px"><span class="chip"><?= Security::h($d['document_code']) ?></span></td><td><a href="/documents/<?= (int)$d['id'] ?>" class="table-link"><?= Security::h($d['title']) ?></a><div class="form-hint"><?= View::docTypeLabel($d['doc_type']) ?> · rev <?= Security::h($d['revision']) ?></div></td><td style="text-align:right"><?= View::statusBadge($d['status']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$documents): ?><tr><td><div class="empty-state-sm">No documents in this space.</div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-diagram-3"></i> Processes (<?= count($processes) ?>)</span></div></div>
      <div class="card-body" style="padding:0">
        <table class="table table-hover" style="margin:0">
          <tbody>
          <?php foreach ($processes as $p): ?>
            <tr><td style="width:90px"><span class="chip"><?= Security::h($p['process_code']) ?></span></td><td><a href="/processes/<?= (int)$p['id'] ?>" class="table-link"><?= Security::h($p['name']) ?></a><div class="form-hint">v<?= Security::h($p['version']) ?></div></td><td style="text-align:right"><?= View::statusBadge($p['status']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$processes): ?><tr><td><div class="empty-state-sm">No processes in this space.</div></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if (Auth::can('space.delete')): ?>
<div style="margin-top:20px">
  <form method="POST" action="/spaces/<?= (int)$space['id'] ?>/delete" style="margin:0" data-confirm="Archive this space? Its content will be hidden from the library.">
    <?= Security::csrfField() ?><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-archive"></i> Archive Space</button>
  </form>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
