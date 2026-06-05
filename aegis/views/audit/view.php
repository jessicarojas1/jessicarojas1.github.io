<?php
$pageTitle    = $audit['name'];
$activeModule = 'audit';
$breadcrumbs  = [['Audits','/audit'],[$audit['name'],null]];
ob_start();
?>

<?php if (!empty($_GET['completed'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Audit completed with score <?= round($audit['score']) ?>%.</div><?php endif; ?>
<?php if (!empty($_GET['updated'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Audit updated.</div><?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($audit['name']) ?></h1>
    <p class="page-subtitle">
      <?= Security::h($audit['package_name'] ?? 'No package') ?> ·
      <?= ucfirst(str_replace('_',' ',$audit['audit_type'])) ?> ·
      <?= $audit['scheduled_date'] ? 'Scheduled '.date('M j, Y', strtotime($audit['scheduled_date'])) : '' ?>
    </p>
  </div>
  <div class="page-actions">
    <?php if ($audit['status'] === 'planned'): ?>
      <form method="POST" action="/audit/<?= $audit['id'] ?>/update" style="display:inline">
        <?= Security::csrfField() ?>
        <input type="hidden" name="status" value="in_progress">
        <button class="btn btn-primary"><i class="bi bi-play-fill"></i> Start Audit</button>
      </form>
    <?php elseif ($audit['status'] === 'in_progress'): ?>
      <form method="POST" action="/audit/<?= $audit['id'] ?>/complete">
        <?= Security::csrfField() ?>
        <button class="btn btn-success" data-confirm-click="Complete this audit?"><i class="bi bi-check-lg"></i> Complete Audit</button>
      </form>
    <?php endif; ?>
    <?php if ($audit['audit_type'] === 'internal'): ?>
      <a href="#report8d" class="btn btn-secondary"><i class="bi bi-clipboard2-check-fill"></i> 8D Report</a>
    <?php endif; ?>
    <a href="/audit/<?= $audit['id'] ?>/export" class="btn btn-secondary"><i class="bi bi-file-earmark-zip"></i> Export Package</a>
    <a href="/audit" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<!-- KPI Summary bar -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px">
  <!-- Status card -->
  <div class="card" style="padding:16px;text-align:center">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">Status</div>
    <span class="badge badge-lg badge-<?= $audit['status'] ?>"><?= ucfirst(str_replace('_',' ',$audit['status'])) ?></span>
  </div>
  <!-- Score card -->
  <div class="card" style="padding:16px;text-align:center">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">Score</div>
    <div style="font-size:24px;font-weight:800;color:<?= $audit['score'] !== null ? ($audit['score'] >= 80 ? '#16a34a' : ($audit['score'] >= 60 ? '#d97706' : '#dc2626')) : 'var(--text-muted)' ?>"><?= $audit['score'] !== null ? round($audit['score']).'%' : '—' ?></div>
  </div>
  <!-- Lead Auditor card -->
  <div class="card" style="padding:16px;text-align:center">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">Lead Auditor</div>
    <div style="font-weight:600;font-size:13px"><?= Security::h($audit['auditor_name'] ?? 'Unassigned') ?></div>
  </div>
  <!-- Progress card with progress bar -->
  <?php $total=max(1,(int)($summary['total']??0)); $assessed=$total-($summary['not_assessed']??0); $pct=round($assessed/$total*100); ?>
  <div class="card" style="padding:16px;text-align:center">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px">Progress</div>
    <div style="font-size:16px;font-weight:700;margin-bottom:6px"><?= $assessed ?>/<?= $total ?></div>
    <div style="height:6px;background:var(--bg-subtle);border-radius:99px;overflow:hidden">
      <div style="height:100%;width:<?= $pct ?>%;background:var(--primary);border-radius:99px"></div>
    </div>
  </div>
  <!-- Findings summary card -->
  <div class="card" style="padding:16px">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:8px;text-align:center">Findings</div>
    <div style="display:flex;flex-direction:column;gap:4px">
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px">
        <span style="display:flex;align-items:center;gap:5px;color:#16a34a"><i class="bi bi-check-circle-fill"></i>Compliant</span>
        <strong><?= $summary['compliant'] ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px">
        <span style="display:flex;align-items:center;gap:5px;color:#d97706"><i class="bi bi-dash-circle-fill"></i>Partial</span>
        <strong><?= $summary['partial'] ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px">
        <span style="display:flex;align-items:center;gap:5px;color:#dc2626"><i class="bi bi-x-circle-fill"></i>Non-Compliant</span>
        <strong><?= $summary['non_compliant'] ?></strong>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px">
        <span style="display:flex;align-items:center;gap:5px;color:var(--text-muted)"><i class="bi bi-circle"></i>Not Assessed</span>
        <strong><?= $summary['not_assessed'] ?></strong>
      </div>
    </div>
  </div>
</div>

<!-- Audit items by domain -->
<?php foreach ($groupedByDomain as $domain => $items): ?>
<div class="card" style="margin-bottom:16px">
  <div class="card-header">
    <h3 class="card-title"><?= Security::h($domain) ?></h3>
    <?php $domainTotal = count($items); $domainComp = count(array_filter($items, fn($i)=>$i['status']==='compliant')); ?>
    <span class="badge badge-<?= $domainComp===$domainTotal ? 'green' : 'gray' ?>"><?= $domainComp ?>/<?= $domainTotal ?> compliant</span>
  </div>
  <div class="card-body p0">
    <?php foreach ($items as $item): ?>
    <div class="audit-item-row item-<?= $item['status'] ?>" id="item-<?= $item['id'] ?>" style="padding:14px 16px;border-bottom:1px solid var(--border-light);display:flex;flex-direction:column;gap:0">
      <!-- Top row: code + title + status selector + edit button -->
      <div style="display:flex;align-items:flex-start;gap:12px">
        <!-- Left: code badge + title -->
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
            <span style="font-family:monospace;font-size:11px;font-weight:700;background:var(--bg-subtle);border:1px solid var(--border);padding:1px 7px;border-radius:4px;color:var(--text-muted)"><?= Security::h($item['code']) ?></span>
            <!-- Status indicator dot -->
            <?php
            $dotColors = ['compliant'=>'#16a34a','partial'=>'#d97706','non_compliant'=>'#dc2626','not_assessed'=>'#9ca3af','not_applicable'=>'#6b7280'];
            $dotColor = $dotColors[$item['status']] ?? '#9ca3af';
            ?>
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $dotColor ?>;flex-shrink:0;display:inline-block"></span>
          </div>
          <!-- Title — truncated with expand -->
          <div class="audit-ctrl-title" style="font-size:13px;font-weight:600;color:var(--text);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;cursor:pointer" data-click="toggleCtrlTitle" data-arg="<?= $item['id'] ?>">
            <?= Security::h($item['title']) ?>
          </div>
        </div>
        <!-- Right: status select + edit button -->
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
          <?php
          $statusBgs = ['not_assessed'=>['#f9fafb','#6b7280'],'compliant'=>['#f0fdf4','#16a34a'],'partial'=>['#fffbeb','#d97706'],'non_compliant'=>['#fef2f2','#dc2626'],'not_applicable'=>['#f4f4f5','#71717a']];
          [$sBg,$sFg] = $statusBgs[$item['status']] ?? ['#f9fafb','#6b7280'];
          ?>
          <select class="status-select-inline" data-change="saveAuditItem" data-args='[<?= $item['id'] ?>,<?= $audit['id'] ?>]' data-item="<?= $item['id'] ?>"
                  style="background:<?= $sBg ?>;color:<?= $sFg ?>;border:1px solid <?= $sFg ?>44;border-radius:99px;padding:4px 10px;font-size:12px;font-weight:600;cursor:pointer;appearance:none;-webkit-appearance:none;outline:none">
            <?php foreach (['not_assessed'=>'Not Assessed','compliant'=>'Compliant','partial'=>'Partial','non_compliant'=>'Non-Compliant','not_applicable'=>'N/A'] as $val=>$label): ?>
              <option value="<?= $val ?>" <?= $item['status']===$val?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-ghost btn-sm" data-click="toggleFinding" data-arg="<?= $item['id'] ?>" title="Add finding details" style="padding:4px 8px">
            <i class="bi bi-<?= ($item['finding'] || $item['evidence']) ? 'pencil-fill' : 'pencil' ?>" style="font-size:13px;<?= ($item['finding'] || $item['evidence']) ? 'color:var(--primary)' : '' ?>"></i>
          </button>
        </div>
      </div>
      <!-- Finding panel (hidden by default) -->
      <div class="audit-finding-panel" id="finding-<?= $item['id'] ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
        <form class="finding-form" data-submit="saveFinding" data-args='[<?= $item['id'] ?>,<?= $audit['id'] ?>]' enctype="multipart/form-data">
          <?= Security::csrfField() ?>
          <input type="hidden" name="status" class="finding-status-input" value="<?= Security::h($item['status']) ?>">
          <div class="form-row">
            <div class="form-group flex-1">
              <label class="form-label">Finding</label>
              <textarea name="finding" class="form-control" rows="2" placeholder="Describe the finding..."><?= Security::h($item['finding'] ?? '') ?></textarea>
            </div>
            <div class="form-group flex-1">
              <label class="form-label">Evidence Notes</label>
              <textarea name="evidence" class="form-control" rows="2" placeholder="Evidence reviewed..."><?= Security::h($item['evidence'] ?? '') ?></textarea>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Risk Level</label>
              <select name="risk_level" class="form-control">
                <option value="">None</option>
                <?php foreach (['low','medium','high','critical'] as $rl): ?>
                  <option value="<?= $rl ?>" <?= ($item['risk_level']??'')===$rl?'selected':'' ?>><?= ucfirst($rl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group flex-2">
              <label class="form-label">Remediation</label>
              <input type="text" name="remediation" class="form-control" placeholder="Required remediation actions..." value="<?= Security::h($item['remediation'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Upload Evidence File</label>
            <input type="file" name="evidence_file[]" multiple class="form-control" accept="image/*,.pdf,.doc,.docx,.xlsx,.csv,.txt,.png,.jpg,.jpeg">
          </div>
          <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> Save Finding</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if ($audit['audit_type'] === 'internal'): ?>
<!-- 8D Internal Audit Report -->
<div class="card" style="margin-top:24px" id="report8d">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h3 class="card-title" style="margin:0">
      <i class="bi bi-clipboard2-check-fill" style="color:var(--primary);margin-right:6px"></i>
      8D Internal Audit Report
    </h3>
    <div style="display:flex;gap:8px">
      <button class="btn btn-ghost btn-sm" id="btn8dClear"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
      <button class="btn btn-secondary btn-sm" id="btn8dPrint"><i class="bi bi-printer-fill"></i> Print Report</button>
    </div>
  </div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:18px" id="eightDBody">

    <?php
    $eightD = [
      ['D1', 'Team Formation',           'List all team members involved in this internal audit investigation, including lead auditor, reviewers, and subject-matter experts.', 'rows' => 3],
      ['D2', 'Problem Description',      'Describe the audit scope, objectives, and summary of non-compliant findings identified during this audit.', 'rows' => 4],
      ['D3', 'Containment Actions',      'Immediate short-term actions taken to contain the impact of identified non-conformities and prevent further compliance drift.', 'rows' => 3],
      ['D4', 'Root Cause Analysis',      'Identify the root cause(s) of each non-compliant finding. Use 5-Why or Fishbone analysis as appropriate.', 'rows' => 4],
      ['D5', 'Corrective Actions',       'Define permanent corrective actions to address each root cause. Include specific tasks, owners, and target completion dates.', 'rows' => 4],
      ['D6', 'Implementation & Verification', 'Document the implementation of corrective actions, verification evidence, and confirmation that they are effective.', 'rows' => 3],
      ['D7', 'Prevention Measures',      'Systemic measures to prevent recurrence across other similar processes, departments, or frameworks.', 'rows' => 3],
      ['D8', 'Team Recognition & Closure','Summarise the outcome, acknowledge the audit team\'s effort, and formally close the 8D report.', 'rows' => 2],
    ];
    foreach ($eightD as $i => $d):
    ?>
    <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden">
      <div style="padding:10px 16px;background:var(--bg-secondary);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:var(--primary);color:#fff;font-weight:800;font-size:13px;flex-shrink:0"><?= $d[0] ?></span>
        <strong style="font-size:14px;color:var(--text)"><?= $d[1] ?></strong>
      </div>
      <div style="padding:12px 16px">
        <p style="margin:0 0 8px;font-size:12px;color:var(--text-muted)"><?= $d[2] ?></p>
        <textarea class="form-control eightd-field" id="eightd_<?= $i ?>" rows="<?= $d['rows'] ?>"
                  placeholder="<?= $d[0] ?>: <?= $d[1] ?> — enter details here…"
                  style="font-size:13px"></textarea>
      </div>
    </div>
    <?php endforeach; ?>

  </div>
</div>
<?php endif; ?>

<script nonce="<?= Security::nonce() ?>">
const _auditId8d = '<?= (int)$audit['id'] ?>';
const _8dKey     = 'aegis_8d_audit_' + _auditId8d;

// Persist 8D fields in localStorage
function save8d() {
  const vals = {};
  document.querySelectorAll('.eightd-field').forEach(function(ta) { vals[ta.id] = ta.value; });
  localStorage.setItem(_8dKey, JSON.stringify(vals));
}
function load8d() {
  try {
    const vals = JSON.parse(localStorage.getItem(_8dKey) || '{}');
    document.querySelectorAll('.eightd-field').forEach(function(ta) {
      if (vals[ta.id]) ta.value = vals[ta.id];
    });
  } catch(e) {}
}

// Auto-fill D2 with non-compliant findings on first open
function autoFill8D() {
  const d2 = document.getElementById('eightd_1');
  if (!d2 || d2.value) return;
  const auditName   = <?= json_encode($audit['name']) ?>;
  const scheduled   = <?= json_encode($audit['scheduled_date'] ? date('M j, Y', strtotime($audit['scheduled_date'])) : 'Not scheduled') ?>;
  const ncCount     = parseInt('<?= $summary['non_compliant'] ?? 0 ?>');
  const partCount   = parseInt('<?= $summary['partial'] ?? 0 ?>');
  const totalCount  = parseInt('<?= $summary['total'] ?? 0 ?>');
  d2.value = 'Audit: ' + auditName + '\nScheduled: ' + scheduled +
    '\n\nTotal items assessed: ' + totalCount +
    '\nNon-Compliant findings: ' + ncCount +
    '\nPartial findings: ' + partCount +
    '\n\nSee audit items above for detailed control-level findings.';
}

load8d();
autoFill8D();

document.querySelectorAll('.eightd-field').forEach(function(ta) {
  ta.addEventListener('input', save8d);
});

var clear8dBtn = document.getElementById('btn8dClear');
if (clear8dBtn) clear8dBtn.addEventListener('click', function() {
  if (!confirm('Reset all 8D fields? This cannot be undone.')) return;
  localStorage.removeItem(_8dKey);
  document.querySelectorAll('.eightd-field').forEach(function(ta) { ta.value = ''; });
  autoFill8D();
});

var print8dBtn = document.getElementById('btn8dPrint');
if (print8dBtn) print8dBtn.addEventListener('click', function() {
  window.print();
});

// Audit item functions
function toggleFinding(id) {
  const panel = document.getElementById('finding-' + id);
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}

function toggleCtrlTitle(id) {
  var el = document.querySelector('#item-' + id + ' .audit-ctrl-title');
  if (!el) return;
  if (el.style.webkitLineClamp === 'unset' || el.style['-webkit-line-clamp'] === 'unset') {
    el.style.webkitLineClamp = '2';
    el.style.overflow = 'hidden';
  } else {
    el.style.webkitLineClamp = 'unset';
    el.style.overflow = 'visible';
  }
}

function saveAuditItem(itemId, auditId) {
  const select = this;
  const status = select.value;
  const formData = new FormData();
  formData.append('status', status);
  formData.append('csrf_token', '<?= Security::generateCsrfToken() ?>');

  const panel = document.getElementById('finding-' + itemId);
  if (panel) panel.querySelector('.finding-status-input').value = status;

  fetch('/audit/' + auditId + '/item/' + itemId + '/update', { method:'POST', body:formData })
    .then(r => r.json())
    .then(data => {
      const row = document.getElementById('item-' + itemId);
      row.className = 'audit-item-row item-' + data.status;
      const statusBgs = {not_assessed:['#f9fafb','#6b7280'],compliant:['#f0fdf4','#16a34a'],partial:['#fffbeb','#d97706'],non_compliant:['#fef2f2','#dc2626'],not_applicable:['#f4f4f5','#71717a']};
      const [bg,fg] = statusBgs[data.status] || ['#f9fafb','#6b7280'];
      select.style.background = bg;
      select.style.color = fg;
      select.style.borderColor = fg + '44';
    });
}

function saveFinding(e, itemId, auditId) {
  e.preventDefault();
  const form = e.target;
  const fd   = new FormData(form);
  fetch('/audit/' + auditId + '/item/' + itemId + '/update', { method:'POST', body:fd })
    .then(r => r.json())
    .then(() => {
      form.closest('.audit-finding-panel').style.display = 'none';
    });
}
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
