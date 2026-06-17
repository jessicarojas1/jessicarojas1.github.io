<?php
$pageTitle    = 'Content Health';
$activeModule = 'reports';
$breadcrumbs  = [['Reports', '/reports'], ['Content Health', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title"><i class="bi bi-heart-pulse"></i> Content Health</h1><p class="page-subtitle">Orphaned pages and broken internal links across the wiki.</p></div>
  <div class="page-actions"><a href="/reports" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> All reports</a></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-signpost-split"></i></div><div><div class="stat-value"><?= count($orphans) ?></div><div class="stat-label">Orphaned pages</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-link-45deg"></i></div><div><div class="stat-value"><?= count($broken) ?></div><div class="stat-label">Broken internal links</div></div></div>
</div>

<div class="card" style="margin-top:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-signpost-split"></i> Orphaned pages</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0">
      <thead><tr><th>Page</th><th>Space</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($orphans as $p): ?>
        <tr>
          <td><a href="/pages/<?= (int)$p['id'] ?>" class="table-link"><?= Security::h($p['title']) ?></a></td>
          <td class="form-hint"><?= Security::h($p['space_key'] ?: '—') ?></td>
          <td><?= View::statusBadge((string)$p['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orphans): ?>
        <tr><td colspan="3" class="empty-row"><div class="empty-state-sm"><i class="bi bi-check2-circle"></i><p>No orphaned pages — every top-level page is a homepage or linked from somewhere.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-link-45deg"></i> Broken internal links</span></div></div>
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0">
      <thead><tr><th>On page</th><th>Space</th><th>Broken link target</th></tr></thead>
      <tbody>
      <?php foreach ($broken as $b): ?>
        <tr>
          <td><a href="/pages/<?= (int)$b['source_id'] ?>" class="table-link"><?= Security::h($b['source_title']) ?></a></td>
          <td class="form-hint"><?= Security::h($b['space_key'] ?: '—') ?></td>
          <td><span class="badge badge-red"><i class="bi bi-x-circle"></i> <?= Security::h($b['kind']) ?> #<?= (int)$b['target'] ?></span> <span class="form-hint">/<?= Security::h($b['kind'] === 'document' ? 'documents' : 'pages') ?>/<?= (int)$b['target'] ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$broken): ?>
        <tr><td colspan="3" class="empty-row"><div class="empty-state-sm"><i class="bi bi-check2-circle"></i><p>No broken internal links found.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
