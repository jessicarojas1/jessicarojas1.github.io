<?php
$pageTitle    = 'Search';
$activeModule = 'search';
$breadcrumbs  = [['Search', null]];
ob_start();

$typeMeta = [
    'documents' => ['bi-file-earmark-text-fill', 'Documents', 'var(--primary)',   '/documents/'],
    'pages'     => ['bi-file-richtext-fill',     'Pages',     'var(--indigo)',    '/pages/'],
    'blogs'     => ['bi-newspaper',              'Blog Posts','var(--purple)',    '/blog/'],
    'comments'  => ['bi-chat-left-text-fill',    'Comments',  'var(--info)',      ''],
    'processes' => ['bi-diagram-3-fill',         'Processes', 'var(--info)',      '/processes/'],
    'tasks'     => ['bi-list-task',              'Tasks',     'var(--warning)',   '/tasks/'],
    'spaces'    => ['bi-collection-fill',        'Spaces',    'var(--purple)',    '/spaces/'],
];
?>
<div class="page-header">
  <div><h1 class="page-title">Search</h1><p class="page-subtitle">Find documents, pages, processes, tasks &amp; spaces across the library</p></div>
</div>

<div class="card" style="margin:18px 0">
  <div class="card-body">
    <form method="GET" action="/search" class="form-row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
      <div class="form-group" style="flex:1;min-width:220px;margin:0"><label class="form-label">Search</label><input type="search" name="q" class="form-control" value="<?= Security::h($q) ?>" placeholder="Search everything…  try in:SPACE  label:NAME  by:NAME" autofocus><p class="form-hint" style="margin:4px 0 0">Filters: <code>in:KEY</code> (space) · <code>label:name</code> · <code>by:name</code> · <code>type:pages</code></p></div>
      <div class="form-group" style="margin:0"><label class="form-label">Type</label>
        <select name="type" class="form-select">
          <option value="">All types</option>
          <?php foreach ($typeMeta as $tk => $tm): ?>
            <option value="<?= Security::h($tk) ?>" <?= ($type ?? '') === $tk ? 'selected' : '' ?>><?= Security::h($tm[1]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Search</button>
      <?php if ($q !== ''): ?><a href="/search" class="btn btn-ghost">Reset</a><?php endif; ?>
    </form>
  </div>
</div>

<?php if ($q === ''): ?>
  <div class="card"><div class="card-body">
    <div class="empty-state">
      <i class="bi bi-search"></i>
      <p>Type a term above to search across documents, pages, processes, tasks and spaces.</p>
    </div>
  </div></div>

  <div class="card" style="margin-top:18px">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-bookmark-star-fill"></i> Saved Searches</span></div></div>
    <div class="card-body" style="padding:0">
      <?php if ($savedSearches): ?>
        <table class="table table-hover" style="margin:0">
          <tbody>
            <?php foreach ($savedSearches as $ss): ?>
              <tr>
                <td><a href="/search?q=<?= Security::h(urlencode($ss['query'] ?? '')) ?>" class="table-link"><i class="bi bi-bookmark-fill"></i> <?= Security::h($ss['name']) ?></a></td>
                <td class="form-hint" style="text-align:right"><?= Security::h($ss['query']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty-state-sm" style="padding:18px">No saved searches yet. Run a search and save it for quick access.</div>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

  <div class="card" style="margin-bottom:18px"><div class="card-body" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div><strong><?= (int)$total ?></strong> result<?= $total === 1 ? '' : 's' ?> for &ldquo;<?= Security::h($q) ?>&rdquo;<?php if ($type !== ''): ?> <span class="chip"><?= Security::h($typeMeta[$type][1] ?? $type) ?></span><?php endif; ?><?php foreach (($activeFilters ?? []) as $af): ?> <span class="chip"><?= Security::h($af[0]) ?>: <?= Security::h($af[1]) ?></span><?php endforeach; ?></div>
    <form method="POST" action="/searches/save" class="form-row" style="align-items:flex-end;gap:8px;margin:0;flex-wrap:wrap">
      <?= Security::csrfField() ?>
      <input type="hidden" name="query" value="<?= Security::h($q) ?>">
      <div class="form-group" style="margin:0"><input type="text" name="name" class="form-control form-control-sm" placeholder="Name this search…" required></div>
      <button class="btn btn-sm btn-ghost" type="submit"><i class="bi bi-bookmark-plus"></i> Save this search</button>
    </form>
  </div></div>

  <?php if ($total === 0): ?>
    <div class="card"><div class="card-body">
      <div class="empty-state"><i class="bi bi-file-earmark-x"></i><p>No results found. Try a different term or broaden the type filter.</p></div>
    </div></div>
  <?php else: ?>
    <?php foreach ($typeMeta as $tk => $tm): ?>
      <?php $rows = $results[$tk] ?? []; if (!$rows) continue; [$icon, $label, $color, $base] = $tm; ?>
      <div class="card" style="margin-bottom:18px">
        <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi <?= Security::h($icon) ?>" style="color:<?= Security::h($color) ?>"></i> <?= Security::h($label) ?></span> <span class="chip"><?= count($rows) ?></span></div></div>
        <div class="card-body" style="padding:0">
          <table class="table table-hover" style="margin:0">
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <?php if ($tk === 'documents'): ?>
                    <td style="width:120px"><span class="chip"><?= Security::h($row['document_code']) ?></span></td>
                    <td><a href="<?= Security::h($base) ?><?= (int)$row['id'] ?>" class="table-link"><?= Security::h($row['title']) ?></a> <span class="chip"><?= Security::h(View::docTypeLabel($row['doc_type'])) ?></span></td>
                    <td style="text-align:right"><?= View::statusBadge($row['status']) ?></td>
                  <?php elseif ($tk === 'pages'): ?>
                    <td><a href="<?= Security::h($base) ?><?= (int)$row['id'] ?>" class="table-link"><?= Security::h($row['title']) ?></a></td>
                    <td class="form-hint"><?= Security::h($row['space_name'] ?: '—') ?></td>
                    <td style="text-align:right"><?= View::statusBadge($row['status']) ?></td>
                  <?php elseif ($tk === 'blogs'): ?>
                    <td><a href="<?= Security::h($base) ?><?= (int)$row['id'] ?>" class="table-link"><?= Security::h($row['title']) ?></a></td>
                    <td class="form-hint"><?= Security::h($row['space_name'] ?: '—') ?></td>
                    <td style="text-align:right"><?= View::statusBadge($row['status']) ?></td>
                  <?php elseif ($tk === 'comments'): ?>
                    <?php $cl = $row['entity_type']==='document' ? '/documents/' : ($row['entity_type']==='blog' ? '/blog/' : '/pages/'); ?>
                    <td><a href="<?= Security::h($cl) ?><?= (int)$row['entity_id'] ?>#comments" class="table-link"><?= Security::h(mb_strimwidth(trim((string)$row['body']), 0, 90, '…')) ?></a><div class="form-hint"><?= Security::h($row['author'] ?: 'Someone') ?> · on <?= Security::h($row['entity_type']) ?></div></td>
                  <?php elseif ($tk === 'processes'): ?>
                    <td style="width:120px"><span class="chip"><?= Security::h($row['process_code']) ?></span></td>
                    <td><a href="<?= Security::h($base) ?><?= (int)$row['id'] ?>" class="table-link"><?= Security::h($row['name']) ?></a></td>
                    <td style="text-align:right"><?= View::statusBadge($row['status']) ?></td>
                  <?php elseif ($tk === 'tasks'): ?>
                    <td><a href="<?= Security::h($base) ?><?= (int)$row['id'] ?>" class="table-link"><?= Security::h($row['title']) ?></a> <?= View::priorityBadge($row['priority']) ?></td>
                    <td style="text-align:right"><?= View::statusBadge($row['status']) ?></td>
                  <?php else: ?>
                    <td style="width:120px"><span class="chip"><?= Security::h($row['space_key']) ?></span></td>
                    <td><a href="<?= Security::h($base) ?><?= (int)$row['id'] ?>" class="table-link"><?= Security::h($row['name']) ?></a><?php if (!empty($row['description'])): ?><div class="form-hint"><?= Security::h($row['description']) ?></div><?php endif; ?></td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
