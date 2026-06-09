<?php
$pageTitle    = 'Dashboard';
$activeModule = 'dashboard';
$breadcrumbs  = [['Dashboard', null]];
$u = Auth::user();
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Welcome back, <?= Security::h(explode(' ', $u['name'])[0]) ?></h1>
    <p class="page-subtitle">Your organization's authoritative knowledge &amp; controlled document platform</p>
  </div>
  <div class="page-actions">
    <a href="/documents/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Document</a>
    <a href="/spaces" class="btn btn-ghost"><i class="bi bi-collection"></i> Browse Spaces</a>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-icon" style="background:rgba(37,99,235,.12);color:var(--primary)"><i class="bi bi-file-earmark-text-fill"></i></div><div><div class="stat-value"><?= $stats['documents'] ?></div><div class="stat-label">Documents</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(5,150,105,.12);color:var(--success)"><i class="bi bi-patch-check-fill"></i></div><div><div class="stat-value"><?= $stats['published'] ?></div><div class="stat-label">Published</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(139,92,246,.12);color:var(--purple)"><i class="bi bi-collection-fill"></i></div><div><div class="stat-value"><?= $stats['spaces'] ?></div><div class="stat-label">Spaces</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(2,132,199,.12);color:var(--info)"><i class="bi bi-diagram-3-fill"></i></div><div><div class="stat-value"><?= $stats['processes'] ?></div><div class="stat-label">Processes</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(217,119,6,.12);color:var(--warning)"><i class="bi bi-check2-square"></i></div><div><div class="stat-value"><?= $stats['pending'] ?></div><div class="stat-label">Pending Approvals</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(220,38,38,.12);color:var(--danger)"><i class="bi bi-clock-history"></i></div><div><div class="stat-value"><?= $stats['overdue_rev'] ?></div><div class="stat-label">Overdue Reviews</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(249,115,22,.12);color:var(--orange)"><i class="bi bi-calendar-x-fill"></i></div><div><div class="stat-value"><?= $stats['expiring'] ?></div><div class="stat-label">Expiring (30d)</div></div></div>
  <div class="stat-card"><div class="stat-icon" style="background:rgba(99,102,241,.12);color:var(--indigo)"><i class="bi bi-file-richtext-fill"></i></div><div><div class="stat-value"><?= $stats['pages'] ?></div><div class="stat-label">Pages</div></div></div>
</div>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;margin-top:22px;align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-check2-square"></i> My Pending Approvals</span></div><a href="/approvals" class="btn btn-sm btn-ghost">View all</a></div>
    <div class="card-body" style="padding:0">
      <?php if ($myApprovals): ?>
      <table class="table table-hover" style="margin:0">
        <tbody>
          <?php foreach ($myApprovals as $a): ?>
          <tr>
            <td><a href="/approvals/<?= (int)$a['id'] ?>" class="table-link"><?= Security::h($a['title']) ?></a><div class="form-hint">Step <?= (int)$a['current_step'] ?> · <?= Security::h(ucfirst($a['approval_mode'])) ?></div></td>
            <td style="text-align:right;white-space:nowrap"><?php if (!empty($a['due_at'])): ?><span class="<?= strtotime($a['due_at']) < time() ? 'badge badge-overdue' : 'form-hint' ?>">due <?= View::fmtDate($a['due_at']) ?></span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><div class="empty-state"><i class="bi bi-check-circle"></i><p>No approvals waiting on you. You're all caught up.</p></div><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-list-task"></i> My Tasks</span></div><a href="/tasks" class="btn btn-sm btn-ghost">View all</a></div>
    <div class="card-body" style="padding:0">
      <?php if ($myTasks): ?>
      <table class="table table-hover" style="margin:0">
        <tbody>
          <?php foreach ($myTasks as $t): ?>
          <tr>
            <td><a href="/tasks/<?= (int)$t['id'] ?>" class="table-link"><?= Security::h($t['title']) ?></a><div class="form-hint"><?= View::priorityBadge($t['priority']) ?> <?php if (!empty($t['due_date'])): ?><span class="<?= strtotime($t['due_date']) < strtotime('today') ? 'badge badge-overdue' : '' ?>">due <?= View::fmtDate($t['due_date']) ?></span><?php endif; ?></div></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?><div class="empty-state"><i class="bi bi-check-circle"></i><p>No open tasks assigned to you.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;align-items:start">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-clock-history"></i> Recently Updated</span></div></div>
    <div class="card-body" style="padding:0">
      <table class="table table-hover" style="margin:0">
        <tbody>
          <?php foreach ($recentDocs as $d): ?>
          <tr>
            <td style="width:90px"><span class="chip"><?= Security::h($d['document_code']) ?></span></td>
            <td><a href="/documents/<?= (int)$d['id'] ?>" class="table-link"><?= Security::h($d['title']) ?></a></td>
            <td style="text-align:right"><?= View::statusBadge($d['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$recentDocs): ?><tr><td><div class="empty-state-sm">No documents yet.</div></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-activity"></i> Activity Stream</span></div></div>
    <div class="card-body">
      <ul class="tl">
        <?php foreach ($recent as $r): ?>
        <li>
          <span class="tl-dot"><i class="bi bi-dot"></i></span>
          <div class="tl-title"><?= Security::h($r['user_name'] ?? 'System') ?> <span style="font-weight:400;color:var(--text-muted)"><?= Security::h(str_replace('_', ' ', $r['action'])) ?></span></div>
          <div class="tl-meta"><?= Security::h((string)($r['entity_type'] ?? '')) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?> · <?= View::timeAgo($r['created_at']) ?></div>
        </li>
        <?php endforeach; ?>
        <?php if (!$recent): ?><li><div class="empty-state-sm">No recent activity.</div></li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
