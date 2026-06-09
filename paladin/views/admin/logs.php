<?php
$pageTitle    = 'Activity Logs';
$activeModule = 'admin_logs';
$breadcrumbs  = [['Administration', '/admin'], ['Activity Logs', null]];
ob_start();
$qs = function (array $over) {
    $base = ['action' => $_GET['action'] ?? '', 'user_id' => $_GET['user_id'] ?? '', 'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? 1];
    return '/admin/logs?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null));
};
?>
<div class="page-header">
  <div><h1 class="page-title">Activity Logs</h1><p class="page-subtitle">Immutable, hash-chained audit trail</p></div>
</div>

<div class="alert-box success" style="margin-bottom:14px"><i class="bi bi-link-45deg"></i> This log is <strong>hash-chained and immutable</strong> — each entry's hash includes the previous entry, so tampering is detectable.</div>

<div class="card" style="margin-bottom:18px">
  <div class="card-body">
    <form method="GET" action="/admin/logs" class="form-row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
      <div class="form-group" style="flex:1;min-width:180px;margin:0"><label class="form-label">Search</label><input type="search" name="q" class="form-control" value="<?= Security::h($_GET['q'] ?? '') ?>" placeholder="Action or entity type…"></div>
      <div class="form-group" style="margin:0"><label class="form-label">Action</label><select name="action" class="form-select"><option value="">All</option><?php foreach ($actions as $a): ?><option value="<?= Security::h($a) ?>" <?= ($_GET['action'] ?? '')===$a?'selected':'' ?>><?= Security::h($a) ?></option><?php endforeach; ?></select></div>
      <div class="form-group" style="margin:0"><label class="form-label">User</label><select name="user_id" class="form-select"><option value="">All</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (($_GET['user_id'] ?? '')==$u['id'])?'selected':'' ?>><?= Security::h($u['name']) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
      <a href="/admin/logs" class="btn btn-ghost">Reset</a>
    </form>
  </div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td class="form-hint"><?= Security::h(View::fmtDate($l['created_at'], 'M j, Y H:i')) ?></td>
        <td><?= Security::h($l['user_name'] ?: 'system') ?></td>
        <td><span class="chip"><?= Security::h($l['action']) ?></span></td>
        <td class="form-hint"><?= $l['entity_type'] ? Security::h($l['entity_type']) . ($l['entity_id'] ? ' #' . (int)$l['entity_id'] : '') : '—' ?></td>
        <td class="form-hint"><?= Security::h($l['ip_address'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$logs): ?>
      <tr><td colspan="5" class="empty-row"><div class="empty-state-sm"><i class="bi bi-journal-x"></i><p>No activity matches your filters.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php if ($pages > 1): ?>
<div class="form-row" style="justify-content:space-between;align-items:center;margin-top:16px">
  <span class="form-hint">Page <?= (int)$page ?> of <?= (int)$pages ?></span>
  <div style="display:flex;gap:8px">
    <?php if ($page > 1): ?><a href="<?= Security::h($qs(['page' => $page - 1])) ?>" class="btn btn-sm btn-ghost"><i class="bi bi-chevron-left"></i> Prev</a><?php endif; ?>
    <?php if ($page < $pages): ?><a href="<?= Security::h($qs(['page' => $page + 1])) ?>" class="btn btn-sm btn-ghost">Next <i class="bi bi-chevron-right"></i></a><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
