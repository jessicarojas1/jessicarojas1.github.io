<?php
$breadcrumbs = [['POA&M', '/poam'], ['Item', null]];
$statusLabels = [
    'open'        => ['Open',        'badge-danger'],
    'in_progress' => ['In Progress', 'badge-warning'],
    'closed'      => ['Closed',      'badge-success'],
    'cancelled'   => ['Cancelled',   'badge-secondary'],
];
[$statusLabel, $statusClass] = $statusLabels[$item['status']] ?? ['Unknown', 'badge-secondary'];
$totalMilestones     = count($milestones);
$completedMilestones = count(array_filter($milestones, fn($m) => $m['is_complete']));
?>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div style="background:color-mix(in srgb,var(--success) 15%,transparent);border:1px solid var(--success);color:var(--success);padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?= Security::h($_SESSION['flash_success']) ?>
  </div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div style="background:color-mix(in srgb,var(--danger) 15%,transparent);border:1px solid var(--danger);color:var(--danger);padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?= Security::h($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($item['poam_number']) ?></h1>
    <p class="page-subtitle"><?= Security::h($item['title']) ?></p>
  </div>
  <span class="badge <?= $statusClass ?>" style="font-size:0.9rem;padding:6px 14px;"><?= $statusLabel ?></span>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">

  <!-- Left: details + milestones -->
  <div>
    <!-- Details Card -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><strong>POA&amp;M Details</strong></div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;">
          <tr>
            <td style="padding:8px 0;color:var(--text-muted);width:180px;">POAM Number</td>
            <td><strong><?= Security::h($item['poam_number']) ?></strong></td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:var(--text-muted);">Package</td>
            <td><?= Security::h($item['package_name'] ?? '—') ?></td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:var(--text-muted);">Owner</td>
            <td><?= Security::h($item['owner_name'] ?? '—') ?></td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:var(--text-muted);">Scheduled Completion</td>
            <td><?= $item['scheduled_completion'] ? date('M j, Y', strtotime($item['scheduled_completion'])) : '—' ?></td>
          </tr>
          <tr>
            <td style="padding:8px 0;color:var(--text-muted);">Created</td>
            <td><?= $item['created_at'] ? date('M j, Y', strtotime($item['created_at'])) : '—' ?></td>
          </tr>
          <?php if ($item['weakness_description']): ?>
          <tr>
            <td style="padding:8px 0;color:var(--text-muted);vertical-align:top;">Weakness</td>
            <td><?= nl2br(Security::h($item['weakness_description'])) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($item['resource_requirements']): ?>
          <tr>
            <td style="padding:8px 0;color:var(--text-muted);vertical-align:top;">Resources Required</td>
            <td><?= nl2br(Security::h($item['resource_requirements'])) ?></td>
          </tr>
          <?php endif; ?>
        </table>

        <?php if ($control): ?>
        <hr style="border:none;border-top:1px solid var(--border);margin:16px 0;">
        <div style="font-size:0.85rem;color:var(--text-muted);margin-bottom:4px;">Linked Control</div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="badge badge-info"><?= Security::h($control['code']) ?></span>
          <span><?= Security::h($control['title']) ?></span>
          <?php if ($control['status']): ?>
            <span class="badge <?= $control['status'] === 'non_compliant' ? 'badge-danger' : 'badge-warning' ?>"><?= Security::h(str_replace('_', ' ', $control['status'])) ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Milestones Card -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <strong>Milestones</strong>
        <?php if ($totalMilestones > 0): ?>
          <span style="font-size:0.85rem;color:var(--text-muted);"><?= $completedMilestones ?>/<?= $totalMilestones ?> complete</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (empty($milestones)): ?>
          <p style="color:var(--text-muted);text-align:center;padding:20px 0;">No milestones yet.</p>
        <?php else: ?>
          <?php foreach ($milestones as $ms): ?>
          <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
            <div style="flex-shrink:0;margin-top:2px;">
              <?php if ($ms['is_complete']): ?>
                <i class="bi bi-check-circle-fill" style="color:var(--success);font-size:1.1rem;"></i>
              <?php else: ?>
                <i class="bi bi-circle" style="color:var(--text-muted);font-size:1.1rem;"></i>
              <?php endif; ?>
            </div>
            <div style="flex:1;">
              <div style="<?= $ms['is_complete'] ? 'text-decoration:line-through;color:var(--text-muted);' : '' ?>">
                <?= Security::h($ms['description']) ?>
              </div>
              <?php if ($ms['due_date']): ?>
                <div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;">
                  Due: <?= date('M j, Y', strtotime($ms['due_date'])) ?>
                  <?php if (!$ms['is_complete'] && strtotime($ms['due_date']) < time()): ?>
                    <span class="badge badge-danger" style="font-size:0.7rem;">Overdue</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if ($ms['is_complete'] && $ms['completed_at']): ?>
                <div style="font-size:0.8rem;color:var(--success);margin-top:2px;">
                  Completed <?= date('M j, Y', strtotime($ms['completed_at'])) ?>
                </div>
              <?php endif; ?>
            </div>
            <form method="POST" action="/poam/<?= (int)$item['id'] ?>/milestone/<?= (int)$ms['id'] ?>/complete" style="margin:0;">
              <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
              <button type="submit" class="btn btn-sm <?= $ms['is_complete'] ? 'btn-secondary' : 'btn-primary' ?>">
                <?= $ms['is_complete'] ? 'Undo' : 'Complete' ?>
              </button>
            </form>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <!-- Add Milestone Form -->
        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
          <strong style="font-size:0.9rem;">Add Milestone</strong>
          <form method="POST" action="/poam/<?= (int)$item['id'] ?>/milestone/add" style="margin-top:10px;">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
            <div class="form-group">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="2" required placeholder="Describe the milestone..."></textarea>
            </div>
            <div class="form-group">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add Milestone</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: update form + delete -->
  <div>
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><strong>Update POA&amp;M</strong></div>
      <div class="card-body">
        <form method="POST" action="/poam/<?= (int)$item['id'] ?>/update">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <div class="form-group">
            <label class="form-label">Title</label>
            <input type="text" name="title" class="form-control" value="<?= Security::h($item['title']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
              <option value="open"        <?= $item['status'] === 'open'        ? 'selected' : '' ?>>Open</option>
              <option value="in_progress" <?= $item['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
              <option value="closed"      <?= $item['status'] === 'closed'      ? 'selected' : '' ?>>Closed</option>
              <option value="cancelled"   <?= $item['status'] === 'cancelled'   ? 'selected' : '' ?>>Cancelled</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Owner</label>
            <select name="owner_id" class="form-control">
              <option value="">— None —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $item['owner_id'] == $u['id'] ? 'selected' : '' ?>><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Scheduled Completion</label>
            <input type="date" name="scheduled_completion" class="form-control" value="<?= Security::h($item['scheduled_completion'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Weakness Description</label>
            <textarea name="weakness_description" class="form-control" rows="3"><?= Security::h($item['weakness_description'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Resource Requirements</label>
            <textarea name="resource_requirements" class="form-control" rows="3"><?= Security::h($item['resource_requirements'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;">Save Changes</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><strong style="color:var(--danger);">Danger Zone</strong></div>
      <div class="card-body">
        <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:12px;">Permanently delete this POA&amp;M item and all its milestones.</p>
        <form method="POST" action="/poam/<?= (int)$item['id'] ?>/delete" data-confirm="Delete this POA&amp;M item? This cannot be undone.">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <button type="submit" class="btn btn-danger" style="width:100%;"><i class="bi bi-trash-fill"></i> Delete POA&amp;M</button>
        </form>
      </div>
    </div>
  </div>

</div>
<script nonce="<?= Security::nonce() ?>">
document.querySelectorAll('[data-confirm]').forEach(function(el) {
  el.addEventListener('submit', function(e) { if (!confirm(el.getAttribute('data-confirm'))) e.preventDefault(); });
});
</script>
