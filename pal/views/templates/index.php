<?php
$pageTitle    = 'Templates';
$activeModule = 'templates';
$breadcrumbs  = [['Templates', null]];
ob_start();

$catMeta = [
    'document' => ['Documents', 'bi-file-earmark-text', '#2563eb'],
    'page'     => ['Pages',     'bi-file-richtext',     '#0ea5e9'],
    'process'  => ['Processes', 'bi-diagram-3',         '#7c3aed'],
    'meeting'  => ['Meetings',  'bi-calendar-event',    '#0891b2'],
    'project'  => ['Projects',  'bi-kanban',            '#16a34a'],
    'risk'     => ['Risk',      'bi-shield-exclamation', '#dc2626'],
    'audit'    => ['Audit',     'bi-clipboard-check',   '#d97706'],
];
$activeCat = Security::sanitizeInput($_GET['category'] ?? '');

// Group templates by category, preserving the catMeta ordering.
$grouped = [];
foreach ($templates as $t) { $grouped[$t['category']][] = $t; }
?>
<div class="page-header">
  <div><h1 class="page-title">Templates</h1><p class="page-subtitle">Reusable starting points for documents, pages, processes &amp; more</p></div>
  <div class="page-actions">
    <?php if (Auth::can('template.manage')): ?><a href="/templates/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Template</a><?php endif; ?>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-body">
    <form method="GET" action="/templates" class="form-row" style="align-items:flex-end;gap:12px">
      <div class="form-group" style="margin:0"><label class="form-label">Category</label>
        <select name="category" class="form-select">
          <option value="">All categories</option>
          <?php foreach ($catMeta as $key => $meta): ?><option value="<?= Security::h($key) ?>" <?= $activeCat===$key?'selected':'' ?>><?= Security::h($meta[0]) ?></option><?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
      <a href="/templates" class="btn btn-ghost">Reset</a>
    </form>
  </div>
</div>

<?php if ($templates): ?>
  <?php foreach ($catMeta as $key => $meta): ?>
    <?php if (empty($grouped[$key])) continue; ?>
    <h2 class="page-title" style="font-size:1.05rem;margin:22px 0 12px"><i class="bi <?= Security::h($meta[1]) ?>"></i> <?= Security::h($meta[0]) ?></h2>
    <div class="lib-grid">
      <?php foreach ($grouped[$key] as $t): ?>
      <a href="/templates/<?= (int)$t['id'] ?>" class="lib-card">
        <div class="lib-card-icon" style="background:<?= Security::h($meta[2]) ?>"><i class="bi <?= Security::h($meta[1]) ?>"></i></div>
        <div>
          <div class="lib-card-title"><?= Security::h($t['name']) ?></div>
          <div class="lib-card-desc"><?= Security::h($t['description'] ?: 'No description provided.') ?></div>
        </div>
        <div class="lib-card-foot">
          <span class="chip"><?= Security::h($meta[0]) ?></span>
          <?php if (!empty($t['doc_type'])): ?><span class="chip"><?= Security::h(View::docTypeLabel($t['doc_type'])) ?></span><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php else: ?>
<div class="card"><div class="card-body"><div class="empty-state"><i class="bi bi-files"></i><p>No templates found.</p><?php if (Auth::can('template.manage')): ?><a href="/templates/create" class="btn btn-primary btn-sm">Create the first template</a><?php endif; ?></div></div></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
