<?php
$tierColors   = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#0284c7','low'=>'#059669'];
$statusColors = ['active'=>'#059669','inactive'=>'#71717a','under_review'=>'#d97706','terminated'=>'#dc2626'];
$tierColor    = $tierColors[$vendor['risk_tier']] ?? '#71717a';
$stColor      = $statusColors[$vendor['status']] ?? '#71717a';
$pageTitle    = 'Vendor: ' . Security::h($vendor['vendor_code']);
$activeModule = 'vendor';
$breadcrumbs  = [['Vendor Risk', '/vendor'], [$vendor['vendor_code'], null]];
ob_start();
?>

<div class="page-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <h1 class="page-title" style="margin:0"><?= Security::h($vendor['name']) ?></h1>
      <?php if (!empty($vendor['vendor_code'])): ?>
        <span class="badge" style="background:var(--info-subtle);color:var(--info);border:1px solid var(--border);font-family:monospace;font-size:13px;padding:4px 10px"><?= Security::h($vendor['vendor_code']) ?></span>
      <?php endif; ?>
      <span class="status-chip" style="background:<?= $tierColor ?>20;color:<?= $tierColor ?>;border:1px solid <?= $tierColor ?>40;"><?= ucfirst(Security::h($vendor['risk_tier'])) ?> Risk</span>
      <span class="status-chip" style="background:<?= $stColor ?>20;color:<?= $stColor ?>;border:1px solid <?= $stColor ?>40;"><?= ucfirst(str_replace('_',' ',Security::h($vendor['status']))) ?></span>
    </div>
    <p class="page-subtitle"><?= $vendor['category'] ? Security::h($vendor['category']) : '' ?><?= $vendor['country'] ? ' · ' . Security::h($vendor['country']) : '' ?></p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('vendor.write')): ?>
      <form method="POST" action="/vendor/<?= $vendor['id'] ?>/portal-link" style="display:inline">
        <?= Security::csrfField() ?>
        <button class="btn btn-secondary"><i class="bi bi-share"></i> Generate Assessment Link</button>
      </form>
      <button data-show-modal="editModal" class="btn btn-secondary"><i class="bi bi-pencil"></i> Edit</button>
      <button data-show-modal="assessModal" class="btn btn-primary"><i class="bi bi-clipboard-check"></i> Schedule Assessment</button>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['portal_link'])): ?>
<div class="card" style="margin-bottom:16px;border-left:3px solid var(--success)">
  <div class="card-body">
    <strong>Portal link generated:</strong>
    <input type="text" value="<?= Security::h($_SESSION['portal_link']) ?>" style="width:100%;margin-top:8px;font-family:monospace;padding:6px;border:1px solid var(--border);border-radius:6px" readonly id="portal-link-input">
    <small style="color:var(--text-muted)">Share this link with the vendor. It expires in 30 days.</small>
  </div>
</div>
<?php unset($_SESSION['portal_link']); ?>
<?php endif; ?>

<div class="two-col-layout">
  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <?php if ($vendor['description']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-file-text" style="color:var(--primary)"></i><span class="card-title">About</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($vendor['description']) ?></p></div>
    </div>
    <?php endif; ?>

    <!-- Assessments -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left"><i class="bi bi-clipboard-check" style="color:var(--secondary)"></i><span class="card-title">Assessments</span></div>
      </div>
      <div class="card-body">
        <?php if ($assessments): foreach ($assessments as $a):
          $aColors=['planned'=>'#71717a','in_progress'=>'#d97706','completed'=>'#059669','overdue'=>'#dc2626'];
          $rColors=['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#0284c7','low'=>'#059669','acceptable'=>'#059669'];
          $ac = $aColors[$a['status']] ?? '#71717a';
          $rc = $rColors[$a['risk_rating'] ?? ''] ?? '#71717a';
        ?>
          <div style="padding:14px 0;border-bottom:1px solid var(--border);">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
              <strong style="font-size:13px"><?= ucfirst(str_replace('_',' ',Security::h($a['assessment_type']))) ?> Assessment</strong>
              <span class="status-chip" style="background:<?= $ac ?>20;color:<?= $ac ?>"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span>
              <?php if ($a['risk_rating']): ?>
                <span class="status-chip" style="background:<?= $rc ?>20;color:<?= $rc ?>"><?= ucfirst($a['risk_rating']) ?> Risk</span>
              <?php endif; ?>
              <?php if ($a['overall_score'] !== null): ?>
                <span style="font-size:12px;color:var(--text-muted)">Score: <?= $a['overall_score'] ?>/100</span>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--text-muted);display:flex;gap:16px;flex-wrap:wrap;">
              <?php if ($a['assessed_by_name']): ?><span><i class="bi bi-person"></i> <?= Security::h($a['assessed_by_name']) ?></span><?php endif; ?>
              <?php if ($a['scheduled_date']): ?><span><i class="bi bi-calendar"></i> Scheduled <?= date('M j, Y', strtotime($a['scheduled_date'])) ?></span><?php endif; ?>
              <?php if ($a['completed_date']): ?><span><i class="bi bi-check-circle"></i> Completed <?= date('M j, Y', strtotime($a['completed_date'])) ?></span><?php endif; ?>
            </div>
            <?php if ($a['findings']): ?>
              <p style="margin:8px 0 0;font-size:13px;white-space:pre-wrap"><?= Security::h($a['findings']) ?></p>
            <?php endif; ?>
            <?php if (Auth::can('vendor.write') && $a['status'] !== 'completed'): ?>
              <button data-click="showUpdateAssessModal" data-args='[<?= (int)$a['id'] ?>,"<?= Security::h($a['status']) ?>"]' class="btn btn-ghost" style="margin-top:8px;font-size:12px;padding:4px 10px"><i class="bi bi-pencil"></i> Update</button>
            <?php endif; ?>
          </div>
        <?php endforeach; else: ?>
          <p style="color:var(--text-muted);text-align:center;padding:20px 0">No assessments scheduled yet.</p>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-info-circle" style="color:var(--primary)"></i><span class="card-title">Vendor Details</span></div></div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <?php $rows=[
            ['Risk Tier',    '<span class="status-chip" style="background:'.$tierColor.'20;color:'.$tierColor.'">' . ucfirst(Security::h($vendor['risk_tier'])) . ' Risk</span>'],
            ['Status',       '<span class="status-chip" style="background:'.$stColor.'20;color:'.$stColor.'">' . ucfirst(str_replace('_',' ',Security::h($vendor['status']))) . '</span>'],
            ['Category',     Security::h($vendor['category'] ?? '—')],
            ['Country',      Security::h($vendor['country'] ?? '—')],
            ['Website',      (function() use ($vendor) {
              $url = $vendor['website'] ?? '';
              if (!$url) return '—';
              // Only allow http/https to prevent javascript: URIs
              $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
              if (!in_array($scheme, ['http', 'https'])) return Security::h($url);
              return '<a href="'.Security::h($url).'" target="_blank" rel="noopener noreferrer">'.Security::h($url).'</a>';
            })()],
            ['Data Access',  $vendor['data_access'] ? '<span style="color:var(--danger)">Yes</span>' : 'No'],
            ['Critical Service', $vendor['critical_service'] ? '<span style="color:var(--danger)">Yes</span>' : 'No'],
            ['Contract Start', $vendor['contract_start'] ? date('M j, Y', strtotime($vendor['contract_start'])) : '—'],
            ['Contract End',   $vendor['contract_end'] ? date('M j, Y', strtotime($vendor['contract_end'])) : '—'],
            ['Added',        date('M j, Y', strtotime($vendor['created_at']))],
          ]; foreach ($rows as [$label, $val]): ?>
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:8px 0;color:var(--text-muted);width:130px"><?= $label ?></td>
            <td style="padding:8px 0"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <?php if ($vendor['primary_contact'] || $vendor['contact_email']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-person-lines-fill" style="color:var(--warning)"></i><span class="card-title">Contact</span></div></div>
      <div class="card-body" style="font-size:14px;">
        <?php if ($vendor['primary_contact']): ?>
          <div style="margin-bottom:6px"><i class="bi bi-person" style="color:var(--text-muted)"></i> <?= Security::h($vendor['primary_contact']) ?></div>
        <?php endif; ?>
        <?php if ($vendor['contact_email']): ?>
          <div><i class="bi bi-envelope" style="color:var(--text-muted)"></i> <a href="mailto:<?= Security::h($vendor['contact_email']) ?>"><?= Security::h($vendor['contact_email']) ?></a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Contracts Section -->
<?php
$contracts = Database::fetchAll(
    "SELECT * FROM vendor_contracts WHERE vendor_id=? ORDER BY end_date ASC NULLS LAST",
    [$vendor['id']]
);
?>
<div class="card" style="margin-top:20px">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-file-earmark-text" style="color:var(--info)"></i>
      <span class="card-title">Contracts</span>
    </div>
    <?php if (Auth::can('vendor.write')): ?>
    <div class="card-header-right">
      <a href="/vendor/<?= (int)$vendor['id'] ?>/contract/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Add Contract
      </a>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-body" style="padding:0">
    <?php if ($contracts): ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Status</th>
          <th>Value</th>
          <th>End Date</th>
          <th>Auto-Renewal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($contracts as $vc):
          $vcStatusMap = [
            'active'     => ['color'=>'var(--success)','bg'=>'var(--success-subtle)','label'=>'Active'],
            'draft'      => ['color'=>'var(--text-muted)','bg'=>'var(--bg-subtle)','label'=>'Draft'],
            'expired'    => ['color'=>'var(--danger)','bg'=>'var(--danger-subtle)','label'=>'Expired'],
            'terminated' => ['color'=>'var(--text-muted)','bg'=>'var(--surface-alt)','label'=>'Terminated'],
          ];
          $vcBadge = $vcStatusMap[$vc['status']] ?? ['color'=>'var(--text-muted)','bg'=>'var(--bg-subtle)','label'=>ucfirst($vc['status'])];
          $vcDaysLeft = $vc['end_date'] ? (int)ceil((strtotime($vc['end_date']) - time()) / 86400) : null;
          $vcEndColor = ($vc['status']==='active' && $vcDaysLeft !== null && $vcDaysLeft <= 30) ? 'var(--danger)'
                      : (($vc['status']==='active' && $vcDaysLeft !== null && $vcDaysLeft <= 60) ? 'var(--warning)' : 'inherit');
        ?>
        <tr>
          <td style="font-weight:500"><?= Security::h($vc['title']) ?><?= $vc['contract_number'] ? ' <small style="color:var(--text-muted);font-weight:400">('.Security::h($vc['contract_number']).')</small>' : '' ?></td>
          <td>
            <span class="status-chip" style="background:<?= $vcBadge['bg'] ?>;color:<?= $vcBadge['color'] ?>">
              <?= $vcBadge['label'] ?>
            </span>
          </td>
          <td style="font-size:13px">
            <?= $vc['value'] !== null ? Security::h($vc['currency']) . ' ' . number_format((float)$vc['value'], 2) : '—' ?>
          </td>
          <td style="font-size:13px;white-space:nowrap">
            <?php if ($vc['end_date']): ?>
              <span style="color:<?= $vcEndColor ?>">
                <?= date('M j, Y', strtotime($vc['end_date'])) ?>
                <?php if ($vc['status']==='active' && $vcDaysLeft !== null && $vcDaysLeft <= 60): ?>
                  <small>(<?= $vcDaysLeft ?>d)</small>
                <?php endif; ?>
              </span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if ($vc['auto_renewal']): ?>
              <span style="color:var(--success)" title="Auto-renews <?= (int)$vc['renewal_notice_days'] ?> days before expiry"><i class="bi bi-check-circle-fill"></i></span>
            <?php else: ?>
              <span style="color:var(--border)"><i class="bi bi-dash-circle"></i></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state-sm">
      <i class="bi bi-file-earmark-text" style="font-size:32px"></i>
      <p style="margin:0;font-size:14px">No contracts on file.</p>
      <?php if (Auth::can('vendor.write')): ?>
        <a href="/vendor/<?= (int)$vendor['id'] ?>/contract/create" class="btn btn-primary btn-sm" style="margin-top:12px">
          <i class="bi bi-plus-lg"></i> Add Contract
        </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Edit Modal -->
<?php if (Auth::can('vendor.write')): ?>
<div class="um-overlay" id="editModal" style="display:none">
  <div class="um-dialog" style="max-width:680px;width:100%">
    <div class="um-header">
      <span>Edit Vendor</span>
      <button class="um-close" data-close-modal="editModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="um-body">
      <form method="post" action="/vendor/<?= $vendor['id'] ?>/update">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-row">
          <div class="form-group" style="flex:2"><label class="form-label">Name *</label><input name="name" class="form-control" value="<?= Security::h($vendor['name']) ?>" required></div>
          <div class="form-group" style="flex:1"><label class="form-label">Category</label>
            <select name="category" class="form-control">
              <option value="">—</option>
              <?php foreach (['Cloud Provider','SaaS','Hardware','Professional Services','Financial','Legal','Other'] as $c): ?>
                <option value="<?= $c ?>" <?= $vendor['category']===$c?'selected':'' ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Risk Tier</label>
            <select name="risk_tier" class="form-control">
              <?php foreach (['critical','high','medium','low'] as $t): ?>
                <option value="<?= $t ?>" <?= $vendor['risk_tier']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Status</label>
            <select name="status" class="form-control">
              <?php foreach (['active','inactive','under_review','terminated'] as $s): ?>
                <option value="<?= $s ?>" <?= $vendor['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Country</label><input name="country" class="form-control" value="<?= Security::h($vendor['country'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Primary Contact</label><input name="primary_contact" class="form-control" value="<?= Security::h($vendor['primary_contact'] ?? '') ?>"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Contact Email</label><input type="email" name="contact_email" class="form-control" value="<?= Security::h($vendor['contact_email'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Website</label><input type="url" name="website" class="form-control" value="<?= Security::h($vendor['website'] ?? '') ?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Contract Start</label><input type="date" name="contract_start" class="form-control" value="<?= $vendor['contract_start'] ? date('Y-m-d', strtotime($vendor['contract_start'])) : '' ?>"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Contract End</label><input type="date" name="contract_end" class="form-control" value="<?= $vendor['contract_end'] ? date('Y-m-d', strtotime($vendor['contract_end'])) : '' ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?= Security::h($vendor['description'] ?? '') ?></textarea></div>
        <div style="display:flex;gap:20px;padding:4px 0">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px"><input type="checkbox" name="data_access" value="1" <?= $vendor['data_access']?'checked':'' ?>> Has data access</label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px"><input type="checkbox" name="critical_service" value="1" <?= $vendor['critical_service']?'checked':'' ?>> Critical service</label>
        </div>
        <div class="modal-footer">
          <button type="button" data-close-modal="editModal" class="btn btn-secondary">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Schedule Assessment Modal -->
<div class="um-overlay" id="assessModal" style="display:none">
  <div class="um-dialog" style="max-width:480px;width:100%">
    <div class="um-header">
      <span>Schedule Assessment</span>
      <button class="um-close" data-close-modal="assessModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="um-body">
      <form method="post" action="/vendor/<?= $vendor['id'] ?>/assessment">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-group"><label class="form-label">Assessment Type</label>
          <select name="assessment_type" class="form-control">
            <?php foreach (['security'=>'Security','privacy'=>'Privacy','business_continuity'=>'Business Continuity','financial'=>'Financial','operational'=>'Operational'] as $v=>$l): ?>
              <option value="<?= $v ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Scheduled Date *</label><input type="date" name="scheduled_date" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Assessor</label>
          <select name="assessed_by" class="form-control">
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['id'] ?>" <?= Auth::id()==$u['id']?'selected':'' ?>><?= Security::h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" data-close-modal="assessModal" class="btn btn-secondary">Cancel</button>
          <button type="submit" class="btn btn-primary">Schedule</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Update Assessment Modal (populated by JS) -->
<div class="um-overlay" id="updateAssessModal" style="display:none">
  <div class="um-dialog" style="max-width:520px;width:100%">
    <div class="um-header">
      <span>Update Assessment</span>
      <button class="um-close" data-close-modal="updateAssessModal"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="um-body">
      <form id="updateAssessForm" method="post">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Status</label>
            <select name="status" class="form-control">
              <?php foreach (['planned'=>'Planned','in_progress'=>'In Progress','completed'=>'Completed','overdue'=>'Overdue'] as $v=>$l): ?>
                <option value="<?= $v ?>"><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Risk Rating</label>
            <select name="risk_rating" class="form-control">
              <option value="">— None —</option>
              <?php foreach (['critical','high','medium','low','acceptable'] as $r): ?>
                <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Score (0–100)</label><input type="number" name="overall_score" class="form-control" min="0" max="100" placeholder="—"></div>
        </div>
        <div class="form-group"><label class="form-label">Findings</label><textarea name="findings" class="form-control" rows="3"></textarea></div>
        <div class="form-group"><label class="form-label">Recommendations</label><textarea name="recommendations" class="form-control" rows="3"></textarea></div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Completed Date</label><input type="date" name="completed_date" class="form-control"></div>
          <div class="form-group" style="flex:1"><label class="form-label">Next Assessment</label><input type="date" name="next_assessment_date" class="form-control"></div>
        </div>
        <div class="modal-footer">
          <button type="button" data-close-modal="updateAssessModal" class="btn btn-secondary">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
(function() {
  var inp = document.getElementById('portal-link-input');
  if (inp) inp.addEventListener('click', function() { this.select(); });
})();

function showUpdateAssessModal(assessId, currentStatus) {
  const form = document.getElementById('updateAssessForm');
  form.action = '/vendor/<?= $vendor['id'] ?>/assessment/' + assessId + '/update';
  const sel = form.querySelector('select[name="status"]');
  if (sel) { for (let o of sel.options) { o.selected = (o.value === currentStatus); } }
  showModal('updateAssessModal');
}
</script>
<?php endif; ?>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
