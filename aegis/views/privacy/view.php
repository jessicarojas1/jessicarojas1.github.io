<?php
$breadcrumbs = $breadcrumbs ?? [['Privacy', '/privacy'], ['Record', null]];
ob_start();
$basisLabels = [
  'consent'=>'Consent','legitimate_interest'=>'Legitimate Interest','contract'=>'Contract',
  'legal_obligation'=>'Legal Obligation','vital_interests'=>'Vital Interests','public_task'=>'Public Task'
];
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($record['name']) ?></h1>
    <p class="page-subtitle">Processing Activity Record</p>
  </div>
  <div class="page-actions">
    <a href="/privacy" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
    <?php if (Auth::can('compliance.assess')): ?>
    <form method="POST" action="/privacy/<?= (int)$record['id'] ?>/delete"
          data-confirm="Delete this processing activity record?">
      <?= Security::csrfField() ?>
      <button class="btn btn-danger btn-sm"><i class="bi bi-trash3-fill"></i> Delete</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start">

  <div style="display:flex;flex-direction:column;gap:16px">

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-info-circle-fill" style="color:var(--primary)"></i><span class="card-title">Basic Information</span></div></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
        <?php if ($record['description']): ?>
        <div>
          <div class="detail-label">Description</div>
          <div><?= nl2br(Security::h($record['description'])) ?></div>
        </div>
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div>
            <div class="detail-label">Data Controller</div>
            <div><?= Security::h($record['controller_name'] ?: '—') ?></div>
          </div>
          <div>
            <div class="detail-label">Data Processor</div>
            <div><?= Security::h($record['processor_name'] ?: '—') ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-gear-fill" style="color:var(--primary)"></i><span class="card-title">Processing Details</span></div></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
        <?php foreach ([
          ['Purpose'              , $record['purpose']],
          ['Legal Basis'         , $basisLabels[$record['legal_basis']] ?? $record['legal_basis']],
          ['Data Subject Categories', $record['data_subject_categories']],
          ['Categories of Personal Data', $record['data_categories']],
        ] as [$label, $val]): if (!$val) continue; ?>
        <div>
          <div class="detail-label"><?= $label ?></div>
          <div><?= nl2br(Security::h($val)) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-share-fill" style="color:var(--primary)"></i><span class="card-title">Sharing &amp; Retention</span></div></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
        <?php foreach ([
          ['Recipients / Disclosures', $record['recipients']],
          ['Third Country Transfers'  , $record['third_country_transfers']],
          ['Retention Period'         , $record['retention_period']],
          ['Security Measures'        , $record['security_measures']],
        ] as [$label, $val]): if (!$val) continue; ?>
        <div>
          <div class="detail-label"><?= $label ?></div>
          <div><?= nl2br(Security::h($val)) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Sidebar -->
  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-shield-lock-fill" style="color:var(--primary)"></i><span class="card-title">DPIA</span></div></div>
      <div class="card-body">
        <?php if ($record['dpia_required']): ?>
          <?php if ($record['dpia_completed']): ?>
          <div style="display:flex;align-items:center;gap:8px;color:var(--success);font-weight:600;margin-bottom:8px">
            <i class="bi bi-check-circle-fill"></i> DPIA Completed
          </div>
          <?php if ($record['dpia_date']): ?>
          <div style="font-size:12px;color:var(--text-muted)">Completed: <?= date('M j, Y', strtotime($record['dpia_date'])) ?></div>
          <?php endif; ?>
          <?php else: ?>
          <div style="display:flex;align-items:center;gap:8px;color:var(--danger);font-weight:600">
            <i class="bi bi-exclamation-circle-fill"></i> DPIA Required
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:4px">This activity requires a Data Protection Impact Assessment.</div>
          <?php endif; ?>
        <?php else: ?>
          <div style="color:var(--text-muted);font-size:13px">No DPIA required for this activity.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-info-circle" style="color:var(--primary)"></i><span class="card-title">Record Info</span></div></div>
      <div class="card-body" style="font-size:13px;display:flex;flex-direction:column;gap:10px">
        <div>
          <div style="color:var(--text-muted)">Status</div>
          <span class="badge badge-<?= $record['status']==='active'?'green':'gray' ?>"><?= ucfirst(Security::h($record['status'])) ?></span>
        </div>
        <div>
          <div style="color:var(--text-muted)">Created by</div>
          <strong><?= Security::h($record['created_by_name'] ?? '—') ?></strong>
        </div>
        <div>
          <div style="color:var(--text-muted)">Created</div>
          <?= date('M j, Y', strtotime($record['created_at'])) ?>
        </div>
        <div>
          <div style="color:var(--text-muted)">Last updated</div>
          <?= date('M j, Y', strtotime($record['updated_at'])) ?>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.detail-label { font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px; }
</style>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
