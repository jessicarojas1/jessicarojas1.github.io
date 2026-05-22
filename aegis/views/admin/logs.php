<?php
$pageTitle    = 'Activity Logs';
$activeModule = 'admin_logs';
$breadcrumbs  = [['Admin', '/admin'], ['Activity Logs', null]];
ob_start();

// Action → icon/colour map
$actionMeta = [
    'login'              => ['icon' => 'box-arrow-in-right',    'color' => '#22c55e', 'label' => 'Login'],
    'logout'             => ['icon' => 'box-arrow-right',       'color' => '#94a3b8', 'label' => 'Logout'],
    'create_user'        => ['icon' => 'person-plus-fill',      'color' => '#4f46e5', 'label' => 'Create User'],
    'update_user'        => ['icon' => 'person-fill-gear',      'color' => '#0284c7', 'label' => 'Update User'],
    'delete_user'        => ['icon' => 'person-dash-fill',      'color' => '#ef4444', 'label' => 'Delete User'],
    'update_control'     => ['icon' => 'shield-check',          'color' => '#059669', 'label' => 'Update Control'],
    'import_package'     => ['icon' => 'cloud-upload-fill',     'color' => '#7c3aed', 'label' => 'Import Package'],
    'create_api_key'     => ['icon' => 'key-fill',              'color' => '#d97706', 'label' => 'Create API Key'],
    'revoke_api_key'     => ['icon' => 'key',                   'color' => '#ef4444', 'label' => 'Revoke API Key'],
    'create_workflow'    => ['icon' => 'diagram-3-fill',        'color' => '#0284c7', 'label' => 'Create Workflow'],
    'update_risk_matrix' => ['icon' => 'sliders',               'color' => '#d97706', 'label' => 'Update Matrix'],
    'update_permissions' => ['icon' => 'shield-lock-fill',      'color' => '#4f46e5', 'label' => 'Update Permissions'],
];
function getActionMeta(string $action, array $map): array {
    return $map[$action] ?? ['icon' => 'activity', 'color' => '#64748b', 'label' => ucfirst(str_replace('_', ' ', $action))];
}
?>

<!-- Stats row -->
<div class="page-header">
  <h1 class="page-title">Activity Logs</h1>
  <div class="page-actions">
    <form method="POST" action="/admin/logs/export" style="display:inline">
      <?= Security::csrfField() ?>
      <button class="btn btn-ghost" type="submit"><i class="bi bi-download"></i> Export CSV</button>
    </form>
    <label class="btn btn-ghost" style="cursor:pointer;display:flex;align-items:center;gap:6px">
      <input type="checkbox" id="autoRefresh" style="accent-color:#4f46e5"> Auto-refresh
    </label>
  </div>
</div>

<div class="log-stats-row">
  <div class="log-stat">
    <div class="log-stat-icon" style="background:#eef2ff;color:#4f46e5"><i class="bi bi-journal-text"></i></div>
    <div>
      <div class="log-stat-value"><?= number_format($stats['total']) ?></div>
      <div class="log-stat-label">Total Events</div>
    </div>
  </div>
  <div class="log-stat">
    <div class="log-stat-icon" style="background:#dcfce7;color:#059669"><i class="bi bi-calendar-check"></i></div>
    <div>
      <div class="log-stat-value"><?= number_format($stats['today']) ?></div>
      <div class="log-stat-label">Today</div>
    </div>
  </div>
  <div class="log-stat">
    <div class="log-stat-icon" style="background:#dbeafe;color:#0284c7"><i class="bi bi-people-fill"></i></div>
    <div>
      <div class="log-stat-value"><?= $stats['week_users'] ?></div>
      <div class="log-stat-label">Active Users (7d)</div>
    </div>
  </div>
  <div class="log-stat">
    <div class="log-stat-icon" style="background:#fef3c7;color:#d97706"><i class="bi bi-graph-up"></i></div>
    <div>
      <div class="log-stat-value log-stat-action"><?= Security::h($stats['top_action']) ?></div>
      <div class="log-stat-label">Top Action</div>
    </div>
  </div>
</div>

<div class="two-col-layout" style="margin-bottom:20px">

  <!-- Filter panel -->
  <div class="card" style="grid-column:span 2">
    <div class="card-header" id="filterToggle" style="cursor:pointer" onclick="toggleFilter()">
      <h3 class="card-title"><i class="bi bi-funnel-fill"></i> Filters
        <?php
          $activeFilters = array_filter([$_GET['user_id'] ?? '', $_GET['action'] ?? '', $_GET['entity_type'] ?? '', $_GET['ip'] ?? '', $_GET['from'] ?? '', $_GET['to'] ?? '']);
          if ($activeFilters): ?>
          <span class="badge badge-blue" style="margin-left:8px"><?= count($activeFilters) ?> active</span>
        <?php endif; ?>
      </h3>
      <i class="bi bi-chevron-down" id="filterChevron"></i>
    </div>
    <div class="card-body" id="filterBody">
      <form method="GET" action="/admin/logs">
        <div class="filter-grid">
          <div class="form-group">
            <label class="form-label">User</label>
            <select name="user_id" class="form-control">
              <option value="">All users</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= ($_GET['user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Action</label>
            <select name="action" class="form-control">
              <option value="">All actions</option>
              <?php foreach ($actionTypes as $at): ?>
                <option value="<?= Security::h($at['action']) ?>" <?= ($_GET['action'] ?? '') === $at['action'] ? 'selected' : '' ?>><?= Security::h(ucfirst(str_replace('_', ' ', $at['action']))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Entity Type</label>
            <select name="entity_type" class="form-control">
              <option value="">All types</option>
              <?php foreach ($entityTypes as $et): ?>
                <option value="<?= Security::h($et['entity_type']) ?>" <?= ($_GET['entity_type'] ?? '') === $et['entity_type'] ? 'selected' : '' ?>><?= Security::h(ucfirst(str_replace('_', ' ', $et['entity_type']))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">IP Address</label>
            <input type="text" name="ip" class="form-control" value="<?= Security::h($_GET['ip'] ?? '') ?>" placeholder="e.g. 192.168.1.">
          </div>
          <div class="form-group">
            <label class="form-label">Date From</label>
            <input type="date" name="from" class="form-control" value="<?= Security::h($_GET['from'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Date To</label>
            <input type="date" name="to" class="form-control" value="<?= Security::h($_GET['to'] ?? '') ?>">
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:4px">
          <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Apply Filters</button>
          <a href="/admin/logs" class="btn btn-ghost">Clear</a>
        </div>
      </form>
    </div>
  </div>

</div>

<div class="two-col-layout" style="align-items:start">

  <!-- Main log table -->
  <div class="card" style="grid-column:span 2">
    <div class="card-header">
      <h3 class="card-title">
        <i class="bi bi-list-ul"></i> Events
        <span class="text-muted" style="font-weight:400;font-size:13px;margin-left:8px"><?= number_format($totalRows) ?> total<?= $activeFilters ? ' (filtered)' : '' ?></span>
      </h3>
      <span class="text-muted text-sm">Page <?= $page ?> of <?= $totalPages ?></span>
    </div>
    <div class="card-body p0">
      <?php if ($logs): ?>
      <table class="table log-table">
        <thead>
          <tr>
            <th style="width:160px">Timestamp</th>
            <th style="width:160px">User</th>
            <th style="width:160px">Action</th>
            <th style="width:130px">Entity</th>
            <th style="width:100px">IP Address</th>
            <th>Changes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $i => $log):
            $meta = getActionMeta($log['action'], $actionMeta);
            $hasChanges = !empty($log['changes']) && $log['changes'] !== 'null';
            $rowId = 'log-row-' . $log['id'];
          ?>
          <tr class="log-row <?= $hasChanges ? 'log-expandable' : '' ?>" <?= $hasChanges ? "onclick=\"toggleChanges('{$rowId}')\"" : '' ?>>
            <td class="text-sm text-muted" style="white-space:nowrap">
              <div><?= date('M j, Y', strtotime($log['created_at'])) ?></div>
              <div style="font-size:11px"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
            </td>
            <td>
              <?php if ($log['user_name']): ?>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="user-avatar sm" style="background:<?= $meta['color'] ?>22;color:<?= $meta['color'] ?>"><?= strtoupper(substr($log['user_name'], 0, 1)) ?></div>
                  <div>
                    <div style="font-size:13px;font-weight:500"><?= Security::h($log['user_name']) ?></div>
                    <?php if ($log['user_role']): ?><div style="font-size:11px;color:var(--text-muted)"><?= ucfirst(Security::h($log['user_role'])) ?></div><?php endif; ?>
                  </div>
                </div>
              <?php else: ?>
                <span class="text-muted text-sm">System</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="log-action-chip" style="--ac:<?= $meta['color'] ?>">
                <i class="bi bi-<?= $meta['icon'] ?>"></i>
                <?= Security::h($meta['label']) ?>
              </div>
            </td>
            <td class="text-sm">
              <?php if ($log['entity_type']): ?>
                <div style="font-weight:500"><?= Security::h(ucfirst(str_replace('_', ' ', $log['entity_type']))) ?></div>
                <?php if ($log['entity_id']): ?><div class="mono text-muted" style="font-size:11px">#<?= $log['entity_id'] ?></div><?php endif; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="mono text-sm text-muted"><?= Security::h($log['ip_address'] ?? '—') ?></td>
            <td>
              <?php if ($hasChanges): ?>
                <span class="log-changes-toggle" id="<?= $rowId ?>-toggle">
                  <i class="bi bi-chevron-right" id="<?= $rowId ?>-icon"></i>
                  <span class="text-muted text-sm">Show details</span>
                </span>
              <?php else: ?>
                <span class="text-muted text-sm">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($hasChanges):
            $decoded = json_decode($log['changes'], true);
          ?>
          <tr class="log-detail-row" id="<?= $rowId ?>" style="display:none">
            <td colspan="6" style="padding:0">
              <div class="log-changes-body">
                <div class="log-changes-title"><i class="bi bi-braces"></i> Change Details</div>
                <?php if (is_array($decoded)): ?>
                  <table class="log-changes-table">
                    <?php foreach ($decoded as $k => $v): ?>
                      <tr>
                        <td class="log-changes-key"><?= Security::h($k) ?></td>
                        <td class="log-changes-val"><?= Security::h(is_array($v) ? json_encode($v) : (string)$v) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                <?php else: ?>
                  <pre class="log-changes-raw"><?= Security::h($log['changes']) ?></pre>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div class="empty-state" style="padding:60px 0">
          <i class="bi bi-journal-x" style="font-size:40px;color:var(--text-muted)"></i>
          <p>No activity log entries found<?= $activeFilters ? ' matching your filters' : '' ?>.</p>
          <?php if ($activeFilters): ?><a href="/admin/logs" class="btn btn-ghost">Clear Filters</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="card-footer log-pagination">
      <div class="text-muted text-sm">
        Showing <?= number_format(($page - 1) * 50 + 1) ?>–<?= number_format(min($page * 50, $totalRows)) ?> of <?= number_format($totalRows) ?>
      </div>
      <div class="pagination-btns">
        <?php
          $qs = array_filter(['user_id'=>$_GET['user_id']??'','action'=>$_GET['action']??'','entity_type'=>$_GET['entity_type']??'','ip'=>$_GET['ip']??'','from'=>$_GET['from']??'','to'=>$_GET['to']??'']);
          $base = '/admin/logs?' . http_build_query($qs);
          $base .= $qs ? '&' : '?';
        ?>
        <?php if ($page > 1): ?>
          <a href="<?= $base ?>page=1" class="btn btn-ghost btn-sm"><i class="bi bi-chevron-double-left"></i></a>
          <a href="<?= $base ?>page=<?= $page - 1 ?>" class="btn btn-ghost btn-sm"><i class="bi bi-chevron-left"></i></a>
        <?php endif; ?>
        <?php
          $start = max(1, $page - 2);
          $end   = min($totalPages, $page + 2);
          for ($p = $start; $p <= $end; $p++):
        ?>
          <a href="<?= $base ?>page=<?= $p ?>" class="btn btn-ghost btn-sm <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="<?= $base ?>page=<?= $page + 1 ?>" class="btn btn-ghost btn-sm"><i class="bi bi-chevron-right"></i></a>
          <a href="<?= $base ?>page=<?= $totalPages ?>" class="btn btn-ghost btn-sm"><i class="bi bi-chevron-double-right"></i></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- Sidebar stats -->
<div class="two-col-layout" style="margin-top:20px">

  <!-- Top users -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-people-fill"></i> Most Active Users (30d)</h3></div>
    <div class="card-body p0">
      <table class="table">
        <thead><tr><th>User</th><th>Actions</th><th>Share</th></tr></thead>
        <tbody>
          <?php
            $maxU = $topUsers ? max(array_column($topUsers, 'c')) : 1;
            foreach ($topUsers as $tu):
              $pct = $maxU > 0 ? round($tu['c'] / $maxU * 100) : 0;
          ?>
          <tr>
            <td><?= Security::h($tu['name'] ?? 'Unknown') ?></td>
            <td><strong><?= number_format($tu['c']) ?></strong></td>
            <td style="min-width:80px">
              <div class="mini-bar"><div class="mini-bar-fill" style="width:<?= $pct ?>%"></div></div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$topUsers): ?>
            <tr><td colspan="3" class="empty-row">No data yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Action breakdown -->
  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-bar-chart-fill"></i> Action Breakdown</h3></div>
    <div class="card-body p0">
      <table class="table">
        <thead><tr><th>Action</th><th>Count</th><th>Share</th></tr></thead>
        <tbody>
          <?php
            $maxA = $actionBreakdown ? max(array_column($actionBreakdown, 'c')) : 1;
            foreach ($actionBreakdown as $ab):
              $pct = $maxA > 0 ? round($ab['c'] / $maxA * 100) : 0;
              $m   = getActionMeta($ab['action'], $actionMeta);
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <i class="bi bi-<?= $m['icon'] ?>" style="color:<?= $m['color'] ?>;font-size:13px"></i>
                <?= Security::h($m['label']) ?>
              </div>
            </td>
            <td><strong><?= number_format($ab['c']) ?></strong></td>
            <td style="min-width:80px">
              <div class="mini-bar"><div class="mini-bar-fill" style="width:<?= $pct ?>%;background:<?= $m['color'] ?>"></div></div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$actionBreakdown): ?>
            <tr><td colspan="3" class="empty-row">No data yet</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<script>
function toggleFilter() {
  const body = document.getElementById('filterBody');
  const icon = document.getElementById('filterChevron');
  const open = body.style.display !== 'none';
  body.style.display = open ? 'none' : '';
  icon.className = open ? 'bi bi-chevron-right' : 'bi bi-chevron-down';
}

function toggleChanges(rowId) {
  const row  = document.getElementById(rowId);
  const icon = document.getElementById(rowId + '-icon');
  const tip  = document.querySelector('#' + rowId + '-toggle span');
  const open = row.style.display !== 'none';
  row.style.display  = open ? 'none' : 'table-row';
  icon.className     = open ? 'bi bi-chevron-right' : 'bi bi-chevron-down';
  if (tip) tip.textContent = open ? 'Show details' : 'Hide details';
}

// Auto-refresh every 30s when enabled
let refreshTimer = null;
document.getElementById('autoRefresh').addEventListener('change', function() {
  if (this.checked) {
    refreshTimer = setInterval(() => location.reload(), 30000);
  } else {
    clearInterval(refreshTimer);
  }
});

// Collapse filters if no active filters on load
<?php if (!$activeFilters): ?>
document.getElementById('filterBody').style.display = 'none';
document.getElementById('filterChevron').className = 'bi bi-chevron-right';
<?php endif; ?>
</script>

<style>
/* Stats row */
.log-stats-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 20px;
}
@media (max-width: 900px) { .log-stats-row { grid-template-columns: repeat(2, 1fr); } }

.log-stat {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 18px 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: var(--shadow);
}
.log-stat-icon {
  width: 44px; height: 44px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.log-stat-value {
  font-size: 26px; font-weight: 800; line-height: 1;
}
.log-stat-action { font-size: 14px; }
.log-stat-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; margin-top: 4px; }

/* Filter grid */
.filter-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-bottom: 8px;
}
@media (max-width: 900px) { .filter-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .filter-grid { grid-template-columns: 1fr; } }

/* Log table */
.log-table { table-layout: fixed; }
.log-table th { position: sticky; top: 0; z-index: 2; }

.log-row.log-expandable { cursor: pointer; }
.log-row.log-expandable:hover { background: var(--hover-bg, #f8fafc); }

/* Action chip */
.log-action-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 99px;
  font-size: 12px;
  font-weight: 500;
  background: color-mix(in srgb, var(--ac) 12%, transparent);
  color: var(--ac);
}
.log-action-chip i { font-size: 11px; }

/* Changes expand */
.log-changes-toggle {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
}
.log-changes-toggle:hover span { color: var(--text); }

.log-detail-row td { padding: 0 !important; }
.log-changes-body {
  background: #1e1e2e;
  padding: 16px 20px;
  border-top: 2px solid #4f46e5;
}
.log-changes-title {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .5px;
  color: #7c7fba;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.log-changes-table { width: 100%; border-collapse: collapse; }
.log-changes-table tr:not(:last-child) td { border-bottom: 1px solid #2e2e4a; }
.log-changes-key {
  padding: 6px 12px 6px 0;
  font-size: 12px;
  font-weight: 600;
  color: #89b4fa;
  font-family: monospace;
  white-space: nowrap;
  width: 160px;
  vertical-align: top;
}
.log-changes-val {
  padding: 6px 0;
  font-size: 12px;
  color: #cdd6f4;
  font-family: monospace;
  word-break: break-all;
}
.log-changes-raw {
  color: #cdd6f4;
  font-size: 12px;
  font-family: monospace;
  margin: 0;
  white-space: pre-wrap;
}

/* Pagination */
.log-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
  gap: 8px;
}
.pagination-btns { display: flex; align-items: center; gap: 4px; }
.pagination-btns .btn.active {
  background: var(--primary, #4f46e5);
  color: #fff;
  border-color: var(--primary, #4f46e5);
}

/* Mini bar */
.mini-bar {
  height: 6px;
  background: var(--border);
  border-radius: 99px;
  overflow: hidden;
}
.mini-bar-fill {
  height: 100%;
  background: var(--primary, #4f46e5);
  border-radius: 99px;
  transition: width .4s;
}

.mono { font-family: monospace; }
.badge-blue { background:#dbeafe;color:#1e40af; }
</style>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
