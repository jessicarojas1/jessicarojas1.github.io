<?php
$pageTitle    = $pageTitle    ?? 'Awareness Training';
$activeModule = $activeModule ?? 'awareness';
$breadcrumbs  = $breadcrumbs  ?? [['Training', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Awareness Training</h1>
    <p class="page-subtitle">Security awareness programs and completion tracking</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('compliance.write')): ?>
    <a href="/awareness/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Program</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div class="card">
  <div class="card-body" style="padding:0">
    <?php if ($programs): ?>
    <table class="table">
      <thead>
        <tr>
          <th>Program</th>
          <th>Type</th>
          <th>Due Date</th>
          <th>Progress</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($programs as $p):
          $total     = (int)$p['total_assigned'];
          $completed = (int)$p['completed_count'];
          $pct       = $total > 0 ? round(($completed / $total) * 100) : 0;
          $color     = $pct >= 80 ? '#059669' : ($pct >= 50 ? '#d97706' : '#dc2626');
          $typeIcons = ['document'=>'file-earmark-text','video'=>'play-btn-fill','policy'=>'file-earmark-check','quiz'=>'patch-question-fill'];
          $icon = $typeIcons[$p['content_type']] ?? 'book';
        ?>
        <tr>
          <td>
            <a href="/awareness/<?= (int)$p['id'] ?>" style="font-weight:600;color:var(--primary);text-decoration:none">
              <?= Security::h($p['title']) ?>
            </a>
            <?php if ($p['description']): ?>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= Security::h(substr($p['description'],0,80)) ?>…</div>
            <?php endif; ?>
          </td>
          <td><i class="bi bi-<?= $icon ?>" style="color:var(--primary)"></i> <?= ucfirst(Security::h($p['content_type'])) ?></td>
          <td><?= $p['due_date'] ? date('M j, Y', strtotime($p['due_date'])) : '<span style="color:var(--text-muted)">—</span>' ?></td>
          <td style="min-width:160px">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden">
                <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:3px"></div>
              </div>
              <span style="font-size:12px;color:<?= $color ?>;font-weight:600;white-space:nowrap"><?= $completed ?>/<?= $total ?></span>
            </div>
          </td>
          <td><span class="badge badge-<?= $p['status'] === 'active' ? 'green' : 'gray' ?>"><?= ucfirst(Security::h($p['status'])) ?></span></td>
          <td class="text-right">
            <a href="/awareness/<?= (int)$p['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state-sm"><i class="bi bi-mortarboard"></i><p>No awareness programs. Create your first security awareness training program to track completion across your team.</p></div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
