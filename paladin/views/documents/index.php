<?php
$pageTitle    = 'Documents';
$activeModule = 'documents';
$breadcrumbs  = [['Documents', null]];
ob_start();
$hasFilter = !empty($_GET['type']) || !empty($_GET['status']) || !empty($_GET['space']) || !empty($_GET['q']);
?>
<div class="page-header">
  <div><h1 class="page-title">Controlled Documents</h1><p class="page-subtitle">Policies, procedures, standards, forms &amp; records under document control</p></div>
  <div class="page-actions"><a href="/documents/export?<?= Security::h(http_build_query(['type' => $_GET['type'] ?? '', 'status' => $_GET['status'] ?? '', 'space' => $_GET['space'] ?? '', 'q' => $_GET['q'] ?? ''])) ?>" class="btn btn-ghost"><i class="bi bi-filetype-csv"></i> Export register</a><?php if (Auth::can('document.create')): ?><a href="/documents/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Document</a><?php endif; ?></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-files"></i></div><div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Total</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-patch-check-fill"></i></div><div><div class="stat-value"><?= (int)$stats['published'] ?></div><div class="stat-label">Published</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-value"><?= (int)$stats['in_review'] ?></div><div class="stat-label">In Review</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(100,116,139,.12);color:var(--secondary)"><i class="bi bi-pencil"></i></div><div><div class="stat-value"><?= (int)$stats['draft'] ?></div><div class="stat-label">Draft</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-clock-history"></i></div><div><div class="stat-value"><?= (int)$stats['overdue'] ?></div><div class="stat-label">Overdue Review</div></div></div>
</div>

<div class="card" style="margin:18px 0">
  <div class="card-body">
    <form method="GET" action="/documents" class="form-row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
      <div class="form-group" style="flex:1;min-width:180px;margin:0"><label class="form-label">Search</label><input type="search" name="q" class="form-control" value="<?= Security::h($_GET['q'] ?? '') ?>" placeholder="Title, code, description…"></div>
      <div class="form-group" style="margin:0"><label class="form-label">Type</label><select name="type" class="form-select"><option value="">All</option><?php foreach (View::docTypes() as $t): ?><option value="<?= $t ?>" <?= ($_GET['type'] ?? '')===$t?'selected':'' ?>><?= View::docTypeLabel($t) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">All</option><?php foreach (['draft','in_review','approved','published','rejected','archived','obsolete'] as $st): ?><option value="<?= $st ?>" <?= ($_GET['status'] ?? '')===$st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$st)) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0"><label class="form-label">Space</label><select name="space" class="form-select"><option value="">All</option><?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (($_GET['space'] ?? '')==$s['id'])?'selected':'' ?>><?= Security::h($s['space_key']) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
      <?php if ($hasFilter): ?><a href="/documents" class="btn btn-ghost">Reset</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <?php if (Auth::can('document.edit')):
    $docTags = Database::fetchAll("SELECT id, name FROM tags ORDER BY name");
  ?>
  <form method="POST" action="/documents/bulk" data-bulk-bar hidden style="margin:10px;border:1px solid var(--primary);border-radius:8px;padding:10px;background:var(--bg-secondary)">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" data-bulk-action>
    <input type="hidden" name="doc_ids" data-bulk-ids>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span class="form-hint" style="font-weight:700"><span data-bulk-count>0</span> selected</span>
      <button type="submit" class="btn btn-sm" data-bulk-do="archive"><i class="bi bi-archive"></i> Archive</button>
      <span style="display:flex;gap:4px;align-items:center">
        <select name="tag_id" class="form-select" style="padding:3px 6px;max-width:160px"><option value="">Label…</option><?php foreach ($docTags as $tg): ?><option value="<?= (int)$tg['id'] ?>"><?= Security::h($tg['name']) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-sm" data-bulk-do="label"><i class="bi bi-tag"></i> Apply</button>
      </span>
    </div>
  </form>
  <?php endif; ?>
  <table class="table table-hover" style="margin:0">
    <thead><tr><?php if (Auth::can('document.edit')): ?><th style="width:30px"></th><?php endif; ?><th>Code</th><th>Title</th><th>Type</th><th>Rev</th><th>Owner</th><th>Status</th><th>Review Due</th></tr></thead>
    <tbody>
    <?php foreach ($documents as $d): ?>
      <tr>
        <?php if (Auth::can('document.edit')): ?><td><input type="checkbox" class="pt-check" value="<?= (int)$d['id'] ?>"></td><?php endif; ?>
        <td><span class="chip"><?= Security::h($d['document_code']) ?></span></td>
        <td><a href="/documents/<?= (int)$d['id'] ?>" class="table-link"><?= Security::h($d['title']) ?></a><?php if ($d['requires_ack']): ?> <i class="bi bi-patch-check-fill" title="Acknowledgement required" style="color:var(--info)"></i><?php endif; ?><?php if ($d['checked_out_by']): ?> <i class="bi bi-lock-fill" title="Checked out" style="color:var(--warning)"></i><?php endif; ?></td>
        <td><span class="chip"><?= View::docTypeLabel($d['doc_type']) ?></span></td>
        <td><?= Security::h($d['revision']) ?></td>
        <td class="form-hint"><?= Security::h($d['owner_name'] ?: '—') ?></td>
        <td><?= View::statusBadge($d['status']) ?></td>
        <td class="form-hint"><?php if ($d['review_date']): ?><span class="<?= (strtotime($d['review_date']) < strtotime('today') && $d['status']==='published') ? 'badge badge-overdue' : '' ?>"><?= View::fmtDate($d['review_date']) ?></span><?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$documents): ?>
      <tr><td colspan="7"><div class="empty-state"><i class="bi bi-file-earmark-x"></i><p>No documents found.</p><?php if ($hasFilter): ?><a href="/documents">Clear filters</a><?php elseif (Auth::can('document.create')): ?><a href="/documents/create" class="btn btn-sm btn-primary">Create the first document</a><?php endif; ?></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
