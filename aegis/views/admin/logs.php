<?php if (!defined('AEGIS_ROOT')) { http_response_code(403); exit; } ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Activity Logs</h1>
    <p class="page-subtitle">Full audit trail of all user actions</p>
  </div>
  <div class="page-actions">
    <form method="POST" action="/admin/logs/export" style="display:inline">
      <?= Security::csrfField() ?>
      <button type="submit" class="btn btn-secondary"><i class="bi bi-download"></i> Export CSV</button>
    </form>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:1.5rem">
  <div class="stat-card"><div class="stat-value"><?= number_format($stats['total']) ?></div><div class="stat-label">Total Events</div></div>
  <div class="stat-card"><div class="stat-value"><?= number_format($stats['today']) ?></div><div class="stat-label">Today</div></div>
  <div class="stat-card"><div class="stat-value"><?= number_format($stats['week_users']) ?></div><div class="stat-label">Active Users (7d)</div></div>
  <div class="stat-card"><div class="stat-value" style="font-size:.9rem"><?= Security::h($stats['top_action']) ?></div><div class="stat-label">Top Action</div></div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-body">
    <form method="GET" action="/admin/logs" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
      <div class="form-group" style="margin:0;min-width:160px">
        <label class="form-label">User</label>
        <select name="user_id" class="form-control form-control-sm">
          <option value="">All Users</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= ($userId === (int)$u['id']) ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:160px">
        <label class="form-label">Action</label>
        <input type="text" name="action" class="form-control form-control-sm" value="<?= Security::h($action) ?>" placeholder="Filter action…">
      </div>
      <div class="form-group" style="margin:0;min-width:130px">
        <label class="form-label">Entity Type</label>
        <select name="entity_type" class="form-control form-control-sm">
          <option value="">All Types</option>
          <?php foreach ($entityTypes as $et): ?>
            <option value="<?= Security::h($et['entity_type']) ?>" <?= ($entityType === $et['entity_type']) ? 'selected' : '' ?>><?= Security::h($et['entity_type']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:120px">
        <label class="form-label">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= Security::h($dateFrom) ?>">
      </div>
      <div class="form-group" style="margin:0;min-width:120px">
        <label class="form-label">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= Security::h($dateTo) ?>">
      </div>
      <div class="form-group" style="margin:0;min-width:130px">
        <label class="form-label">IP Address</label>
        <input type="text" name="ip" class="form-control form-control-sm" value="<?= Security::h($ipAddr) ?>" placeholder="Filter IP…">
      </div>
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="/admin/logs" class="btn btn-ghost btn-sm">Reset</a>
    </form>
  </div>
</div>

<!-- Log table -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Events <span style="font-weight:400;color:var(--text-muted);font-size:.85rem"><?= number_format($totalRows) ?> total</span></h3>
  </div>
  <?php if (empty($logs)): ?>
    <div class="empty-state"><i class="bi bi-journal-x"></i><h3>No events found</h3><p>Try adjusting your filters</p></div>
  <?php else: ?>
  <div class="table-container">
    <table class="data-table">
      <thead>
        <tr>
          <th>Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Entity</th>
          <th>IP</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td style="white-space:nowrap;font-size:.82rem;color:var(--text-muted)"><?= date('M j, Y H:i:s', strtotime($log['created_at'])) ?></td>
          <td>
            <?php if ($log['user_name']): ?>
              <span style="font-weight:500"><?= Security::h($log['user_name']) ?></span>
              <span style="font-size:.75rem;color:var(--text-muted);display:block"><?= Security::h($log['user_role'] ?? '') ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted)">System</span>
            <?php endif; ?>
          </td>
          <td><code style="font-size:.8rem;background:var(--bg-secondary);padding:.2rem .4rem;border-radius:4px"><?= Security::h($log['action']) ?></code></td>
          <td style="font-size:.85rem">
            <?php if ($log['entity_type']): ?>
              <?= Security::h($log['entity_type']) ?>
              <?php if ($log['entity_id']): ?><span style="color:var(--text-muted)"> #<?= (int)$log['entity_id'] ?></span><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="font-size:.8rem;color:var(--text-muted)"><?= Security::h($log['ip_address'] ?? '—') ?></td>
          <td style="font-size:.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?php if ($log['changes']): ?>
              <span title="<?= Security::h(is_string($log['changes']) ? $log['changes'] : json_encode($log['changes'])) ?>"><?= Security::h(substr(is_string($log['changes']) ? $log['changes'] : json_encode($log['changes']), 0, 60)) ?>…</span>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="card-body" style="border-top:1px solid var(--border-color);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
    <?php
    $qs = http_build_query(array_filter(['user_id'=>$userId,'action'=>$action,'entity_type'=>$entityType,'ip'=>$ipAddr,'from'=>$dateFrom,'to'=>$dateTo]));
    $qs = $qs ? '&'.$qs : '';
    ?>
    <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?><?= $qs ?>" class="btn btn-ghost btn-sm">← Prev</a><?php endif; ?>
    <span style="font-size:.85rem;color:var(--text-muted)">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?><?= $qs ?>" class="btn btn-ghost btn-sm">Next →</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
