<?php
$pageTitle    = 'Acknowledgement Campaigns';
$activeModule = 'campaigns';
$breadcrumbs  = [['Acknowledgement Campaigns', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Acknowledgement Campaigns</h1><p class="page-subtitle">Drive and track read-and-understand sign-off on controlled documents.</p></div>
  <div class="page-header-actions"><a href="/campaigns/create" class="btn btn-primary"><i class="bi bi-megaphone-fill"></i> New Campaign</a></div>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <table class="table table-hover" style="margin:0">
      <thead><tr><th>Campaign</th><th>Document</th><th>Due</th><th style="width:220px">Progress</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($campaigns as $c):
        $total = (int)$c['target_count']; $done = (int)$c['done_count'];
        $pct = $total > 0 ? (int)round($done / $total * 100) : 0;
        $overdue = $c['due_date'] && $c['status'] === 'active' && strtotime($c['due_date']) < time() && $done < $total;
      ?>
        <tr>
          <td><a href="/campaigns/<?= (int)$c['id'] ?>"><?= Security::h($c['title']) ?></a></td>
          <td class="form-hint"><span class="chip"><?= Security::h($c['document_code']) ?></span> <?= Security::h($c['doc_title']) ?> <span class="badge badge-gray">rev <?= Security::h($c['revision']) ?></span></td>
          <td class="form-hint"><?= $c['due_date'] ? Security::h(View::fmtDate($c['due_date'])) : '—' ?><?php if ($overdue): ?> <span class="badge badge-red">overdue</span><?php endif; ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:8px;border-radius:6px;background:var(--border-light);overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:var(--success)"></div>
              </div>
              <span class="form-hint" style="white-space:nowrap"><?= $done ?>/<?= $total ?></span>
            </div>
          </td>
          <td><?= $c['status'] === 'active' ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Closed</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$campaigns): ?>
        <tr><td colspan="5" class="empty-row"><div class="empty-state-sm"><i class="bi bi-megaphone"></i><p>No campaigns yet. Launch one to track document sign-off.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
