<?php
$statusIcons = [
    'approved' => '<i class="bi bi-check-circle-fill" style="color:#22c55e"></i>',
    'rejected' => '<i class="bi bi-x-circle-fill" style="color:#ef4444"></i>',
    'pending'  => '<i class="bi bi-clock" style="color:#f59e0b"></i>',
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Approval Request #<?= (int)$req['id'] ?></h1>
    <p class="page-subtitle">
      <?= Security::h($req['template_name']) ?> —
      <?= Security::h(ucfirst($req['entity_type'])) ?> #<?= (int)$req['entity_id'] ?>:
      <a href="/<?= Security::h($req['entity_type']) ?>/<?= (int)$req['entity_id'] ?>" class="text-link">
        <?= Security::h($entityLabel) ?>
      </a>
    </p>
  </div>
  <div>
    <span class="status-chip status-<?= Security::h($req['status']) ?>">
      <?= ucfirst(Security::h($req['status'])) ?>
    </span>
  </div>
</div>

<!-- Approval chain progress -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header"><h3>Approval Chain</h3></div>
  <div class="card-body">
    <div style="display:flex;gap:0;align-items:stretch">
      <?php foreach ($steps as $i => $step): ?>
        <?php
          $isCurrent = $step['step_number'] == $req['current_step'] && $req['status'] === 'pending';
          $isDone    = !empty($step['actioned_at']);
          $status    = $step['decision'] ?? ($isDone ? 'approved' : ($isCurrent ? 'pending' : 'waiting'));
          $bgColor   = match($status) {
            'approved' => '#f0fdf4', 'rejected' => '#fef2f2',
            'pending'  => '#fffbeb', default    => '#f9fafb',
          };
        ?>
        <div style="flex:1;padding:16px;background:<?= $bgColor ?>;border:1px solid #e5e7eb;
                    <?= $i === 0 ? 'border-radius:8px 0 0 8px' : '' ?>
                    <?= $i === count($steps)-1 ? 'border-radius:0 8px 8px 0' : '' ?>
                    <?= $isCurrent ? 'border-color:#f59e0b;border-width:2px' : '' ?>">
          <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">
            Step <?= (int)$step['step_number'] ?>
          </div>
          <div class="fw-600 text-sm"><?= Security::h($step['label']) ?></div>
          <div class="text-muted text-xs" style="margin-top:4px">
            <?= $step['required_role'] ? ucfirst($step['required_role']) . ' role' : '' ?>
          </div>
          <div style="margin-top:8px">
            <?= $statusIcons[$status] ?? '<i class="bi bi-dash"></i>' ?>
            <?php if ($step['actioned_by_name']): ?>
              <span class="text-xs"><?= Security::h($step['actioned_by_name']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($step['notes']): ?>
            <div class="text-xs text-muted" style="margin-top:6px;font-style:italic">
              "<?= Security::h($step['notes']) ?>"
            </div>
          <?php endif; ?>
        </div>
        <?php if ($i < count($steps) - 1): ?>
          <div style="display:flex;align-items:center;padding:0 4px;background:#f9fafb;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb">
            <i class="bi bi-chevron-right text-muted"></i>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Context snapshot -->
<?php if (!empty($req['context_data'])): ?>
  <?php $ctx = json_decode($req['context_data'], true) ?? []; ?>
  <div class="card" style="margin-bottom:24px">
    <div class="card-header"><h3>Request Context</h3></div>
    <div class="card-body">
      <table class="desc-table">
        <?php foreach ($ctx as $key => $val): ?>
          <?php if ($key === 'csrf_token') continue; ?>
          <tr>
            <th><?= Security::h(ucwords(str_replace('_', ' ', $key))) ?></th>
            <td><?= Security::h((string)$val) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- Decision form -->
<?php if ($canAct && $req['status'] === 'pending'): ?>
<div class="card">
  <div class="card-header"><h3>Your Decision — Step <?= (int)$req['current_step'] ?>: <?= Security::h($currentStep['label'] ?? '') ?></h3></div>
  <div class="card-body">
    <form method="POST" action="/approvals/<?= (int)$req['id'] ?>/decide">
      <?= Security::csrfField() ?>
      <div class="form-group">
        <label class="form-label">Decision <span class="required">*</span></label>
        <div style="display:flex;gap:12px">
          <label class="radio-card" style="flex:1;border:2px solid #e5e7eb;border-radius:8px;padding:16px;cursor:pointer">
            <input type="radio" name="decision" value="approved" required style="margin-right:8px">
            <i class="bi bi-check-circle-fill" style="color:#22c55e"></i>
            <strong>Approve</strong>
            <p class="text-muted text-sm" style="margin:4px 0 0">Advance to the next step or complete the request.</p>
          </label>
          <label class="radio-card" style="flex:1;border:2px solid #e5e7eb;border-radius:8px;padding:16px;cursor:pointer">
            <input type="radio" name="decision" value="rejected" style="margin-right:8px">
            <i class="bi bi-x-circle-fill" style="color:#ef4444"></i>
            <strong>Reject</strong>
            <p class="text-muted text-sm" style="margin:4px 0 0">Block the change and notify the requester.</p>
          </label>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="notes">Notes / Justification</label>
        <textarea id="notes" name="notes" class="form-control" rows="3"
                  placeholder="Optional rationale for your decision…"></textarea>
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary">Submit Decision</button>
        <a href="/approvals" class="btn btn-secondary">Back</a>
      </div>
    </form>
  </div>
</div>
<?php elseif ($req['status'] !== 'pending'): ?>
  <div class="alert-box <?= $req['status'] === 'approved' ? 'success' : 'error' ?>">
    <i class="bi bi-<?= $req['status'] === 'approved' ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
    This request was <?= Security::h($req['status']) ?>
    <?= $req['completed_at'] ? 'on ' . date('M j, Y g:ia', strtotime($req['completed_at'])) : '' ?>.
  </div>
<?php endif; ?>
