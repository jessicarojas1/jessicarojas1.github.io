<?php if (!defined('AEGIS_ROOT')) { http_response_code(403); exit; }

$pageTitle    = 'Activity Logs';
$activeModule = 'admin_logs';
$breadcrumbs  = [['Admin', '/admin'], ['Activity Logs', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Activity Logs</h1>
    <p class="page-subtitle">Full audit trail of all user actions</p>
  </div>
  <div class="page-actions">
    <form method="POST" action="/admin/logs/export" style="display:inline">
      <?= Security::csrfField() ?>
      <button type="submit" class="btn btn-ghost"><i class="bi bi-download"></i> Export CSV</button>
    </form>
  </div>
</div>

<!-- KPI Stats Row -->
<div class="stats-grid" style="margin-bottom:1.5rem">

  <div class="stat-card" style="flex-direction:row;align-items:center;gap:16px">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--primary),#818cf8);flex-shrink:0">
      <i class="bi bi-journal-text"></i>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
      <div class="stat-label">Total Events</div>
    </div>
  </div>

  <div class="stat-card" style="flex-direction:row;align-items:center;gap:16px">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--success),#34d399);flex-shrink:0">
      <i class="bi bi-clock"></i>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format((int)$stats['today']) ?></div>
      <div class="stat-label">Today</div>
    </div>
  </div>

  <div class="stat-card" style="flex-direction:row;align-items:center;gap:16px">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--info),#38bdf8);flex-shrink:0">
      <i class="bi bi-people-fill"></i>
    </div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format((int)$stats['week_users']) ?></div>
      <div class="stat-label">Active Users (7d)</div>
    </div>
  </div>

  <div class="stat-card" style="flex-direction:row;align-items:center;gap:16px">
    <div class="stat-icon" style="background:linear-gradient(135deg,var(--warning),#fbbf24);flex-shrink:0">
      <i class="bi bi-lightning-fill"></i>
    </div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1rem;word-break:break-all"><?= Security::h($stats['top_action']) ?></div>
      <div class="stat-label">Top Action</div>
    </div>
  </div>

</div>

<!-- Filter Bar -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-header">
    <h3 class="card-title"><i class="bi bi-funnel-fill"></i> Filters</h3>
  </div>
  <div class="card-body">
    <form method="GET" action="/admin/logs">
      <div class="form-row" style="margin-bottom:12px">
        <div class="form-group" style="min-width:160px">
          <label class="form-label">User</label>
          <select name="user_id" class="form-control form-control-sm">
            <option value="">All Users</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= ($userId === (int)$u['id']) ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="min-width:160px">
          <label class="form-label">Action</label>
          <input type="text" name="action" class="form-control form-control-sm" value="<?= Security::h($action) ?>" placeholder="e.g. login, update…">
        </div>
        <div class="form-group" style="min-width:140px">
          <label class="form-label">Entity Type</label>
          <select name="entity_type" class="form-control form-control-sm">
            <option value="">All Types</option>
            <?php foreach ($entityTypes as $et): ?>
              <option value="<?= Security::h($et['entity_type']) ?>" <?= ($entityType === $et['entity_type']) ? 'selected' : '' ?>><?= Security::h($et['entity_type']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row" style="margin-bottom:16px">
        <div class="form-group" style="min-width:140px">
          <label class="form-label">From Date</label>
          <input type="date" name="from" class="form-control form-control-sm" value="<?= Security::h($dateFrom) ?>">
        </div>
        <div class="form-group" style="min-width:140px">
          <label class="form-label">To Date</label>
          <input type="date" name="to" class="form-control form-control-sm" value="<?= Security::h($dateTo) ?>">
        </div>
        <div class="form-group" style="min-width:150px">
          <label class="form-label">IP Address</label>
          <input type="text" name="ip" class="form-control form-control-sm" value="<?= Security::h($ipAddr) ?>" placeholder="e.g. 192.168.1.1">
        </div>
        <div class="form-group" style="justify-content:flex-end;min-width:auto">
          <label class="form-label" style="visibility:hidden">Action</label>
          <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filter</button>
            <a href="/admin/logs" class="btn btn-ghost btn-sm"><i class="bi bi-x-circle"></i> Reset</a>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Log Table -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title">
      <i class="bi bi-journal-text"></i>
      Events
      <span style="font-weight:400;color:var(--text-muted);font-size:.85rem;margin-left:4px"><?= number_format($totalRows) ?> total</span>
    </h3>
    <?php if ($totalPages > 1): ?>
      <span style="font-size:.82rem;color:var(--text-muted)">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php endif; ?>
  </div>

  <?php if (empty($logs)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="bi bi-journal-x"></i></div>
      <h3>No events found</h3>
      <p>Try adjusting your filters or broadening your date range.</p>
      <a href="/admin/logs" class="btn btn-ghost btn-sm">Clear Filters</a>
    </div>
  <?php else: ?>

  <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;border-collapse:collapse">
      <thead>
        <tr>
          <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);background:var(--bg-subtle);border-bottom:1px solid var(--border);white-space:nowrap">Time</th>
          <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);background:var(--bg-subtle);border-bottom:1px solid var(--border);white-space:nowrap">User</th>
          <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);background:var(--bg-subtle);border-bottom:1px solid var(--border);white-space:nowrap">Action</th>
          <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);background:var(--bg-subtle);border-bottom:1px solid var(--border);white-space:nowrap">Entity</th>
          <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);background:var(--bg-subtle);border-bottom:1px solid var(--border);white-space:nowrap">IP Address</th>
          <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);background:var(--bg-subtle);border-bottom:1px solid var(--border)">Details</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr style="border-bottom:1px solid var(--border-light)">

          <!-- Time -->
          <td style="padding:12px 16px;vertical-align:middle;white-space:nowrap">
            <span style="font-size:.82rem;color:var(--text-muted)"><?= date('M j, Y', strtotime($log['created_at'])) ?></span>
            <span style="display:block;font-size:.78rem;color:var(--text-light)"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
          </td>

          <!-- User -->
          <td style="padding:12px 16px;vertical-align:middle">
            <?php if ($log['user_name']): ?>
              <div style="display:flex;align-items:center;gap:8px">
                <div class="user-avatar sm" style="flex-shrink:0"><?= strtoupper(substr($log['user_name'], 0, 1)) ?></div>
                <div>
                  <div style="font-weight:500;font-size:.88rem"><?= Security::h($log['user_name']) ?></div>
                  <?php if (!empty($log['user_role'])): ?>
                    <div style="font-size:.75rem;color:var(--text-muted)"><?= Security::h(ucfirst($log['user_role'])) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php else: ?>
              <span style="display:flex;align-items:center;gap:6px;color:var(--text-muted);font-size:.85rem">
                <i class="bi bi-gear-fill" style="font-size:.9rem"></i> System
              </span>
            <?php endif; ?>
          </td>

          <!-- Action chip -->
          <td style="padding:12px 16px;vertical-align:middle">
            <code style="font-size:.78rem;background:var(--bg-secondary,var(--bg-subtle));border:1px solid var(--border);padding:.25rem .5rem;border-radius:6px;color:var(--text);font-family:monospace;white-space:nowrap"><?= Security::h($log['action']) ?></code>
          </td>

          <!-- Entity -->
          <td style="padding:12px 16px;vertical-align:middle;font-size:.85rem">
            <?php if ($log['entity_type']): ?>
              <span style="font-weight:500"><?= Security::h($log['entity_type']) ?></span>
              <?php if ($log['entity_id']): ?>
                <span style="color:var(--text-muted);font-size:.78rem"> #<?= (int)$log['entity_id'] ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--text-light)">—</span>
            <?php endif; ?>
          </td>

          <!-- IP Address -->
          <td style="padding:12px 16px;vertical-align:middle">
            <span style="font-size:.8rem;font-family:monospace;color:var(--text-muted)"><?= Security::h($log['ip_address'] ?? '—') ?></span>
          </td>

          <!-- Details (truncated) -->
          <td style="padding:12px 16px;vertical-align:middle;max-width:240px">
            <?php
              $rawChanges = $log['changes'] ?? null;
              if ($rawChanges):
                $changesStr = is_string($rawChanges) ? $rawChanges : json_encode($rawChanges);
                $preview    = mb_substr($changesStr, 0, 80);
                $hasMore    = mb_strlen($changesStr) > 80;
            ?>
              <span title="<?= Security::h($changesStr) ?>"
                    style="font-size:.8rem;color:var(--text-muted);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= Security::h($preview) ?><?= $hasMore ? '…' : '' ?>
              </span>
            <?php else: ?>
              <span style="color:var(--text-light);font-size:.82rem">—</span>
            <?php endif; ?>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <?php
    $qs = http_build_query(array_filter([
      'user_id'     => $userId,
      'action'      => $action,
      'entity_type' => $entityType,
      'ip'          => $ipAddr,
      'from'        => $dateFrom,
      'to'          => $dateTo,
    ]));
    $qs = $qs ? '&' . $qs : '';
  ?>
  <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div style="display:flex;align-items:center;gap:6px">
      <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?><?= $qs ?>" class="btn btn-ghost btn-sm"><i class="bi bi-chevron-left"></i> Prev</a>
      <?php else: ?>
        <button class="btn btn-ghost btn-sm" disabled style="opacity:.4"><i class="bi bi-chevron-left"></i> Prev</button>
      <?php endif; ?>

      <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        for ($p = $start; $p <= $end; $p++):
      ?>
        <?php if ($p === $page): ?>
          <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;background:var(--primary);color:var(--card-bg);border-radius:6px;font-size:.82rem;font-weight:600"><?= $p ?></span>
        <?php else: ?>
          <a href="?page=<?= $p ?><?= $qs ?>" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:1px solid var(--border);border-radius:6px;font-size:.82rem;color:var(--text-muted);text-decoration:none" class="btn btn-ghost btn-sm" style="width:32px;height:32px;padding:0;justify-content:center"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?><?= $qs ?>" class="btn btn-ghost btn-sm">Next <i class="bi bi-chevron-right"></i></a>
      <?php else: ?>
        <button class="btn btn-ghost btn-sm" disabled style="opacity:.4">Next <i class="bi bi-chevron-right"></i></button>
      <?php endif; ?>
    </div>
    <span style="font-size:.82rem;color:var(--text-muted)">
      Showing <?= number_format(($page - 1) * 50 + 1) ?>–<?= number_format(min($page * 50, $totalRows)) ?> of <?= number_format($totalRows) ?> events
    </span>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
