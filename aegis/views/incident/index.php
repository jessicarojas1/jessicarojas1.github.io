<?php
$pageTitle    = 'Incident Management';
$activeModule = 'incident';
$breadcrumbs  = [['Incident Management', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Incident Management</h1>
    <p class="page-subtitle">Track, investigate, and resolve security incidents</p>
  </div>
  <div class="page-actions">
    <a href="/incident/create" class="btn btn-danger"><i class="bi bi-plus-lg"></i> New Incident</a>
  </div>
</div>

<!-- Stats -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon" style="color:#6366f1"><i class="bi bi-exclamation-circle-fill"></i></div>
    <div class="stat-value"><?= (int)($summary['total'] ?? 0) ?></div>
    <div class="stat-label">Total Incidents</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:#dc2626"><i class="bi bi-circle-fill"></i></div>
    <div class="stat-value"><?= (int)($summary['open'] ?? 0) ?></div>
    <div class="stat-label">Open</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:#d97706"><i class="bi bi-search"></i></div>
    <div class="stat-value"><?= (int)($summary['investigating'] ?? 0) ?></div>
    <div class="stat-label">Investigating</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:#0284c7"><i class="bi bi-shield-fill-check"></i></div>
    <div class="stat-value"><?= (int)($summary['contained'] ?? 0) ?></div>
    <div class="stat-label">Contained</div>
  </div>
  <div class="stat-card">
    <div class="stat-icon" style="color:#059669"><i class="bi bi-check-circle-fill"></i></div>
    <div class="stat-value"><?= (int)($summary['resolved'] ?? 0) ?></div>
    <div class="stat-label">Resolved / Closed</div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:1rem">
  <div class="card-body" style="padding:0.75rem 1rem">
    <form method="GET" action="/incident" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap">
      <select name="severity" class="form-control" style="width:auto;min-width:140px" data-autosubmit>
        <option value="">All severities</option>
        <?php foreach (['critical'=>'Critical','high'=>'High','medium'=>'Medium','low'=>'Low'] as $val => $label): ?>
          <option value="<?= $val ?>" <?= ($_GET['severity'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="form-control" style="width:auto;min-width:150px" data-autosubmit>
        <option value="">All statuses</option>
        <?php foreach (['open'=>'Open','investigating'=>'Investigating','contained'=>'Contained','resolved'=>'Resolved','closed'=>'Closed'] as $val => $label): ?>
          <option value="<?= $val ?>" <?= ($_GET['status'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="search" class="form-control" placeholder="Search incidents..." value="<?= Security::h($_GET['search'] ?? '') ?>" style="flex:1;min-width:180px">
      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <a href="/incident" class="btn btn-ghost btn-sm">Clear</a>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Number</th>
          <th>Title</th>
          <th>Severity</th>
          <th>Category</th>
          <th>Status</th>
          <th>Assigned To</th>
          <th>Detected</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($incidents): foreach ($incidents as $inc):
          $sevColors = [
            'critical' => '#dc2626',
            'high'     => '#d97706',
            'medium'   => '#0284c7',
            'low'      => '#059669',
          ];
          $sev = $inc['severity'] ?? 'medium';
          $sevColor = $sevColors[$sev] ?? '#6b7280';
          $statusLabels = [
            'open'          => 'Open',
            'investigating' => 'Investigating',
            'contained'     => 'Contained',
            'resolved'      => 'Resolved',
            'closed'        => 'Closed',
          ];
          $statusColors = [
            'open'          => '#dc2626',
            'investigating' => '#d97706',
            'contained'     => '#0284c7',
            'resolved'      => '#059669',
            'closed'        => '#6b7280',
          ];
          $st = $inc['status'] ?? 'open';
          $stColor = $statusColors[$st] ?? '#6b7280';
          $detectedAt = $inc['detected_at'] ? date('M j, Y g:ia', strtotime($inc['detected_at'])) : '—';
        ?>
          <tr>
            <td><span style="font-family:monospace;font-size:0.85rem;font-weight:600"><?= Security::h($inc['incident_number']) ?></span></td>
            <td><a href="/incident/<?= (int)$inc['id'] ?>" style="font-weight:500;text-decoration:none;color:inherit"><?= Security::h($inc['title']) ?></a></td>
            <td>
              <span class="status-chip" style="background:<?= $sevColor ?>20;color:<?= $sevColor ?>;border:1px solid <?= $sevColor ?>40;padding:2px 10px;border-radius:99px;font-size:0.78rem;font-weight:600;text-transform:uppercase;letter-spacing:0.04em">
                <?= Security::h(ucfirst($sev)) ?>
              </span>
            </td>
            <td><?= Security::h($inc['category'] ?? '—') ?></td>
            <td>
              <span class="status-chip" style="background:<?= $stColor ?>20;color:<?= $stColor ?>;border:1px solid <?= $stColor ?>40;padding:2px 10px;border-radius:99px;font-size:0.78rem;font-weight:500">
                <?= Security::h($statusLabels[$st] ?? ucfirst($st)) ?>
              </span>
            </td>
            <td><?= Security::h($inc['assigned_to_name'] ?? '—') ?></td>
            <td style="font-size:0.85rem;color:var(--text-muted)"><?= $detectedAt ?></td>
            <td>
              <a href="/incident/<?= (int)$inc['id'] ?>" class="btn btn-ghost btn-sm" title="View"><i class="bi bi-eye"></i></a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="8" style="text-align:center;padding:3rem 1rem">
              <div style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;color:var(--text-muted)">
                <i class="bi bi-shield-check" style="font-size:2.5rem"></i>
                <p style="margin:0;font-size:1rem">No incidents found.
                  <?php if (empty($_GET['severity']) && empty($_GET['status']) && empty($_GET['search'])): ?>
                    <a href="/incident/create">Report an incident</a>.
                  <?php else: ?>
                    <a href="/incident">Clear filters</a>.
                  <?php endif; ?>
                </p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
