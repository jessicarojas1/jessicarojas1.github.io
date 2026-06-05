<?php
$breadcrumbs = $breadcrumbs ?? [['Privacy', null]];
ob_start();
$basisLabels = [
  'consent'=>'Consent','legitimate_interest'=>'Legitimate Interest','contract'=>'Contract',
  'legal_obligation'=>'Legal Obligation','vital_interests'=>'Vital Interests','public_task'=>'Public Task'
];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Data Privacy</h1>
    <p class="page-subtitle">Record of Processing Activities (RoPA) and Data Subject Requests</p>
  </div>
  <div class="page-actions">
    <a href="/privacy/requests" class="btn btn-ghost"><i class="bi bi-inbox-fill"></i> Subject Requests <?= count(array_filter($dsr, fn($r) => $r['status']==='open')) > 0 ? '<span class="badge badge-red">'.count(array_filter($dsr, fn($r) => $r['status']==='open')).'</span>' : '' ?></a>
    <?php if (Auth::can('compliance.write')): ?>
    <a href="/privacy/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Activity</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px">
  <div class="card" style="padding:20px;text-align:center">
    <div style="font-size:28px;font-weight:800;color:var(--primary)"><?= $stats['total'] ?></div>
    <div style="font-size:12px;color:var(--text-muted)">Processing Activities</div>
  </div>
  <div class="card" style="padding:20px;text-align:center">
    <div style="font-size:28px;font-weight:800;color:var(--success)"><?= $stats['active'] ?></div>
    <div style="font-size:12px;color:var(--text-muted)">Active</div>
  </div>
  <div class="card" style="padding:20px;text-align:center">
    <div style="font-size:28px;font-weight:800;color:<?= $stats['dpia_due'] > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>"><?= $stats['dpia_due'] ?></div>
    <div style="font-size:12px;color:var(--text-muted)">DPIAs Required</div>
  </div>
</div>

<!-- RoPA table -->
<div class="card">
  <div class="card-header">
    <div class="card-header-left"><i class="bi bi-shield-lock-fill" style="color:var(--primary)"></i><span class="card-title">Record of Processing Activities</span></div>
  </div>
  <div class="card-body" style="padding:0">
    <?php if ($records): ?>
    <table class="table">
      <thead>
        <tr>
          <th>Activity</th>
          <th>Legal Basis</th>
          <th>Controller</th>
          <th>DPIA</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
          <td>
            <a href="/privacy/<?= (int)$r['id'] ?>" style="font-weight:600;color:var(--primary);text-decoration:none">
              <?= Security::h($r['name']) ?>
            </a>
            <?php if ($r['purpose']): ?>
              <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= Security::h(substr($r['purpose'],0,70)) ?></div>
            <?php endif; ?>
          </td>
          <td><?= Security::h($basisLabels[$r['legal_basis']] ?? ($r['legal_basis'] ?: '—')) ?></td>
          <td><?= Security::h($r['controller_name'] ?: '—') ?></td>
          <td>
            <?php if ($r['dpia_required']): ?>
              <?php if ($r['dpia_completed']): ?>
                <span class="badge badge-green"><i class="bi bi-check-circle-fill"></i> Done</span>
              <?php else: ?>
                <span class="badge badge-red"><i class="bi bi-exclamation-circle-fill"></i> Required</span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:12px">—</span>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= $r['status']==='active'?'green':'gray' ?>"><?= ucfirst(Security::h($r['status'])) ?></span></td>
          <td class="text-right">
            <a href="/privacy/<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm"><i class="bi bi-eye"></i> View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state-sm"><i class="bi bi-file-earmark-lock2"></i><p>No processing activities. Document every activity where personal data is processed to build your GDPR Record of Processing Activities (RoPA).</p></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($dsr): ?>
<div class="card" style="margin-top:20px">
  <div class="card-header">
    <div class="card-header-left"><i class="bi bi-inbox-fill" style="color:var(--primary)"></i><span class="card-title">Open Data Subject Requests</span></div>
    <a href="/privacy/requests" class="btn btn-ghost btn-sm">View All</a>
  </div>
  <div class="card-body" style="padding:0">
    <table class="table">
      <thead><tr><th>Type</th><th>Subject</th><th>Status</th><th>Due</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($dsr as $r):
          $typeLabels=['access'=>'Access','erasure'=>'Erasure','rectification'=>'Rectification','portability'=>'Portability','objection'=>'Objection','restriction'=>'Restriction'];
        ?>
        <tr>
          <td><span class="badge badge-blue"><?= Security::h($typeLabels[$r['request_type']] ?? $r['request_type']) ?></span></td>
          <td><?= Security::h($r['subject_name']) ?> <?= $r['subject_email'] ? '<span style="color:var(--text-muted);font-size:12px">'.Security::h($r['subject_email']).'</span>' : '' ?></td>
          <td><span class="badge badge-<?= $r['status']==='open'?'red':'yellow' ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
          <td><?= $r['due_date'] ? date('M j', strtotime($r['due_date'])) : '—' ?></td>
          <td><a href="/privacy/requests" class="btn btn-ghost btn-sm">Manage</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
