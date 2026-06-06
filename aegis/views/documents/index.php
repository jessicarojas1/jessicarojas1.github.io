<?php
$breadcrumbs  = $breadcrumbs  ?? [['Documents', null]];
$statusColors = [
  'draft'=>'var(--text-muted)','under_review'=>'var(--warning)','approved'=>'var(--info)',
  'published'=>'var(--primary-light)','archived'=>'var(--text-muted)','expired'=>'var(--danger)',
];
$classColors = ['public'=>'var(--primary-light)','internal'=>'var(--info)','confidential'=>'var(--warning)','restricted'=>'var(--danger)'];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Documents</h1>
    <p class="page-subtitle">Version-controlled document library with classification and expiry tracking.</p>
  </div>
  <div>
    <a href="/documents/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Document</a>
  </div>
</div>

<!-- Filters -->
<form method="GET" class="filter-bar" style="margin-bottom:16px">
  <input type="text" name="q" class="form-control" placeholder="Search title…" value="<?= Security::h($_GET['q'] ?? '') ?>" style="max-width:240px">
  <select name="status" class="form-control" style="max-width:160px">
    <option value="">All Statuses</option>
    <?php foreach (['draft','under_review','approved','published','archived','expired'] as $s): ?>
      <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="classification" class="form-control" style="max-width:160px">
    <option value="">All Classifications</option>
    <?php foreach (['public','internal','confidential','restricted'] as $c): ?>
      <option value="<?= $c ?>" <?= ($_GET['classification'] ?? '') === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-secondary" type="submit"><i class="bi bi-search"></i></button>
</form>

<?php if (empty($documents)): ?>
  <div class="empty-state-sm"><i class="bi bi-file-earmark-text"></i><p>No documents found. Create your first document to start building the document library.</p></div>
<?php else: ?>
<div class="card">
  <table class="data-table">
    <thead>
      <tr>
        <th>Document</th>
        <th>Classification</th>
        <th>Status</th>
        <th>Version</th>
        <th>Owner</th>
        <th>Next Review</th>
        <th>Expiry</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($documents as $doc):
        $expColor = '';
        if ($doc['expiry_date']) {
          $days = (strtotime($doc['expiry_date']) - time()) / 86400;
          $expColor = $days < 0 ? 'color:var(--danger);font-weight:600' : ($days < 30 ? 'color:var(--warning);font-weight:600' : '');
        }
      ?>
        <tr>
          <td>
            <div class="fw-600"><a href="/documents/<?= (int)$doc['id'] ?>" class="text-link"><?= Security::h($doc['title']) ?></a></div>
            <?php if ($doc['doc_number']): ?><div class="text-muted text-xs"><?= Security::h($doc['doc_number']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php $cc = $classColors[$doc['classification']] ?? 'var(--text-muted)'; ?>
            <span class="badge" style="background:color-mix(in srgb,<?= $cc ?> 20%,transparent);color:<?= $cc ?>">
              <?= Security::h(ucfirst($doc['classification'])) ?>
            </span>
          </td>
          <td>
            <?php $sc = $statusColors[$doc['status']] ?? 'var(--text-muted)'; ?>
            <span class="badge" style="background:color-mix(in srgb,<?= $sc ?> 20%,transparent);color:<?= $sc ?>">
              <?= Security::h(ucfirst(str_replace('_',' ',$doc['status']))) ?>
            </span>
          </td>
          <td class="text-sm"><?= Security::h($doc['current_version']) ?></td>
          <td class="text-sm"><?= Security::h($doc['owner_name'] ?? '—') ?></td>
          <td class="text-sm text-muted"><?= $doc['next_review_date'] ? date('M j, Y', strtotime($doc['next_review_date'])) : '—' ?></td>
          <td class="text-sm" style="<?= $expColor ?>"><?= $doc['expiry_date'] ? date('M j, Y', strtotime($doc['expiry_date'])) : '—' ?></td>
          <td><a href="/documents/<?= (int)$doc['id'] ?>" class="btn btn-sm btn-secondary">View</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
