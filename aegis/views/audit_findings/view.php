<?php
$breadcrumbs  = $breadcrumbs  ?? [['Audits', '/audit'], ['Findings', '/audit_findings'], ['View Finding', null]];
$csrf = Security::generateCsrfToken();
$sevBadge = ['critical'=>'badge-danger','high'=>'badge-danger','medium'=>'badge-warning','low'=>'badge-info','info'=>'badge-secondary'];
$statusBadge = ['open'=>'badge-danger','in_progress'=>'badge-warning','resolved'=>'badge-success','risk_accepted'=>'badge-info','closed'=>'badge-secondary'];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($finding['finding_number']) ?></h1>
    <p class="page-subtitle"><?= Security::h($finding['title']) ?></p>
  </div>
  <div style="display:flex;gap:10px;">
    <?php if (!in_array($finding['status'],['closed','resolved'])): ?>
    <form id="closeForm" method="POST" action="/audit-findings/<?= (int)$finding['id'] ?>/close" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button id="btnCloseFinding" type="button" class="btn btn-secondary"><i class="bi bi-check-circle"></i> Close</button>
    </form>
    <?php endif; ?>
    <?php if (in_array($finding['status'],['closed','resolved','risk_accepted'])): ?>
    <form method="POST" action="/audit-findings/<?= (int)$finding['id'] ?>/reopen" style="margin:0" data-confirm="Reopen this finding?">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button>
    </form>
    <?php endif; ?>
    <form id="deleteForm" method="POST" action="/audit-findings/<?= (int)$finding['id'] ?>/delete" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button id="btnDeleteFinding" type="button" class="btn btn-danger"><i class="bi bi-trash"></i></button>
    </form>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start;">
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Description & Response -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Finding Details</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px;">
        <?php if ($finding['description']): ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:6px;">Description</div><?= nl2br(Security::h($finding['description'])) ?></div>
        <?php endif; ?>
        <form method="POST" action="/audit-findings/<?= (int)$finding['id'] ?>/update">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <div class="form-group">
            <label class="form-label">Response Notes</label>
            <textarea name="response_notes" class="form-control" rows="4" placeholder="Document your organization's response and remediation plan..."><?= Security::h($finding['response_notes'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Root Cause</label>
            <textarea name="root_cause" class="form-control" rows="2" placeholder="Underlying cause identified through analysis (5-why, fishbone)..."><?= Security::h($finding['root_cause'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Preventive Action</label>
            <textarea name="preventive_action" class="form-control" rows="2" placeholder="Action to prevent recurrence across the organization..."><?= Security::h($finding['preventive_action'] ?? '') ?></textarea>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
            <div class="form-group"><label class="form-label">Status</label>
              <select name="status" class="form-control">
                <?php foreach (['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','risk_accepted'=>'Risk Accepted','closed'=>'Closed','reopened'=>'Reopened'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $finding['status']===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Deadline</label><input type="date" name="deadline" class="form-control" value="<?= Security::h($finding['deadline'] ?? '') ?>"></div>
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-top:12px;">Save Changes</button>
        </form>
      </div>
    </div>

    <!-- Updates timeline -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Updates</h3></div>
      <div class="card-body">
        <?php if (empty($updates)): ?>
        <p style="color:var(--text-muted);font-size:0.875rem;">No updates yet.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:16px;margin-bottom:20px;">
          <?php foreach ($updates as $upd): ?>
          <div style="border-left:3px solid var(--border);padding-left:16px;">
            <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;"><?= Security::h($upd['user_name'] ?? 'System') ?> · <?= date('M j, Y g:ia', strtotime($upd['created_at'])) ?></div>
            <div><?= nl2br(Security::h($upd['content'])) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="POST" action="/audit-findings/<?= (int)$finding['id'] ?>/add-update">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <div class="form-group"><textarea name="content" class="form-control" rows="3" placeholder="Add an update or note..." required></textarea></div>
          <button type="submit" class="btn btn-secondary btn-sm" style="margin-top:8px;">Add Update</button>
        </form>
      </div>
    </div>

    <!-- Linked risks (Phase 2 traceability) -->
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-shield-exclamation"></i> Linked Risks <span class="badge badge-secondary"><?= count($linkedRisks ?? []) ?></span></h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
        <?php if (empty($linkedRisks)): ?>
          <div class="empty-state-sm"><i class="bi bi-link-45deg"></i><p>No risks linked to this finding yet.</p></div>
        <?php else: foreach ($linkedRisks as $lr): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border:1px solid var(--border);border-radius:var(--radius-sm)">
            <div style="min-width:0">
              <a href="/risk/<?= (int)$lr['id'] ?>" style="font-weight:600;color:var(--primary)"><?= Security::h($lr['risk_code'] ?: ('Risk #' . (int)$lr['id'])) ?></a>
              <span class="badge" style="background:var(--info-subtle);color:var(--moderate);font-size:10px;text-transform:uppercase;margin-left:6px"><?= Security::h(str_replace('_', ' ', $lr['relationship_type'])) ?></span>
              <div style="font-size:12px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= Security::h((string)$lr['title']) ?></div>
            </div>
            <?php if (!empty($canLinkRisk)): ?>
              <form method="POST" action="/audit-findings/<?= (int)$finding['id'] ?>/unlink-risk" data-confirm="Unlink this risk?" style="flex-shrink:0">
                <?= Security::csrfField() ?>
                <input type="hidden" name="link_id" value="<?= (int)$lr['link_id'] ?>">
                <button type="submit" class="btn-unstyled" style="cursor:pointer;color:var(--danger)" title="Unlink" aria-label="Unlink risk"><i class="bi bi-x-lg"></i></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>

        <?php if (!empty($canLinkRisk) && !empty($availableRisks)): ?>
          <form method="POST" action="/audit-findings/<?= (int)$finding['id'] ?>/link-risk" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px">
            <?= Security::csrfField() ?>
            <div class="form-group" style="margin:0;flex:1;min-width:200px">
              <label class="form-label">Link a risk</label>
              <select name="risk_id" class="form-control" required>
                <option value="">Select risk…</option>
                <?php foreach ($availableRisks as $ar): ?>
                  <option value="<?= (int)$ar['id'] ?>"><?= Security::h(($ar['risk_code'] ? $ar['risk_code'] . ' — ' : '') . mb_strimwidth((string)$ar['title'], 0, 50, '…')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="margin:0">
              <label class="form-label">Relationship</label>
              <select name="relationship_type" class="form-control">
                <option value="causes">Causes</option>
                <option value="indicates">Indicates</option>
                <option value="mitigated_by">Mitigated by</option>
                <option value="related" selected>Related</option>
              </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-link-45deg"></i> Link</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Metadata</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Severity</div><span class="badge <?= $sevBadge[$finding['severity']] ?? 'badge-secondary' ?>"><?= ucfirst($finding['severity']) ?></span></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Status</div><span class="badge <?= $statusBadge[$finding['status']] ?? 'badge-secondary' ?>"><?= ucwords(str_replace('_',' ',$finding['status'])) ?></span></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Source</div><div><?= Security::h(ucwords(str_replace('_',' ',$finding['source']))) ?></div></div>
        <?php if (!empty($finding['linked_audit_id'])): ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Linked Audit</div><div><a href="/audit/<?= (int)$finding['linked_audit_id'] ?>"><?= Security::h($finding['linked_audit_name'] ?? $finding['audit_name'] ?? '—') ?></a></div></div>
        <?php else: ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Audit / Engagement</div><div><?= Security::h($finding['audit_name'] ?: '—') ?></div></div>
        <?php endif; ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Auditor / Firm</div><div><?= Security::h($finding['auditor_name'] ?: '—') ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Owner</div><div><?= Security::h($finding['owner_name'] ?: '—') ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Deadline</div>
          <?php if ($finding['deadline']):
            $dl = strtotime($finding['deadline']); $late = $dl < time() && !in_array($finding['status'],['closed','resolved']);
          ?>
          <div style="<?= $late ? 'color:var(--danger);font-weight:600;' : '' ?>"><?= date('M j, Y', $dl) ?><?= $late ? ' (Overdue)' : '' ?></div>
          <?php else: ?>—<?php endif; ?>
        </div>
        <?php if ($finding['package_name']): ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Compliance Package</div><div><?= Security::h($finding['package_name']) ?></div></div>
        <?php endif; ?>
        <?php if ($finding['control_code']): ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Control</div><div style="font-family:monospace;"><?= Security::h($finding['control_code']) ?></div></div>
        <?php endif; ?>
        <?php if ($finding['closed_at']): ?>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Closed</div><div><?= date('M j, Y', strtotime($finding['closed_at'])) ?></div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
(function() {
  var closeBtn = document.getElementById('btnCloseFinding');
  var deleteBtn = document.getElementById('btnDeleteFinding');
  if (closeBtn) {
    closeBtn.addEventListener('click', function() {
      if (confirm('Mark this finding as closed?')) document.getElementById('closeForm').submit();
    });
  }
  if (deleteBtn) {
    deleteBtn.addEventListener('click', function() {
      if (confirm('Permanently delete this finding?')) document.getElementById('deleteForm').submit();
    });
  }
})();
</script>
