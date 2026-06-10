<?php
$pageTitle    = 'Processes';
$activeModule = 'processes';
$breadcrumbs  = [['Processes', null]];
ob_start();
$hasFilter = !empty($_GET['status']) || !empty($_GET['space']) || !empty($_GET['q']);
?>
<div class="page-header">
  <div><h1 class="page-title">Business Processes</h1><p class="page-subtitle">Documented process flows, owners &amp; lifecycle status</p></div>
  <div class="page-actions"><a href="/processes/export?<?= Security::h(http_build_query(['status' => $_GET['status'] ?? '', 'space' => $_GET['space'] ?? '', 'q' => $_GET['q'] ?? ''])) ?>" class="btn btn-ghost"><i class="bi bi-filetype-csv"></i> Export register</a><?php if (Auth::can('process.create')): ?><a href="/processes/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Process</a><?php endif; ?></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-diagram-3-fill"></i></div><div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Total</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-patch-check-fill"></i></div><div><div class="stat-value"><?= (int)$stats['published'] ?></div><div class="stat-label">Published</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(100,116,139,.12);color:var(--secondary)"><i class="bi bi-pencil"></i></div><div><div class="stat-value"><?= (int)$stats['draft'] ?></div><div class="stat-label">Draft</div></div></div>
</div>

<div class="card" style="margin:18px 0">
  <div class="card-body">
    <form method="GET" action="/processes" class="form-row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
      <div class="form-group" style="flex:1;min-width:180px;margin:0"><label class="form-label">Search</label><input type="search" name="q" class="form-control" value="<?= Security::h($_GET['q'] ?? '') ?>" placeholder="Name, code, description…"></div>
      <div class="form-group" style="margin:0"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All</option><?php foreach (['draft','in_review','published','retired'] as $st): ?><option value="<?= $st ?>" <?= ($_GET['status'] ?? '')===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0"><label class="form-label">Space</label><select name="space" class="form-select"><option value="">All</option><?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (($_GET['space'] ?? '')==$s['id'])?'selected':'' ?>><?= Security::h($s['space_key']) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
      <?php if ($hasFilter): ?><a href="/processes" class="btn btn-ghost">Reset</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>Code</th><th>Name</th><th>Owner</th><th>Version</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($processes as $p): ?>
      <tr>
        <td><span class="chip"><?= Security::h($p['process_code']) ?></span></td>
        <td><a href="/processes/<?= (int)$p['id'] ?>" class="table-link"><?= Security::h($p['name']) ?></a></td>
        <td class="form-hint"><?= Security::h($p['owner_name'] ?: '—') ?></td>
        <td><?= Security::h($p['version']) ?></td>
        <td><?= View::statusBadge($p['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$processes): ?>
      <tr><td colspan="5"><div class="empty-state"><i class="bi bi-diagram-3"></i><p>No processes found.</p><?php if ($hasFilter): ?><a href="/processes">Clear filters</a><?php elseif (Auth::can('process.create')): ?><a href="/processes/create" class="btn btn-sm btn-primary">Create the first process</a><?php endif; ?></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
