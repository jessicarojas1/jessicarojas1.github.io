<?php
$breadcrumbs = $breadcrumbs ?? [['CUI Inventory', null]];
$csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">CUI Inventory</h1>
    <p class="page-subtitle">Track where Controlled Unclassified Information lives across your environment</p>
  </div>
  <a href="/cui/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New CUI Record</a>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
  <div class="card" style="text-align:center;padding:20px;">
    <div style="font-size:2rem;font-weight:700;color:var(--primary);"><?= $stats['total'] ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">Total Records</div>
  </div>
  <div class="card" style="text-align:center;padding:20px;">
    <div style="font-size:2rem;font-weight:700;color:var(--success);"><?= $stats['encrypted'] ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">Encrypted</div>
  </div>
  <div class="card" style="text-align:center;padding:20px;">
    <div style="font-size:2rem;font-weight:700;color:var(--warning);"><?= $stats['categories'] ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">CUI Categories</div>
  </div>
</div>

<?php if (empty($items)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-lock-fill" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No CUI Records</h3>
  <p style="color:var(--text-muted);margin-bottom:20px;">Document where Controlled Unclassified Information is stored, processed, or transmitted.</p>
  <a href="/cui/create" class="btn btn-primary">Add First Record</a>
</div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th scope="col">#</th>
        <th scope="col">Description</th>
        <th scope="col">CUI Category</th>
        <th scope="col">System / Asset</th>
        <th scope="col">Storage</th>
        <th scope="col">Encrypted</th>
        <th scope="col">Owner</th>
        <th scope="col"></th>
      </tr>
    </thead>
    <tbody>
    <?php
    $storageIcons = ['database'=>'bi-database','file_share'=>'bi-folder2','cloud'=>'bi-cloud','email'=>'bi-envelope','paper'=>'bi-file-text','other'=>'bi-question-circle'];
    foreach ($items as $item):
    ?>
      <tr>
        <td style="font-weight:600;font-family:monospace;"><?= Security::h($item['inventory_number']) ?></td>
        <td><?= Security::h(mb_substr($item['data_description'], 0, 70)) ?><?= mb_strlen($item['data_description']) > 70 ? '…' : '' ?></td>
        <td><?= Security::h($item['cui_category'] ?: '—') ?></td>
        <td><?= Security::h($item['asset_name'] ?? $item['system_name'] ?? '—') ?></td>
        <td><i class="bi <?= $storageIcons[$item['storage_type']] ?? 'bi-question-circle' ?>"></i> <?= ucfirst(str_replace('_', ' ', $item['storage_type'])) ?></td>
        <td>
          <?php if ($item['is_encrypted']): ?>
            <span class="badge badge-success"><i class="bi bi-shield-check"></i> Yes</span>
          <?php else: ?>
            <span class="badge badge-danger"><i class="bi bi-shield-x"></i> No</span>
          <?php endif; ?>
        </td>
        <td><?= Security::h($item['data_owner'] ?: '—') ?></td>
        <td><a href="/cui/<?= (int)$item['id'] ?>" class="btn btn-sm btn-secondary">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
