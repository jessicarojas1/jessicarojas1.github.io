<?php
$statusLabels = [
    'open'        => ['Open',        'badge-danger'],
    'in_progress' => ['In Progress', 'badge-warning'],
    'closed'      => ['Closed',      'badge-success'],
    'cancelled'   => ['Cancelled',   'badge-secondary'],
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">POA&amp;M Items</h1>
    <p class="page-subtitle">Plans of Action &amp; Milestones — track remediation of non-compliant controls</p>
  </div>
  <button class="btn btn-primary" onclick="document.getElementById('generateModal').style.display='flex'">
    <i class="bi bi-lightning-fill"></i> Generate from Package
  </button>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success" style="background:color-mix(in srgb,var(--success) 15%,transparent);border:1px solid var(--success);color:var(--success);padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?= Security::h($_SESSION['flash_success']) ?>
  </div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert-danger" style="background:color-mix(in srgb,var(--danger) 15%,transparent);border:1px solid var(--danger);color:var(--danger);padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?= Security::h($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<?php if (empty($items)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-list-check" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No POA&amp;M Items</h3>
  <p style="color:var(--text-muted);margin-bottom:20px;">Generate POA&amp;M items from a compliance package or create them manually.</p>
  <button class="btn btn-primary" onclick="document.getElementById('generateModal').style.display='flex'">
    <i class="bi bi-lightning-fill"></i> Generate from Package
  </button>
</div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>POAM #</th>
        <th>Title</th>
        <th>Package</th>
        <th>Owner</th>
        <th>Status</th>
        <th>Scheduled Completion</th>
        <th>Milestones</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $item):
      [$statusLabel, $statusClass] = $statusLabels[$item['status']] ?? ['Unknown', 'badge-secondary'];
      $totalM = (int)$item['total_milestones'];
      $doneM  = (int)$item['completed_milestones'];
    ?>
      <tr>
        <td><strong><?= Security::h($item['poam_number']) ?></strong></td>
        <td><?= Security::h($item['title']) ?></td>
        <td><?= Security::h($item['package_name'] ?? '—') ?></td>
        <td><?= Security::h($item['owner_name'] ?? '—') ?></td>
        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
        <td><?= $item['scheduled_completion'] ? date('M j, Y', strtotime($item['scheduled_completion'])) : '—' ?></td>
        <td>
          <?php if ($totalM > 0): ?>
            <span style="font-size:0.85rem;"><?= $doneM ?>/<?= $totalM ?></span>
            <div style="background:var(--border);border-radius:4px;height:4px;width:60px;margin-top:4px;">
              <div style="background:var(--success);border-radius:4px;height:4px;width:<?= $totalM > 0 ? round($doneM/$totalM*100) : 0 ?>%;"></div>
            </div>
          <?php else: ?>
            <span style="color:var(--text-muted);font-size:0.85rem;">None</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="/poam/<?= (int)$item['id'] ?>" class="btn btn-sm btn-secondary">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Generate Modal -->
<div id="generateModal" style="display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
  <div class="card" style="width:100%;max-width:480px;margin:0 20px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <strong>Generate POA&amp;M from Package</strong>
      <button type="button" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:1.2rem;" onclick="document.getElementById('generateModal').style.display='none'"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card-body">
      <form method="POST" action="/poam/generate">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-group">
          <label class="form-label">Compliance Package</label>
          <select name="package_id" class="form-control" required>
            <option value="">— Select package —</option>
            <?php foreach ($packages as $pkg): ?>
              <option value="<?= (int)$pkg['id'] ?>"><?= Security::h($pkg['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <p style="font-size:0.85rem;color:var(--text-muted);margin-top:8px;">
          Creates POA&amp;M items for all non-compliant and partial controls in the selected package. Existing items will be skipped.
        </p>
        <div style="display:flex;gap:8px;margin-top:16px;">
          <button type="submit" class="btn btn-primary"><i class="bi bi-lightning-fill"></i> Generate</button>
          <button type="button" class="btn btn-secondary" onclick="document.getElementById('generateModal').style.display='none'">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
