<?php
/** Audit Log viewer. In scope: $user,$NONCE,$programs,$programId,$rows,$total,$actions,$action,$offset,$page. */
use Redoubt\Support\Security;

$title = 'Audit Log';
$navActive = 'audit';
$breadcrumbs = ['Home' => '/app', 'Audit Log' => null];
require __DIR__ . '/partials/app_header.php';
$base = '/app/admin/audit?program_id=' . (int) $programId . ($action !== '' ? '&amp;action=' . Security::h($action) : '');
?>
<div class="page-header">
  <h1 class="page-title">Audit Log</h1>
  <form method="get" action="/app/admin/audit" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <select name="program_id" style="width:auto"><?php foreach ($programs as $pid => $label): ?><option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option><?php endforeach; ?></select>
    <select name="action" style="width:auto">
      <option value="">All actions</option>
      <?php foreach ($actions as $a): ?><option value="<?= Security::h($a) ?>"<?= $a === $action ? ' selected' : '' ?>><?= Security::h($a) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Filter</button>
  </form>
</div>

<div class="card">
  <div class="perm-key" style="margin-bottom:8px"><?= (int) $total ?> event<?= $total === 1 ? '' : 's' ?><?= $action !== '' ? ' · action = ' . Security::h($action) : '' ?> · append-only</div>
  <?php if ($rows === []): ?><div class="empty-state-sm">No audit events.</div>
  <?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Target</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="perm-key" style="white-space:nowrap"><?= Security::h((string) $r['at']) ?></td>
          <td><?= Security::h($r['actor'] ?: 'system') ?></td>
          <td><span class="badge b-muted"><?= Security::h((string) $r['action']) ?></span></td>
          <td class="perm-key"><?= Security::h((string) ($r['target'] ?? '')) ?></td>
          <td class="perm-key"><?= Security::h((string) ($r['ip'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div style="display:flex;justify-content:space-between;margin-top:12px">
      <?php if ($offset > 0): ?><a class="btn btn-sm" href="<?= $base ?>&amp;offset=<?= max(0, $offset - $page) ?>">← Newer</a><?php else: ?><span></span><?php endif; ?>
      <?php if ($offset + $page < $total): ?><a class="btn btn-sm" href="<?= $base ?>&amp;offset=<?= $offset + $page ?>">Older →</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
