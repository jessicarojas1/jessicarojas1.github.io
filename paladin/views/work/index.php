<?php
$pageTitle    = 'My Work';
$activeModule = 'work';
$breadcrumbs  = [['My Work', null]];
ob_start();
$overdue = fn($d) => $d && substr((string)$d, 0, 10) < $today;
?>
<div class="page-header">
  <div><h1 class="page-title">My Work</h1><p class="page-subtitle">Everything awaiting your attention, in one place.</p></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-list-task"></i></div><div><div class="stat-value"><?= count($myTasks) ?></div><div class="stat-label">Open tasks</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-check2-square"></i></div><div><div class="stat-value"><?= count($myApprovals) ?></div><div class="stat-label">Approvals to decide</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-clock-history"></i></div><div><div class="stat-value"><?= count($myReviews) ?></div><div class="stat-label">Doc reviews due</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(2,132,199,.12);color:var(--info)"><i class="bi bi-patch-check"></i></div><div><div class="stat-value"><?= count($myAcks) ?></div><div class="stat-label">To acknowledge</div></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px">
  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-list-task"></i> My open tasks</span></div><a href="/tasks?assigned_to=<?= (int)Auth::id() ?>" class="btn btn-sm btn-ghost">All</a></div>
    <div class="card-body" style="padding:0"><table class="table table-hover" style="margin:0"><tbody>
      <?php foreach ($myTasks as $t): ?>
        <tr><td><a href="/tasks/<?= (int)$t['id'] ?>" class="table-link"><?= Security::h($t['title']) ?></a></td>
            <td style="width:90px"><?= View::priorityBadge((string)$t['priority']) ?></td>
            <td class="form-hint" style="width:110px"><?php if ($t['due_date']): ?><span class="<?= $overdue($t['due_date']) ? 'badge badge-overdue' : '' ?>"><?= View::fmtDate($t['due_date']) ?></span><?php else: ?>—<?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$myTasks): ?><tr><td class="empty-row"><div class="empty-state-sm"><i class="bi bi-check2-all"></i><p>No open tasks assigned to you.</p></div></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-check2-square"></i> Approvals awaiting you</span></div><a href="/approvals" class="btn btn-sm btn-ghost">All</a></div>
    <div class="card-body" style="padding:0"><table class="table table-hover" style="margin:0"><tbody>
      <?php foreach ($myApprovals as $a): ?>
        <tr><td><a href="/approvals/<?= (int)$a['id'] ?>" class="table-link"><?= Security::h($a['title']) ?></a></td>
            <td class="form-hint" style="width:130px"><?php if ($a['due_at']): ?><span class="<?= $overdue($a['due_at']) ? 'badge badge-overdue' : '' ?>">due <?= View::fmtDate($a['due_at']) ?></span><?php else: ?>—<?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$myApprovals): ?><tr><td class="empty-row"><div class="empty-state-sm"><i class="bi bi-check2-all"></i><p>Nothing awaiting your decision.</p></div></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-clock-history"></i> My documents due for review</span></div></div>
    <div class="card-body" style="padding:0"><table class="table table-hover" style="margin:0"><tbody>
      <?php foreach ($myReviews as $d): $when = $d['review_date'] ?: $d['expiration_date']; ?>
        <tr><td><span class="chip"><?= Security::h($d['document_code']) ?></span> <a href="/documents/<?= (int)$d['id'] ?>" class="table-link"><?= Security::h($d['title']) ?></a></td>
            <td class="form-hint" style="width:120px"><span class="<?= $overdue($when) ? 'badge badge-overdue' : '' ?>"><?= View::fmtDate($when) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if (!$myReviews): ?><tr><td class="empty-row"><div class="empty-state-sm"><i class="bi bi-check2-all"></i><p>No documents you own are due for review.</p></div></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-patch-check"></i> Documents to acknowledge</span></div></div>
    <div class="card-body" style="padding:0"><table class="table table-hover" style="margin:0"><tbody>
      <?php foreach ($myAcks as $d): ?>
        <tr><td><span class="chip"><?= Security::h($d['document_code']) ?></span> <a href="/documents/<?= (int)$d['id'] ?>" class="table-link"><?= Security::h($d['title']) ?></a></td>
            <td class="form-hint" style="width:80px">rev <?= Security::h($d['revision']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$myAcks): ?><tr><td class="empty-row"><div class="empty-state-sm"><i class="bi bi-check2-all"></i><p>Nothing to acknowledge.</p></div></td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
