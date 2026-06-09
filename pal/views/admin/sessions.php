<?php
$pageTitle    = 'Sessions';
$activeModule = 'admin_sessions';
$breadcrumbs  = [['Administration', '/admin'], ['Sessions', null]];
ob_start();
?>
<div class="page-header">
  <div><h1 class="page-title">Active Sessions</h1><p class="page-subtitle">Live sign-ins across the platform</p></div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <table class="table table-hover" style="margin:0">
    <thead><tr><th>User</th><th>IP</th><th>User Agent</th><th>Last Seen</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $s): ?>
      <tr>
        <td><?php if (!empty($s['user_name'])): ?><div style="display:flex;align-items:center;gap:10px"><?= View::avatar($s['user_name']) ?><span><?= Security::h($s['user_name']) ?><br><span class="form-hint"><?= Security::h(Auth::roleLabel($s['role'] ?? 'viewer')) ?></span></span></div><?php else: ?><span class="form-hint">unknown</span><?php endif; ?></td>
        <td class="form-hint"><?= Security::h($s['ip_address'] ?: '—') ?></td>
        <td class="form-hint" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= Security::h($s['user_agent'] ?? '') ?>"><?= Security::h(mb_strimwidth((string)($s['user_agent'] ?? '—'), 0, 60, '…')) ?></td>
        <td class="form-hint"><?= Security::h(View::timeAgo($s['last_seen_at'])) ?></td>
        <td style="text-align:right">
          <?php if (!empty($s['user_id'])): ?>
          <form method="POST" action="/admin/sessions/<?= (int)$s['user_id'] ?>/revoke" data-confirm="Revoke all sessions for this user?" style="display:inline;margin:0">
            <?= Security::csrfField() ?>
            <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-circle"></i> Revoke</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sessions): ?>
      <tr><td colspan="5" class="empty-row"><div class="empty-state-sm"><i class="bi bi-hdd-network"></i><p>No active sessions.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
