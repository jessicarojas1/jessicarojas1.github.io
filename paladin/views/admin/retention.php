<?php
$pageTitle    = 'Retention Rules';
$activeModule = 'admin_retention';
$breadcrumbs  = [['Administration', '/admin'], ['Retention Rules', null]];
ob_start();
$truthy = fn($v) => in_array(strtolower((string)$v), ['1','t','true','yes','on'], true);
$docTypes = ['policy','procedure','process','standard','guideline','work_instruction','plan','form','template','record','evidence','training'];
?>
<div class="page-header">
  <div><h1 class="page-title">Retention Rules</h1><p class="page-subtitle">Automatically archive or flag controlled content that has been inactive past a threshold.</p></div>
</div>

<div class="iam" style="grid-template-columns:1fr 340px">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-clock-history"></i> Rules</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0">
        <thead><tr><th>Name</th><th>Scope</th><th>Age</th><th>Action</th><th>Matches now</th><th>Last run</th><th style="width:150px"></th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
          <tr>
            <td><?= Security::h($r['name']) ?> <?= $truthy($r['is_active']) ? '' : '<span class="badge badge-gray">paused</span>' ?></td>
            <td class="form-hint">
              <?= $r['content_type'] === 'page' ? 'Pages' : 'Documents' ?>
              <?= !empty($r['space_name']) ? ' · ' . Security::h($r['space_name']) : ' · all spaces' ?>
              <?= !empty($r['doc_type']) ? ' · ' . Security::h($r['doc_type']) : '' ?>
            </td>
            <td class="form-hint"><?= (int)$r['age_days'] ?>d</td>
            <td><span class="badge <?= $r['action'] === 'archive' ? 'badge-amber' : 'badge-blue' ?>"><?= $r['action'] === 'archive' ? 'Archive' : 'Notify owner' ?></span></td>
            <td><span class="chip"><?= (int)$r['preview'] ?></span></td>
            <td class="form-hint"><?= $r['last_run_at'] ? Security::h(View::timeAgo($r['last_run_at'])) . ' · ' . (int)$r['last_affected'] : 'never' ?></td>
            <td style="text-align:right;white-space:nowrap">
              <form method="POST" action="/admin/retention/<?= (int)$r['id'] ?>/run" style="display:inline;margin:0" data-confirm="Run this rule now? It will <?= $r['action'] === 'archive' ? 'archive matching content' : 'notify owners' ?>."><?= Security::csrfField() ?><button class="btn btn-sm" type="submit" title="Run now"><i class="bi bi-play-circle"></i></button></form>
              <form method="POST" action="/admin/retention/<?= (int)$r['id'] ?>/toggle" style="display:inline;margin:0"><?= Security::csrfField() ?><button class="btn btn-sm" type="submit" title="<?= $truthy($r['is_active']) ? 'Pause' : 'Resume' ?>"><i class="bi <?= $truthy($r['is_active']) ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button></form>
              <form method="POST" action="/admin/retention/<?= (int)$r['id'] ?>/delete" style="display:inline;margin:0" data-confirm="Delete this rule?"><?= Security::csrfField() ?><button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rules): ?>
          <tr><td colspan="7" class="empty-row"><div class="empty-state-sm"><i class="bi bi-clock-history"></i><p>No retention rules yet.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-plus-lg"></i> New Rule</span></div></div>
    <div class="card-body">
      <form method="POST" action="/admin/retention">
        <?= Security::csrfField() ?>
        <div class="form-group"><label class="form-label" for="rt_name">Name</label><input type="text" id="rt_name" name="name" class="form-control" required maxlength="160" placeholder="e.g. Archive stale procedures"></div>
        <div class="form-group"><label class="form-label" for="rt_type">Content type</label>
          <select id="rt_type" name="content_type" class="form-select"><option value="document">Documents</option><option value="page">Pages</option></select>
        </div>
        <div class="form-group"><label class="form-label" for="rt_space">Space (optional)</label>
          <select id="rt_space" name="space_id" class="form-select"><option value="">All spaces</option>
            <?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>"><?= Security::h($s['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label" for="rt_doctype">Document type (optional)</label>
          <select id="rt_doctype" name="doc_type" class="form-select"><option value="">Any type</option>
            <?php foreach ($docTypes as $dt): ?><option value="<?= $dt ?>"><?= ucwords(str_replace('_', ' ', $dt)) ?></option><?php endforeach; ?>
          </select>
          <div class="form-hint">Applies to Documents only.</div>
        </div>
        <div class="form-group"><label class="form-label" for="rt_age">Inactive for (days)</label><input type="number" id="rt_age" name="age_days" class="form-control" value="365" min="1" max="36500" required></div>
        <div class="form-group"><label class="form-label" for="rt_action">Action</label>
          <select id="rt_action" name="action" class="form-select"><option value="archive">Archive matching content</option><option value="notify">Notify the owner (no change)</option></select>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary btn-full"><i class="bi bi-plus-lg"></i> Add Rule</button></div>
        <div class="form-hint" style="margin-top:10px"><i class="bi bi-info-circle"></i> Archiving sets the item status to <code>archived</code> — reversible, nothing is deleted.</div>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
