<?php
$pageTitle    = $package['name'];
$activeModule = 'compliance';
$breadcrumbs  = [['Compliance','/compliance'],[$package['standard_code'],null]];

// Stash CSRF token for JS-driven forms
$csrf = Security::csrfField();

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($package['name']) ?></h1>
    <p class="page-subtitle"><?= Security::h($package['standard_name']) ?><?= $package['authority'] ? ' · ' . Security::h($package['authority']) : '' ?></p>
  </div>
  <div class="page-actions">
    <a href="/compliance/gap-analysis" class="btn btn-ghost"><i class="bi bi-diagram-3"></i> Gap Analysis</a>
    <a href="/compliance/<?= (int)$package['id'] ?>/scorecard" class="btn btn-ghost"><i class="bi bi-printer"></i> Scorecard</a>
    <button class="btn btn-ghost" onclick="openModal('edit-pkg')"><i class="bi bi-pencil-fill"></i> Edit</button>
    <form method="POST" action="/compliance/<?= (int)$package['id'] ?>/delete" style="display:inline"
          onsubmit="return confirm('Permanently delete this package and all its controls? This cannot be undone.')">
      <?= Security::csrfField() ?>
      <button type="submit" class="btn btn-ghost" style="color:#dc2626"><i class="bi bi-trash3-fill"></i> Delete</button>
    </form>
    <a href="/audit/create" class="btn btn-primary"><i class="bi bi-clipboard2-plus"></i> Start Audit</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert-box success" style="margin-bottom:16px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?><?php unset($_SESSION['flash_success']); ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert-box error" style="margin-bottom:16px"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?><?php unset($_SESSION['flash_error']); ?></div>
<?php endif; ?>
<?php if (isset($_GET['created'])): ?>
  <div class="alert-box success" style="margin-bottom:16px"><i class="bi bi-check-circle-fill"></i> Package created! Add domains and controls below to get started.</div>
<?php endif; ?>

<!-- Overview cards -->
<div class="overview-bar">
  <?php
  $total     = array_sum(array_column($domains, 'child_count')) ?: 0;
  $compliant = array_sum(array_column($domains, 'compliant_count'));
  $partial   = array_sum(array_column($domains, 'partial_count'));
  $nonComp   = array_sum(array_column($domains, 'non_compliant_count'));
  $pct       = $total > 0 ? round(($compliant / $total) * 100) : 0;
  ?>
  <div class="overview-pct">
    <svg width="80" height="80" viewBox="0 0 80 80">
      <circle cx="40" cy="40" r="34" fill="none" stroke="#e2e8f0" stroke-width="8"/>
      <?php if ($total > 0): ?>
      <circle cx="40" cy="40" r="34" fill="none" stroke="#4f46e5" stroke-width="8"
        stroke-dasharray="<?= round(2*M_PI*34 * $pct/100, 2) ?> 999"
        stroke-linecap="round" transform="rotate(-90 40 40)"/>
      <?php endif; ?>
    </svg>
    <div class="overview-pct-num"><?= $pct ?>%</div>
  </div>
  <div class="overview-stats">
    <div class="ov-stat"><span class="ov-num" style="color:#4f46e5"><?= $total ?></span><span>Total Controls</span></div>
    <div class="ov-stat"><span class="ov-num" style="color:#059669"><?= $compliant ?></span><span>Compliant</span></div>
    <div class="ov-stat"><span class="ov-num" style="color:#d97706"><?= $partial ?></span><span>Partial</span></div>
    <div class="ov-stat"><span class="ov-num" style="color:#dc2626"><?= $nonComp ?></span><span>Non-compliant</span></div>
    <div class="ov-stat"><span class="ov-num" style="color:#64748b"><?= max(0,$total-$compliant-$partial-$nonComp) ?></span><span>Not Started</span></div>
  </div>
  <?php if ($package['standard_desc']): ?>
  <div class="overview-desc">
    <p><?= Security::h($package['standard_desc']) ?></p>
    <?php if ($package['standard_url']): ?>
      <a href="<?= Security::h($package['standard_url']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">
        <i class="bi bi-box-arrow-up-right"></i> Official Reference
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Filter bar -->
<div class="filter-bar card" id="domains">
  <form method="GET" class="filter-form">
    <input type="text" name="q" value="<?= Security::h($_GET['q'] ?? '') ?>" placeholder="Search controls…" class="form-control form-control-sm">
    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <option value="not_started"   <?= ($_GET['status']??'')==='not_started'  ?'selected':'' ?>>Not Started</option>
      <option value="compliant"     <?= ($_GET['status']??'')==='compliant'    ?'selected':'' ?>>Compliant</option>
      <option value="partial"       <?= ($_GET['status']??'')==='partial'      ?'selected':'' ?>>Partial</option>
      <option value="non_compliant" <?= ($_GET['status']??'')==='non_compliant'?'selected':'' ?>>Non-Compliant</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="/compliance/<?= $package['id'] ?>" class="btn btn-ghost btn-sm">Clear</a>
    <span style="flex:1"></span>
    <button type="button" class="btn btn-primary btn-sm" onclick="openModal('add-domain')">
      <i class="bi bi-plus-lg"></i> Add Domain
    </button>
  </form>
</div>

<!-- Domain list -->
<?php if (!$domains): ?>
<div class="card" style="text-align:center;padding:40px 20px;color:var(--text-muted)">
  <i class="bi bi-diagram-3" style="font-size:2.5rem;display:block;margin-bottom:12px;color:#cbd5e1"></i>
  <h3 style="margin-bottom:8px;color:var(--text)">No domains yet</h3>
  <p style="margin-bottom:16px">Domains group your controls into sections (e.g. Access Control, Risk Management).</p>
  <button class="btn btn-primary" onclick="openModal('add-domain')"><i class="bi bi-plus-lg"></i> Add First Domain</button>
</div>
<?php endif; ?>

<?php foreach ($domains as $domain): ?>
<?php
  $dTotal = (int)$domain['child_count'];
  $dComp  = (int)$domain['compliant_count'];
  $dPct   = $dTotal > 0 ? round(($dComp/$dTotal)*100) : 0;
  $dColor = $dPct >= 80 ? '#059669' : ($dPct >= 50 ? '#d97706' : '#dc2626');
?>
<div class="domain-block card" id="domain-<?= $domain['id'] ?>">
  <div class="domain-header" onclick="toggleDomain(<?= $domain['id'] ?>)" style="cursor:pointer">
    <div class="domain-header-left">
      <div class="domain-code"><?= Security::h($domain['code']) ?></div>
      <div class="domain-title"><?= Security::h($domain['title']) ?></div>
    </div>
    <div class="domain-header-right" style="display:flex;align-items:center;gap:8px">
      <?php if ($dTotal > 0): ?>
      <div class="domain-mini-stats">
        <span class="mini-stat green"><?= $dComp ?> ✓</span>
        <span class="mini-stat red"><?= $domain['non_compliant_count'] ?> ✗</span>
        <span class="mini-stat gray"><?= max(0,$dTotal-$dComp-(int)$domain['partial_count']-(int)$domain['non_compliant_count']) ?> –</span>
      </div>
      <div class="domain-pct" style="color:<?= $dColor ?>"><?= $dPct ?>%</div>
      <div class="mini-progress">
        <div style="width:<?= $dPct ?>%;background:<?= $dColor ?>;height:100%;border-radius:4px;transition:width .3s"></div>
      </div>
      <?php else: ?>
      <span class="badge badge-gray" style="font-size:11px">No controls</span>
      <?php endif; ?>
      <!-- Domain actions — stop propagation so clicks don't toggle the accordion -->
      <button class="btn btn-ghost btn-sm" title="Add Control"
              onclick="event.stopPropagation();openModal('add-ctrl-<?= $domain['id'] ?>')">
        <i class="bi bi-plus-lg"></i> Add Control
      </button>
      <button class="btn btn-ghost btn-sm" title="Edit Domain"
              onclick="event.stopPropagation();openModal('edit-domain-<?= $domain['id'] ?>')">
        <i class="bi bi-pencil-fill"></i>
      </button>
      <form method="POST" action="/compliance/<?= $package['id'] ?>/domain/<?= $domain['id'] ?>/delete" style="display:inline"
            onsubmit="event.stopPropagation();return confirm('Delete domain \'<?= Security::h(addslashes($domain['code'])) ?>\' and all its controls?')">
        <?= Security::csrfField() ?>
        <button type="submit" class="btn btn-ghost btn-sm" title="Delete Domain" style="color:#dc2626">
          <i class="bi bi-trash3-fill"></i>
        </button>
      </form>
      <i class="bi bi-chevron-down domain-chevron" id="chevron-<?= $domain['id'] ?>"></i>
    </div>
  </div>

  <div class="domain-controls" id="controls-<?= $domain['id'] ?>" style="display:none">
    <?php
    $controls = Database::fetchAll(
      "SELECT co.*, ci.status as impl_status, u.name as assignee
       FROM compliance_objectives co
       LEFT JOIN control_implementations ci ON ci.objective_id = co.id
       LEFT JOIN users u ON u.id = ci.assigned_to
       WHERE co.parent_id = ? ORDER BY co.sort_order",
      [$domain['id']]
    );
    $sq = trim($_GET['q'] ?? '');
    $sf = $_GET['status'] ?? '';
    $visibleCount = 0;
    foreach ($controls as $ctrl):
      if ($sq && stripos($ctrl['code'].$ctrl['title'], $sq) === false) continue;
      $implStatus = $ctrl['impl_status'] ?? 'not_started';
      if ($sf && $implStatus !== $sf) continue;
      $visibleCount++;
    ?>
      <div class="control-row" data-status="<?= Security::h($implStatus) ?>">
        <div class="control-row-left">
          <span class="control-status-icon status-<?= Security::h($implStatus) ?>" title="<?= ucwords(str_replace('_',' ',$implStatus)) ?>">
            <i class="bi bi-<?= statusIcon($implStatus) ?>"></i>
          </span>
          <div class="control-info">
            <span class="control-code"><?= Security::h($ctrl['code']) ?></span>
            <span class="control-title"><?= Security::h($ctrl['title']) ?></span>
            <?php if ($ctrl['assignee']): ?>
              <span class="control-assignee"><i class="bi bi-person-fill"></i> <?= Security::h($ctrl['assignee']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:4px;align-items:center">
          <a href="/compliance/<?= $package['id'] ?>/objective/<?= $ctrl['id'] ?>" class="btn btn-ghost btn-sm" title="Assess compliance status">
            <i class="bi bi-pencil-fill"></i> Assess
          </a>
          <a href="/compliance/control/<?= $ctrl['id'] ?>/test" class="btn btn-ghost btn-sm" title="Record test result" style="color:#6366f1">
            <i class="bi bi-clipboard2-check"></i> Test
          </a>
          <button class="btn btn-ghost btn-sm" title="Edit control details"
                  onclick="openModal('edit-ctrl-<?= $ctrl['id'] ?>')">
            <i class="bi bi-sliders"></i>
          </button>
          <form method="POST" action="/compliance/<?= $package['id'] ?>/control/<?= $ctrl['id'] ?>/delete" style="display:inline"
                onsubmit="return confirm('Delete control <?= Security::h(addslashes($ctrl['code'])) ?>?')">
            <?= Security::csrfField() ?>
            <button type="submit" class="btn btn-ghost btn-sm" title="Delete control" style="color:#dc2626"><i class="bi bi-trash3-fill"></i></button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!$controls || ($sq || $sf) && $visibleCount === 0): ?>
    <div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px">
      <?= ($sq || $sf) ? 'No controls match the current filter.' : 'No controls yet — click <strong>Add Control</strong> to add the first one.' ?>
    </div>
    <?php endif; ?>

    <!-- Quick add control row (inline) -->
    <div style="padding:10px 16px;border-top:1px solid var(--border-light);background:var(--bg-secondary)">
      <button class="btn btn-ghost btn-sm" onclick="openModal('add-ctrl-<?= $domain['id'] ?>')">
        <i class="bi bi-plus-lg"></i> Add Control to <?= Security::h($domain['code']) ?>
      </button>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php
function statusIcon(string $s): string {
  return match($s) {
    'compliant'      => 'check-circle-fill',
    'partial'        => 'dash-circle-fill',
    'non_compliant'  => 'x-circle-fill',
    'not_applicable' => 'slash-circle-fill',
    default          => 'circle',
  };
}
?>

<!-- ══════════════════════════════════════════════════════════════
     MODAL OVERLAY SYSTEM
════════════════════════════════════════════════════════════════ -->
<div id="modal-overlay" onclick="closeModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:16px"></div>

<!-- Edit Package -->
<div id="modal-edit-pkg" class="aegis-modal" style="display:none">
  <div class="modal-header">
    <h3>Edit Package</h3>
    <button class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
  </div>
  <form method="POST" action="/compliance/<?= (int)$package['id'] ?>/update">
    <?= Security::csrfField() ?>
    <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group" style="margin:0">
        <label class="form-label">Name <span style="color:#dc2626">*</span></label>
        <input type="text" name="name" class="form-control" required value="<?= Security::h($package['name']) ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Version</label>
        <input type="text" name="version" class="form-control" value="<?= Security::h($package['version'] ?? '1.0') ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3"><?= Security::h($package['description'] ?? '') ?></textarea>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Base Standard</label>
        <select name="standard_id" class="form-control">
          <option value="">— Custom / None —</option>
          <?php foreach (Database::fetchAll("SELECT * FROM standards WHERE is_active=TRUE ORDER BY name") as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $package['standard_id'] == $s['id'] ? 'selected' : '' ?>>
              <?= Security::h($s['name']) ?> (<?= Security::h($s['code']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    </div>
  </form>
</div>

<!-- Add Domain -->
<div id="modal-add-domain" class="aegis-modal" style="display:none">
  <div class="modal-header">
    <h3><i class="bi bi-plus-circle-fill" style="color:#4f46e5"></i> Add Domain</h3>
    <button class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
  </div>
  <form method="POST" action="/compliance/<?= (int)$package['id'] ?>/domain/add">
    <?= Security::csrfField() ?>
    <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group" style="margin:0">
        <label class="form-label">Domain Code <span style="color:#dc2626">*</span></label>
        <input type="text" name="code" class="form-control" required placeholder="e.g. AC, 5.1, PR.AC" autofocus>
        <p class="text-muted" style="font-size:12px;margin-top:4px">A short unique identifier for this domain.</p>
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Domain Title <span style="color:#dc2626">*</span></label>
        <input type="text" name="title" class="form-control" required placeholder="e.g. Access Control">
      </div>
    </div>
    <div class="modal-footer">
      <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Domain</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    </div>
  </form>
</div>

<!-- Per-domain modals: Edit Domain + Add Control -->
<?php foreach ($domains as $domain): ?>

<!-- Edit Domain: <?= $domain['id'] ?> -->
<div id="modal-edit-domain-<?= $domain['id'] ?>" class="aegis-modal" style="display:none">
  <div class="modal-header">
    <h3><i class="bi bi-pencil-fill"></i> Edit Domain</h3>
    <button class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
  </div>
  <form method="POST" action="/compliance/<?= (int)$package['id'] ?>/domain/<?= (int)$domain['id'] ?>/update">
    <?= Security::csrfField() ?>
    <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group" style="margin:0">
        <label class="form-label">Domain Code <span style="color:#dc2626">*</span></label>
        <input type="text" name="code" class="form-control" required value="<?= Security::h($domain['code']) ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Domain Title <span style="color:#dc2626">*</span></label>
        <input type="text" name="title" class="form-control" required value="<?= Security::h($domain['title']) ?>">
      </div>
    </div>
    <div class="modal-footer">
      <button type="submit" class="btn btn-primary">Save</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    </div>
  </form>
</div>

<!-- Add Control to Domain: <?= $domain['id'] ?> -->
<div id="modal-add-ctrl-<?= $domain['id'] ?>" class="aegis-modal" style="display:none">
  <div class="modal-header">
    <h3><i class="bi bi-plus-circle-fill" style="color:#059669"></i> Add Control — <?= Security::h($domain['code']) ?></h3>
    <button class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
  </div>
  <form method="POST" action="/compliance/<?= (int)$package['id'] ?>/domain/<?= (int)$domain['id'] ?>/control/add">
    <?= Security::csrfField() ?>
    <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group" style="margin:0">
        <label class="form-label">Control Code <span style="color:#dc2626">*</span></label>
        <input type="text" name="code" class="form-control" required placeholder="e.g. <?= Security::h($domain['code']) ?>.1">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Control Title <span style="color:#dc2626">*</span></label>
        <input type="text" name="title" class="form-control" required placeholder="e.g. Least Privilege Access">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Description <span class="text-muted">(optional)</span></label>
        <textarea name="description" class="form-control" rows="3" placeholder="What does this control require?"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Control</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      <a href="/compliance/import" class="btn btn-ghost" style="margin-left:auto;font-size:12px" title="Import many controls at once">
        <i class="bi bi-cloud-upload"></i> Import instead
      </a>
    </div>
  </form>
</div>

<?php
// Per-control edit modals for this domain
$ctrlsForModal = Database::fetchAll(
    "SELECT id, code, title, description FROM compliance_objectives WHERE parent_id = ? ORDER BY sort_order",
    [$domain['id']]
);
foreach ($ctrlsForModal as $cm): ?>
<!-- Edit Control: <?= $cm['id'] ?> -->
<div id="modal-edit-ctrl-<?= $cm['id'] ?>" class="aegis-modal" style="display:none">
  <div class="modal-header">
    <h3><i class="bi bi-sliders"></i> Edit Control</h3>
    <button class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
  </div>
  <form method="POST" action="/compliance/<?= (int)$package['id'] ?>/control/<?= (int)$cm['id'] ?>/update">
    <?= Security::csrfField() ?>
    <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
      <div class="form-group" style="margin:0">
        <label class="form-label">Control Code <span style="color:#dc2626">*</span></label>
        <input type="text" name="code" class="form-control" required value="<?= Security::h($cm['code']) ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Control Title <span style="color:#dc2626">*</span></label>
        <input type="text" name="title" class="form-control" required value="<?= Security::h($cm['title']) ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="3"><?= Security::h($cm['description'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button type="submit" class="btn btn-primary">Save</button>
      <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    </div>
  </form>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<script>
function toggleDomain(id) {
  const el  = document.getElementById('controls-' + id);
  const ch  = document.getElementById('chevron-' + id);
  const vis = el.style.display !== 'none';
  el.style.display = vis ? 'none' : 'block';
  ch.style.transform = vis ? '' : 'rotate(180deg)';
}
// Auto-open first domain
document.addEventListener('DOMContentLoaded', function() {
  var first = document.querySelector('.domain-block');
  if (first) { var id = first.id.replace('domain-', ''); toggleDomain(id); }
});

// Modal system
var overlay = document.getElementById('modal-overlay');
var activeModal = null;

function openModal(id) {
  if (activeModal) { activeModal.style.display = 'none'; }
  var m = document.getElementById('modal-' + id);
  if (!m) return;
  activeModal = m;
  overlay.style.display = 'flex';
  m.style.display = 'block';
  // Focus first input
  var inp = m.querySelector('input,textarea,select');
  if (inp) { setTimeout(function(){ inp.focus(); inp.select && inp.select(); }, 80); }
}
function closeModal() {
  overlay.style.display = 'none';
  if (activeModal) { activeModal.style.display = 'none'; activeModal = null; }
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeModal(); });
</script>

<style>
.aegis-modal {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 1001;
  width: 100%;
  max-width: 480px;
  background: var(--bg-card);
  border-radius: 12px;
  box-shadow: 0 20px 60px rgba(0,0,0,.25);
  overflow: hidden;
}
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
}
.modal-header h3 { margin: 0; font-size: 15px; font-weight: 700; display:flex;gap:8px;align-items:center; }
.modal-close { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 16px; padding: 4px; }
.modal-close:hover { color: var(--text); }
.modal-footer {
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 14px 20px;
  border-top: 1px solid var(--border);
  background: var(--bg-secondary);
}
@media (max-width: 520px) {
  .aegis-modal { max-width: calc(100vw - 32px); }
}
</style>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
