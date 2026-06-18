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
    $canMove = Auth::can('page.edit');
    $csrf = $canMove ? Security::csrfField() : '';
    $siblings = $byParent[$parent ?? 0];
    $last = count($siblings) - 1;
    echo '<ul' . ($depth === 0 ? ' class="page-tree"' : '') . '>';
    foreach ($siblings as $i => $p) {
        $pid = (int)$p['id'];
        $par = $p['parent_id'] !== null ? (int)$p['parent_id'] : '';
        echo '<li data-pt-node="' . $pid . '" data-pt-parent="' . $par . '">';
        echo '<div class="pt-row" style="display:flex;align-items:center;gap:4px"' . ($canMove ? ' draggable="true"' : '') . '>';
        if (Auth::can('page.delete')) echo '<input type="checkbox" class="pt-check" value="' . $pid . '" title="Select" style="flex:none">';
        if ($canMove) echo '<span class="pt-handle" style="cursor:grab;color:var(--text-light)" title="Drag to move"><i class="bi bi-grip-vertical"></i></span>';
        echo '<a href="/pages/' . $pid . '" style="flex:1"><i class="bi bi-file-richtext"></i> ' . Security::h($p['title']) . ' ' . View::statusBadge($p['status']) . '</a>';
        if ($canMove && count($siblings) > 1) {
            $up = $i > 0 ? '' : ' disabled style="visibility:hidden"';
            $dn = $i < $last ? '' : ' disabled style="visibility:hidden"';
            echo '<form method="POST" action="/pages/' . $pid . '/move" style="margin:0">' . $csrf . '<input type="hidden" name="dir" value="up"><button class="btn-unstyled" type="submit" title="Move up" style="border:none;background:none;cursor:pointer;color:var(--text-light);padding:0 2px"' . $up . '><i class="bi bi-chevron-up"></i></button></form>';
            echo '<form method="POST" action="/pages/' . $pid . '/move" style="margin:0">' . $csrf . '<input type="hidden" name="dir" value="down"><button class="btn-unstyled" type="submit" title="Move down" style="border:none;background:none;cursor:pointer;color:var(--text-light);padding:0 2px"' . $dn . '><i class="bi bi-chevron-down"></i></button></form>';
        }
        echo '</div>';
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
    <?php if (Auth::can('page.create')): ?><a href="/pages/templates?space=<?= (int)$space['id'] ?>" class="btn btn-ghost"><i class="bi bi-plus-lg"></i> Page</a><?php endif; ?>
    <?php if (Auth::can('page.create')): ?><a href="/pages/import?space=<?= (int)$space['id'] ?>" class="btn btn-ghost"><i class="bi bi-markdown"></i> Import MD</a><?php endif; ?>
    <a href="/spaces/<?= (int)$space['id'] ?>/blog" class="btn btn-ghost"><i class="bi bi-newspaper"></i> Blog</a>
    <a href="/spaces/<?= (int)$space['id'] ?>/export" class="btn btn-ghost" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Export</a>
    <a href="/spaces/<?= (int)$space['id'] ?>/export-zip" class="btn btn-ghost"><i class="bi bi-file-zip"></i> PDF ZIP</a>
    <a href="/spaces/<?= (int)$space['id'] ?>/export-word" class="btn btn-ghost"><i class="bi bi-file-earmark-word-fill"></i> Word (.docx)</a>
    <?php if (Auth::can('page.delete')): ?><a href="/spaces/<?= (int)$space['id'] ?>/trash" class="btn btn-ghost"><i class="bi bi-trash"></i> Trash</a><?php endif; ?>
    <?php if (Auth::can('space.edit')): ?><a href="/spaces/<?= (int)$space['id'] ?>/edit" class="btn btn-ghost"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
  </div>
</div>

<?php if ($space['description']): ?><div class="card" style="margin-bottom:18px"><div class="card-body"><p style="margin:0;color:var(--text-muted)"><?= Security::h($space['description']) ?></p></div></div><?php endif; ?>

<?php if (!empty($homepage)): ?>
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-house-fill"></i> <?= Security::h($homepage['title']) ?></span></div><a href="/pages/<?= (int)$homepage['id'] ?>" class="btn btn-sm btn-ghost">Open page</a></div>
  <div class="card-body"><div class="prose"><?= $homepage['body'] ?: '<p class="form-hint">This homepage has no content yet.</p>' ?></div></div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-list-nested"></i> Page Tree</span></div></div>
    <div class="card-body">
      <?php if ($pages): ?>
        <div data-page-tree data-space-id="<?= (int)$space['id'] ?>"<?= Auth::can('page.edit') ? ' data-can-edit="1"' : '' ?>>
          <?php renderTree($byParent, 0); ?>
        </div>
        <?php if (Auth::can('page.edit')): ?><div class="form-hint" style="margin-top:8px"><i class="bi bi-grip-vertical"></i> Drag pages to reorder or re-nest them.</div><?php endif; ?>
        <?php if (Auth::can('page.delete')):
          $bulkTags   = Database::fetchAll("SELECT id, name FROM tags ORDER BY name");
          $bulkSpaces = Database::fetchAll("SELECT id, name FROM spaces WHERE is_archived=FALSE AND id<>? ORDER BY name", [(int)$space['id']]);
        ?>
        <form method="POST" action="/spaces/<?= (int)$space['id'] ?>/bulk" data-bulk-bar hidden style="margin-top:12px;border:1px solid var(--primary);border-radius:8px;padding:10px;background:var(--bg-secondary)">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" data-bulk-action>
          <input type="hidden" name="page_ids" data-bulk-ids>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span class="form-hint" style="font-weight:700"><span data-bulk-count>0</span> selected</span>
            <button type="submit" class="btn btn-sm btn-danger" data-bulk-do="trash"><i class="bi bi-trash"></i> Trash</button>
            <span style="display:flex;gap:4px;align-items:center">
              <select name="tag_id" class="form-select" style="padding:3px 6px;max-width:150px"><option value="">Label…</option><?php foreach ($bulkTags as $tg): ?><option value="<?= (int)$tg['id'] ?>"><?= Security::h($tg['name']) ?></option><?php endforeach; ?></select>
              <button type="submit" class="btn btn-sm" data-bulk-do="label"><i class="bi bi-tag"></i> Apply</button>
            </span>
            <?php if ($bulkSpaces): ?>
            <span style="display:flex;gap:4px;align-items:center">
              <select name="target_space" class="form-select" style="padding:3px 6px;max-width:150px"><option value="">Move to…</option><?php foreach ($bulkSpaces as $bs): ?><option value="<?= (int)$bs['id'] ?>"><?= Security::h($bs['name']) ?></option><?php endforeach; ?></select>
              <button type="submit" class="btn btn-sm" data-bulk-do="move"><i class="bi bi-arrow-right"></i> Move</button>
            </span>
            <?php endif; ?>
          </div>
        </form>
        <?php endif; ?>
      <?php else: ?><div class="empty-state-sm">No pages yet.</div><?php endif; ?>
    </div>
    <div class="card-header" style="border-top:1px solid var(--border-light)" id="shortcuts"><div class="card-header-left"><span class="card-title"><i class="bi bi-link-45deg"></i> Shortcuts</span></div></div>
    <div class="card-body">
      <?php if (!empty($shortcuts)): ?>
        <ul class="macro-list" style="list-style:none;margin:0;padding:0">
        <?php foreach ($shortcuts as $sc): ?>
          <li style="display:flex;align-items:center;gap:6px;padding:3px 0">
            <a href="<?= Security::h($sc['url']) ?>"<?= str_starts_with((string)$sc['url'], '/') ? '' : ' target="_blank" rel="noopener noreferrer"' ?> style="flex:1"><i class="bi <?= Security::h($sc['icon'] ?: 'bi-link-45deg') ?>"></i> <?= Security::h($sc['label']) ?></a>
            <?php if (!empty($canManageSpace)): ?>
              <form method="POST" action="/spaces/<?= (int)$space['id'] ?>/shortcuts/<?= (int)$sc['id'] ?>/delete" style="margin:0" data-confirm="Remove this shortcut?"><?= Security::csrfField() ?><button class="btn-unstyled" type="submit" title="Remove" style="border:none;background:none;cursor:pointer;color:var(--danger)"><i class="bi bi-x-lg"></i></button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php else: ?><div class="empty-state-sm">No shortcuts yet.</div><?php endif; ?>
      <?php if (!empty($canManageSpace)): ?>
        <form method="POST" action="/spaces/<?= (int)$space['id'] ?>/shortcuts" style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
          <?= Security::csrfField() ?>
          <input type="text" name="label" class="form-control form-control-sm" placeholder="Label" maxlength="120" required>
          <input type="text" name="url" class="form-control form-control-sm" placeholder="https://… or /internal/path" maxlength="2048" required>
          <div style="display:flex;gap:6px">
            <input type="text" name="icon" class="form-control form-control-sm" placeholder="bi-link-45deg" style="flex:1">
            <button class="btn btn-sm" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
    <div class="card-header" style="border-top:1px solid var(--border-light)" id="members"><div class="card-header-left"><span class="card-title"><i class="bi bi-people"></i> Members &amp; Permissions</span></div>
      <span class="badge <?= (isset($space['is_private']) && in_array(strtolower((string)$space['is_private']),['1','t','true'],true)) ? 'badge-amber' : 'badge-gray' ?>"><i class="bi bi-<?= (isset($space['is_private']) && in_array(strtolower((string)$space['is_private']),['1','t','true'],true)) ? 'lock-fill' : 'unlock' ?>"></i> <?= (isset($space['is_private']) && in_array(strtolower((string)$space['is_private']),['1','t','true'],true)) ? 'Private' : 'Open' ?></span>
    </div>
    <div class="card-body">
      <?php foreach ($members as $m): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:6px 0">
        <?= View::avatar($m['name']) ?>
        <div style="flex:1"><div style="font-weight:600;font-size:.85rem"><?= Security::h($m['name']) ?></div><div class="form-hint"><?= Security::h(ucfirst($m['role'])) ?></div></div>
        <?php if (!empty($canManageSpace) && $m['role'] !== 'owner'): ?>
          <form method="POST" action="/spaces/<?= (int)$space['id'] ?>/members/<?= (int)$m['id'] ?>/remove" style="margin:0" data-confirm="Remove <?= Security::h($m['name']) ?> from this space?"><?= Security::csrfField() ?><button class="btn-unstyled" type="submit" title="Remove" style="border:none;background:none;cursor:pointer;color:var(--danger)"><i class="bi bi-x-lg"></i></button></form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$members): ?><div class="empty-state-sm">No members.</div><?php endif; ?>

      <?php if (!empty($canManageSpace)): ?>
      <form method="POST" action="/spaces/<?= (int)$space['id'] ?>/members" style="margin-top:10px;border-top:1px solid var(--border-light);padding-top:10px">
        <?= Security::csrfField() ?>
        <div class="form-group" style="margin-bottom:6px"><select name="user_id" class="form-select" required>
          <option value="">Add a member…</option>
          <?php foreach ($addableUsers as $au): ?><option value="<?= (int)$au['id'] ?>"><?= Security::h($au['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row" style="gap:6px">
          <select name="role" class="form-select" style="flex:1">
            <option value="viewer">Viewer</option>
            <option value="contributor">Contributor</option>
            <option value="reviewer">Reviewer</option>
            <option value="approver">Approver</option>
            <option value="admin">Space admin</option>
          </select>
          <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
        </div>
        <div class="form-hint" style="margin-top:6px">Private spaces are visible only to members. Space admins manage members &amp; settings.</div>
      </form>
      <?php endif; ?>
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

    <?php $spaceActivity = Activity::feed(8, (int)$space['id']); if ($spaceActivity): ?>
    <div class="card" style="margin-top:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-activity"></i> Recently Updated</span></div><a href="/activity?space=<?= (int)$space['id'] ?>" class="btn btn-sm btn-ghost">View all</a></div>
      <div class="card-body">
        <?php foreach ($spaceActivity as $it): ?>
          <div style="display:flex;align-items:flex-start;gap:8px;padding:6px 0;border-bottom:1px solid var(--border-light)">
            <i class="bi <?= Security::h($it['icon']) ?>" style="color:var(--primary);margin-top:3px"></i>
            <div style="flex:1;font-size:.88rem"><span style="font-weight:600"><?= Security::h($it['actor']) ?></span> <span class="form-hint"><?= Security::h($it['verb']) ?></span> <?php if ($it['link']): ?><a href="<?= Security::h($it['link']) ?>"><?= Security::h($it['target_title']) ?></a><?php else: ?><?= Security::h($it['target_title']) ?><?php endif; ?></div>
            <span class="form-hint" style="white-space:nowrap"><?= Security::h(View::timeAgo($it['created_at'])) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
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
require PALADIN_ROOT . '/views/layout.php';
