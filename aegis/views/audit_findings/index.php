<?php
$breadcrumbs  = $breadcrumbs  ?? [['Audits', '/audit'], ['Findings', null]];
$csrf = Security::generateCsrfToken();
$sevBadge = ['critical'=>'badge-danger','high'=>'badge-danger','medium'=>'badge-warning','low'=>'badge-info','info'=>'badge-secondary'];
$statusBadge = ['open'=>'badge-danger','in_progress'=>'badge-warning','resolved'=>'badge-success','risk_accepted'=>'badge-info','closed'=>'badge-secondary'];
$filter = $_GET['status'] ?? 'all';
$filtered = $filter === 'all' ? $findings : array_filter($findings, fn($f) => $f['status'] === $filter);
$overdue = array_filter($findings, fn($f) => AuditFindingController::remediationStatus($f['deadline'], $f['status']) === 'overdue');
$open    = array_filter($findings, fn($f) => in_array($f['status'], ['open','in_progress']));
?>
<div class="page-header">
  <div>
    <h1 class="page-title">External Audit Findings</h1>
    <p class="page-subtitle">Track findings from external auditors, pen testers, and certification bodies</p>
  </div>
  <button class="btn btn-primary" data-show-modal="createFindingModal"><i class="bi bi-plus-lg"></i> New Finding</button>
</div>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;">
  <div class="card" style="text-align:center;padding:20px;">
    <div style="font-size:2rem;font-weight:700;color:var(--primary);"><?= count($findings) ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">Total Findings</div>
  </div>
  <div class="card" style="text-align:center;padding:20px;">
    <div style="font-size:2rem;font-weight:700;color:var(--danger);"><?= count($open) ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">Open / In Progress</div>
  </div>
  <div class="card" style="text-align:center;padding:20px;<?= count($overdue) > 0 ? 'border-color:var(--danger);' : '' ?>">
    <div style="font-size:2rem;font-weight:700;color:<?= count($overdue) > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= count($overdue) ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">Overdue</div>
  </div>
</div>

<div style="display:flex;gap:8px;margin-bottom:16px;">
  <?php foreach (['all'=>'All','open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed'] as $k=>$l): ?>
  <a href="?status=<?= $k ?>" class="btn btn-sm <?= $filter===$k ? 'btn-primary' : 'btn-secondary' ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>

<?php if (empty($filtered)): ?>
<div class="card" style="text-align:center;padding:40px;"><p style="color:var(--text-muted);">No findings match this filter.</p></div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead><tr><th scope="col">Finding #</th><th scope="col">Title</th><th scope="col">Severity</th><th scope="col">Status</th><th scope="col">Source</th><th scope="col">Linked Audit</th><th scope="col">Owner</th><th scope="col">Deadline</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($filtered as $f):
      $remediation = AuditFindingController::remediationStatus($f['deadline'], $f['status']);
      $overdueCls  = $remediation === 'overdue' ? 'color:var(--danger);font-weight:600;' : '';
      $auditLabel  = $f['linked_audit_name'] ?? $f['audit_name'] ?? '—';
    ?>
      <tr>
        <td style="font-family:monospace;font-weight:600;"><?= Security::h($f['finding_number']) ?></td>
        <td><a href="/audit-findings/<?= (int)$f['id'] ?>"><?= Security::h($f['title']) ?></a></td>
        <td><span class="badge <?= $sevBadge[$f['severity']] ?? 'badge-secondary' ?>"><?= ucfirst($f['severity']) ?></span></td>
        <td><span class="badge <?= $statusBadge[$f['status']] ?? 'badge-secondary' ?>"><?= ucwords(str_replace('_',' ',$f['status'])) ?></span></td>
        <td><?= Security::h(ucwords(str_replace('_',' ',$f['source']))) ?></td>
        <td><?php if (!empty($f['audit_id'])): ?><a href="/audit/<?= (int)$f['audit_id'] ?>"><?= Security::h($auditLabel) ?></a><?php else: ?><?= Security::h($auditLabel) ?><?php endif; ?></td>
        <td><?= Security::h($f['owner_name'] ?: '—') ?></td>
        <td style="<?= $overdueCls ?>">
          <?= $f['deadline'] ? date('M j, Y', strtotime($f['deadline'])) : '—' ?>
          <?php if ($remediation === 'overdue'): ?>
            <span class="badge badge-danger" style="font-size:0.7rem"><i class="bi bi-exclamation-triangle-fill"></i> Overdue</span>
          <?php elseif ($remediation === 'due'): ?>
            <span class="badge badge-warning" style="font-size:0.7rem"><i class="bi bi-clock"></i> Due soon</span>
          <?php endif; ?>
        </td>
        <td><a href="/audit-findings/<?= (int)$f['id'] ?>" class="btn btn-sm btn-secondary">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Create Modal -->
<div id="createFindingModal" class="um-overlay">
  <div class="um-dialog" style="width:700px;max-height:90vh;overflow-y:auto;max-width:95vw;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">New Audit Finding</h3>
      <button data-close-modal="createFindingModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/audit-findings/create">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group" style="grid-column:1/-1"><label class="form-label">Title <span style="color:var(--danger)">*</span></label><input type="text" name="title" class="form-control" required></div>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        <div class="form-group"><label class="form-label">Severity</label>
          <select name="severity" class="form-control">
            <option value="critical">Critical</option><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option><option value="info">Info</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Source</label>
          <select name="source" class="form-control">
            <option value="external_audit">External Audit</option><option value="pentest">Penetration Test</option><option value="certification">Certification</option><option value="assessment">Assessment</option><option value="regulatory">Regulatory</option><option value="other">Other</option>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label">Linked Audit</label>
          <select name="audit_id" class="form-control">
            <option value="">— Not linked to an audit —</option>
            <?php foreach ($audits as $a): ?>
            <option value="<?= (int)$a['id'] ?>"><?= Security::h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--text-muted);font-size:0.78rem;">Optionally link this finding to a specific audit record. The audit name will be pre-filled automatically.</small>
        </div>
        <div class="form-group"><label class="form-label">Audit Name <span style="color:var(--text-muted);font-weight:400;">(override)</span></label><input type="text" name="audit_name" class="form-control" placeholder="e.g. ISO 27001 Certification Audit"></div>
        <div class="form-group"><label class="form-label">Auditor / Firm</label><input type="text" name="auditor_name" class="form-control"></div>
        <div class="form-group"><label class="form-label">Owner</label>
          <select name="owner_id" class="form-control">
            <option value="">— Unassigned —</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Deadline</label><input type="date" name="deadline" class="form-control"></div>
        <div class="form-group" style="grid-column:1/-1"><label class="form-label">Linked Compliance Package</label>
          <select name="package_id" class="form-control">
            <option value="">— None —</option>
            <?php foreach ($packages as $p): ?><option value="<?= (int)$p['id'] ?>"><?= Security::h($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">Create Finding</button>
        <button type="button" data-close-modal="createFindingModal" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

