<?php
$pageTitle    = 'Email Delivery Log';
$activeModule = 'admin';
$breadcrumbs  = [['Admin','/admin'],['Email Delivery Log',null]];
$nonce = Security::nonce();
ob_start();

$typeLabels = [
    'overdue_controls'       => 'Overdue Controls',
    'policy_review_due'      => 'Policy Review',
    'pending_approval'       => 'Pending Approval',
    'new_risk_assigned'      => 'Risk Assigned',
    'open_incident_aging'    => 'Incident Aging',
    'risk_review_overdue'    => 'Risk Review Overdue',
    'treatment_due'          => 'Treatment Due',
    'risk_score_worsened'    => 'Score Worsened',
    'vendor_assessment_expiring' => 'Vendor Due',
    'document_expiring'      => 'Doc Expiring',
    'assessment_pending_stale'   => 'Assessment Stale',
];
$typeColors = [
    'overdue_controls'=>'var(--danger)','policy_review_due'=>'var(--warning)','pending_approval'=>'var(--primary)',
    'new_risk_assigned'=>'#f97316','open_incident_aging'=>'var(--danger)','risk_review_overdue'=>'#b45309',
    'treatment_due'=>'var(--secondary)','risk_score_worsened'=>'var(--danger)','vendor_assessment_expiring'=>'#0891b2',
    'document_expiring'=>'var(--success)','assessment_pending_stale'=>'#71717a',
];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Email Delivery Log</h1>
    <p class="page-subtitle">Track all notification emails sent by AEGIS</p>
  </div>
  <div class="page-actions">
    <a href="/admin/email-delivery?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn btn-ghost">
      <i class="bi bi-download"></i> Export CSV
    </a>
  </div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
  <?php foreach ([
    ['Total Sent','total_all','var(--primary)','bi-envelope-fill'],
    ['Today','today','var(--primary)','bi-calendar-check-fill'],
    ['This Week','this_week','var(--warning)','bi-calendar-week-fill'],
    ['This Month','this_month','#3b82f6','bi-calendar-month-fill'],
  ] as [$label,$key,$color,$icon]): ?>
  <div class="card" style="border-left:4px solid <?= $color ?>">
    <div class="card-body" style="display:flex;align-items:center;gap:14px;padding:14px 18px">
      <i class="bi <?= $icon ?>" style="font-size:24px;color:<?= $color ?>"></i>
      <div>
        <div style="font-size:26px;font-weight:800;color:<?= $color ?>"><?= number_format((int)($stats[$key]??0)) ?></div>
        <div style="font-size:12px;color:var(--text-muted)"><?= $label ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="filter-bar card" style="margin-bottom:16px">
  <form method="GET" class="filter-form" style="flex-wrap:wrap;gap:8px">
    <select name="type" class="form-control form-control-sm" data-autosubmit>
      <option value="">All types</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= Security::h($t['notification_type']) ?>" <?= ($_GET['type']??'')===$t['notification_type']?'selected':'' ?>><?= $typeLabels[$t['notification_type']] ?? Security::h($t['notification_type']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="recipient" class="form-control form-control-sm" placeholder="Recipient email..." value="<?= Security::h($_GET['recipient']??'') ?>">
    <input type="date" name="from" class="form-control form-control-sm" value="<?= Security::h($_GET['from']??'') ?>">
    <input type="date" name="to" class="form-control form-control-sm" value="<?= Security::h($_GET['to']??'') ?>">
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="/admin/email-delivery" class="btn btn-ghost btn-sm">Clear</a>
  </form>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table">
      <thead>
        <tr>
          <th>Sent At</th>
          <th>Type</th>
          <th>Recipient</th>
          <th>Entity</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($logs): foreach ($logs as $log):
          $tc = $typeColors[$log['notification_type']] ?? '#71717a';
          $tl = $typeLabels[$log['notification_type']] ?? $log['notification_type'];
        ?>
        <tr>
          <td style="white-space:nowrap;font-size:12px;color:var(--text-muted)">
            <?= Security::h(date('Y-m-d H:i', strtotime($log['sent_at']))) ?>
          </td>
          <td>
            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:<?= $tc ?>18;color:<?= $tc ?>;border:1px solid <?= $tc ?>30;white-space:nowrap">
              <?= Security::h($tl) ?>
            </span>
          </td>
          <td style="font-size:13px"><?= Security::h($log['recipient_email'] ?? '—') ?></td>
          <td style="font-size:12px;color:var(--text-muted)">
            <?php if ($log['entity_id']): ?>
              <?= Security::h(ucfirst(str_replace('_',' ', $log['notification_type']))) ?> #<?= (int)$log['entity_id'] ?>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="4" class="empty-row">
          <div class="empty-state-sm"><i class="bi bi-envelope-slash"></i><p>No emails match your filters.</p></div>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;justify-content:center;gap:8px;margin-top:16px;flex-wrap:wrap">
  <?php for ($p=1; $p<=$pages; $p++):
    $active = $p === $page;
    $qp = array_merge($_GET, ['page'=>$p]);
  ?>
    <a href="?<?= http_build_query($qp) ?>"
       style="display:inline-block;padding:6px 12px;border-radius:6px;border:1px solid <?= $active?'var(--primary)':'var(--border)' ?>;background:<?= $active?'var(--primary)':'var(--bg-card)' ?>;color:<?= $active?'#fff':'inherit' ?>;text-decoration:none;font-size:13px;font-weight:<?= $active?'700':'400' ?>">
      <?= $p ?>
    </a>
  <?php endfor; ?>
</div>
<div style="text-align:center;margin-top:8px;font-size:12px;color:var(--text-muted)">
  Showing <?= count($logs) ?> of <?= number_format($total) ?> records
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
