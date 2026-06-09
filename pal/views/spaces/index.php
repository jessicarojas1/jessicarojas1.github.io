<?php
$pageTitle    = 'Spaces';
$activeModule = 'spaces';
$breadcrumbs  = [['Spaces', null]];
ob_start();
$typeIcons = ['department'=>'bi-building','team'=>'bi-people','program'=>'bi-diagram-3','project'=>'bi-kanban','compliance'=>'bi-patch-check','process'=>'bi-gear-wide-connected','admin'=>'bi-shield-lock'];
?>
<div class="page-header">
  <div><h1 class="page-title">Spaces</h1><p class="page-subtitle">Organizational knowledge areas — departments, teams, programs &amp; compliance</p></div>
  <div class="page-actions">
    <?php if (Auth::can('space.create')): ?><a href="/spaces/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Space</a><?php endif; ?>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-body">
    <form method="GET" action="/spaces" class="form-row" style="align-items:flex-end;gap:12px">
      <div class="form-group" style="flex:1;margin:0"><label class="form-label">Search</label><input type="search" name="q" class="form-control" placeholder="Name, key or description…" value="<?= Security::h($_GET['q'] ?? '') ?>"></div>
      <div class="form-group" style="margin:0"><label class="form-label">Type</label>
        <select name="type" class="form-select">
          <option value="">All types</option>
          <?php foreach (View::spaceTypes() as $t): ?><option value="<?= $t ?>" <?= ($_GET['type'] ?? '')===$t?'selected':'' ?>><?= ucfirst($t) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
      <a href="/spaces" class="btn btn-ghost">Reset</a>
    </form>
  </div>
</div>

<?php if ($spaces): ?>
<div class="lib-grid">
  <?php foreach ($spaces as $s): ?>
  <a href="/spaces/<?= (int)$s['id'] ?>" class="lib-card">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div class="lib-card-icon" style="background:<?= Security::h($s['color'] ?: '#2563eb') ?>"><i class="bi <?= Security::h($s['icon'] ?: ($typeIcons[$s['type']] ?? 'bi-folder2-open')) ?>"></i></div>
      <?php if (!empty($s['is_fav'])): ?><i class="bi bi-star-fill" style="color:var(--warning)"></i><?php endif; ?>
    </div>
    <div>
      <div class="lib-card-title"><?= Security::h($s['name']) ?> <span class="chip"><?= Security::h($s['space_key']) ?></span></div>
      <div class="lib-card-desc"><?= Security::h($s['description'] ?: 'No description provided.') ?></div>
    </div>
    <div class="lib-card-foot">
      <span><i class="bi bi-file-richtext"></i> <?= (int)$s['page_count'] ?> pages</span>
      <span><i class="bi bi-file-earmark-text"></i> <?= (int)$s['doc_count'] ?> docs</span>
      <span style="margin-left:auto"><i class="bi bi-person"></i> <?= Security::h($s['owner_name'] ?: '—') ?></span>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-collection"></i><p>No spaces found.</p><?php if (Auth::can('space.create')): ?><a href="/spaces/create" class="btn btn-primary btn-sm">Create the first space</a><?php endif; ?></div></div></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
