<?php
$pageTitle    = 'Risk Register';
$activeModule = 'risk';
$breadcrumbs  = [['Risk Register', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Risk Register</h1>
    <p class="page-subtitle">Track, assess, and treat organizational risks</p>
  </div>
  <div class="page-actions">
    <button data-print class="btn btn-ghost no-print"><i class="bi bi-printer"></i> Print / Export</button>
    <a href="/risk/dashboard" class="btn btn-ghost no-print"><i class="bi bi-speedometer2"></i> Dashboard</a>
    <a href="/risk/matrix" class="btn btn-ghost no-print"><i class="bi bi-grid-3x3-gap-fill"></i> Matrix View</a>
    <a href="/risk/create" class="btn btn-danger no-print"><i class="bi bi-plus-lg"></i> Log Risk</a>
  </div>
</div>

<?php if (!empty($_GET['deleted'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Risk deleted.</div><?php endif; ?>
<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>

<!-- Risk Appetite Summary (compact pill row) -->
<?php
$_appetiteRows = [];
try {
    $_appetiteRows = Database::fetchAll("SELECT category, appetite, max_score FROM risk_appetite ORDER BY category LIMIT 10") ?: [];
} catch (Throwable $_e) { $_appetiteRows = []; }
if (!empty($_appetiteRows)):
$_appColors = ['zero'=>'#dc2626','low'=>'#d97706','moderate'=>'#2563eb','high'=>'#16a34a'];
?>
<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:16px;padding:10px 14px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:10px">
  <span style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;flex-shrink:0">
    <i class="bi bi-speedometer2" style="color:var(--primary);margin-right:3px"></i>Risk Appetite:
  </span>
  <?php foreach ($_appetiteRows as $_ar):
    $_c = $_appColors[$_ar['appetite']] ?? '#71717a';
  ?>
  <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:20px;background:<?= $_c ?>15;color:<?= $_c ?>;border:1px solid <?= $_c ?>35;white-space:nowrap">
    <?= Security::h($_ar['category']) ?>: <?= ucfirst($_ar['appetite']) ?><?= $_ar['max_score'] !== null ? ' (≤'.Security::h($_ar['max_score']).')' : '' ?>
  </span>
  <?php endforeach; ?>
  <?php if (Auth::can('admin') || Auth::role() === 'admin'): ?>
  <a href="/admin/risk-appetite" style="margin-left:auto;font-size:11px;color:var(--primary);text-decoration:none;white-space:nowrap;flex-shrink:0">
    <i class="bi bi-pencil-fill"></i> Edit
  </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Summary KPIs -->
<div class="risk-kpi-grid">
  <div class="risk-kpi critical"><i class="bi bi-exclamation-octagon-fill"></i><span class="kpi-num"><?= $summary['critical'] ?></span><span>Critical</span></div>
  <div class="risk-kpi high"><i class="bi bi-exclamation-triangle-fill"></i><span class="kpi-num"><?= $summary['high'] ?></span><span>High</span></div>
  <div class="risk-kpi medium"><i class="bi bi-exclamation-circle-fill"></i><span class="kpi-num"><?= $summary['medium'] ?></span><span>Medium</span></div>
  <div class="risk-kpi low"><i class="bi bi-info-circle-fill"></i><span class="kpi-num"><?= $summary['low'] ?></span><span>Low</span></div>
  <div class="risk-kpi open"><i class="bi bi-circle-fill"></i><span class="kpi-num"><?= $summary['open'] ?></span><span>Open</span></div>
  <div class="risk-kpi" style="color:#16a34a"><i class="bi bi-eye-fill"></i><span class="kpi-num"><?= $summary['monitoring'] ?></span><span>Monitoring</span></div>
  <div class="risk-kpi accepted" style="color:#d97706"><i class="bi bi-check-circle-fill"></i><span class="kpi-num"><?= $summary['accepted'] ?></span><span>Accepted</span></div>
  <div class="risk-kpi" style="color:var(--text-muted)"><i class="bi bi-lock-fill"></i><span class="kpi-num"><?= $summary['closed'] ?></span><span>Closed</span></div>
</div>

<?php
$_filterCount = count(array_filter([
    $_GET['category'] ?? '',
    $_GET['status'] ?? '',
    $_GET['treatment'] ?? '',
    $_GET['source'] ?? '',
    $_GET['level'] ?? '',
]));
?>
<div class="filter-toolbar">
  <div class="filter-popover-wrap">
    <button type="button" class="filter-btn <?= $_filterCount ? 'active' : '' ?>" data-filter-toggle>
      <i class="bi bi-funnel"></i> Filters
      <?php if ($_filterCount): ?><span class="filter-count"><?= $_filterCount ?></span><?php endif; ?>
    </button>
    <div class="filter-popover <?= $_filterCount ? 'open' : '' ?>">
      <form method="GET" action="/risk">
        <div class="filter-popover-grid">
          <div class="filter-field">
            <label>Category</label>
            <select name="category">
              <option value="">All categories</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($_GET['category']??'')==$cat['id']?'selected':'' ?>><?= Security::h($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-field">
            <label>Status</label>
            <select name="status">
              <option value="">All statuses</option>
              <?php foreach (['open'=>'Open','in_review'=>'In Review','monitoring'=>'Monitoring','accepted'=>'Accepted','closed'=>'Closed','transferred'=>'Transferred'] as $sv=>$sl): ?>
                <option value="<?= $sv ?>" <?= ($_GET['status']??'')===$sv?'selected':'' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-field">
            <label>Treatment</label>
            <select name="treatment">
              <option value="">All strategies</option>
              <?php foreach (['mitigate'=>'Mitigate','accept'=>'Accept','transfer'=>'Transfer','avoid'=>'Avoid'] as $sv=>$sl): ?>
                <option value="<?= $sv ?>" <?= ($_GET['treatment']??'')===$sv?'selected':'' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-field">
            <label>Source</label>
            <select name="source">
              <option value="">All sources</option>
              <?php foreach (['strategic'=>'Strategic','operational'=>'Operational','financial'=>'Financial','compliance'=>'Compliance','technology'=>'Technology','reputational'=>'Reputational','external'=>'External','people'=>'People','project'=>'Project'] as $sv=>$sl): ?>
                <option value="<?= $sv ?>" <?= ($_GET['source']??'')===$sv?'selected':'' ?>><?= $sl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-field">
            <label>Risk Level</label>
            <select name="level">
              <option value="">All levels</option>
              <option value="critical" <?= ($_GET['level']??'')==='critical'?'selected':'' ?>>Critical (&gt;14)</option>
              <option value="high" <?= ($_GET['level']??'')==='high'?'selected':'' ?>>High (10–14)</option>
              <option value="medium" <?= ($_GET['level']??'')==='medium'?'selected':'' ?>>Medium (5–9)</option>
              <option value="low" <?= ($_GET['level']??'')==='low'?'selected':'' ?>>Low (≤4)</option>
            </select>
          </div>
        </div>
        <div class="filter-popover-actions">
          <button type="submit" class="btn btn-primary btn-sm">Apply</button>
          <a href="/risk" class="btn btn-ghost btn-sm">Clear</a>
        </div>
      </form>
    </div>
  </div>
  <?php if ($_filterCount): ?>
  <div class="filter-chips">
    <?php if (!empty($_GET['category'])): ?>
      <?php $__cat = array_values(array_filter($categories, fn($c)=>$c['id']==$_GET['category']))[0] ?? null; ?>
      <?php if ($__cat): ?>
      <span class="filter-chip"><?= Security::h($__cat['name']) ?> <button class="filter-chip-remove" onclick="window.location='/risk?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{category:''}}).toString().replace('category=&','').replace(/&?category=$/,'')">×</button></span>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($_GET['status'])): ?>
      <?php $__sl = ['open'=>'Open','in_review'=>'In Review','monitoring'=>'Monitoring','accepted'=>'Accepted','closed'=>'Closed','transferred'=>'Transferred']; ?>
      <span class="filter-chip">Status: <?= Security::h($__sl[$_GET['status']] ?? $_GET['status']) ?> <a href="<?= Security::h(preg_replace('/[?&]status=[^&]*/','', $_SERVER['REQUEST_URI'])) ?>" class="filter-chip-remove">×</a></span>
    <?php endif; ?>
    <?php if (!empty($_GET['treatment'])): ?>
      <?php $__tl = ['mitigate'=>'Mitigate','accept'=>'Accept','transfer'=>'Transfer','avoid'=>'Avoid']; ?>
      <span class="filter-chip">Strategy: <?= Security::h($__tl[$_GET['treatment']] ?? $_GET['treatment']) ?> <a href="<?= Security::h(preg_replace('/[?&]treatment=[^&]*/','', $_SERVER['REQUEST_URI'])) ?>" class="filter-chip-remove">×</a></span>
    <?php endif; ?>
    <?php if (!empty($_GET['source'])): ?>
      <span class="filter-chip">Source: <?= Security::h(ucfirst($_GET['source'])) ?> <a href="<?= Security::h(preg_replace('/[?&]source=[^&]*/','', $_SERVER['REQUEST_URI'])) ?>" class="filter-chip-remove">×</a></span>
    <?php endif; ?>
    <?php if (!empty($_GET['level'])): ?>
      <span class="filter-chip">Level: <?= Security::h(ucfirst($_GET['level'])) ?> <a href="<?= Security::h(preg_replace('/[?&]level=[^&]*/','', $_SERVER['REQUEST_URI'])) ?>" class="filter-chip-remove">×</a></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div id="bulkBar" style="display:none;background:var(--info-subtle);border:1px solid #bae6fd80;border-radius:8px;padding:12px 16px;margin-bottom:12px;align-items:center;gap:12px;flex-wrap:wrap">
  <span id="bulkCount" style="font-weight:600;color:#0369a1">0 selected</span>
  <form method="POST" action="/risk/bulk-update" id="bulkForm" style="display:flex;gap:8px;align-items:center">
    <?= Security::csrfField() ?>
    <div id="bulkIdsContainer"></div>
    <select name="bulk_action" class="form-control" style="width:auto">
      <option value="">Choose action…</option>
      <optgroup label="Set Status">
        <option value="status_open">Open</option>
        <option value="status_in_review">In Review</option>
        <option value="status_monitoring">Monitoring</option>
        <option value="status_accepted">Accepted</option>
        <option value="status_closed">Closed</option>
        <option value="status_transferred">Transferred</option>
      </optgroup>
      <optgroup label="Set Strategy">
        <option value="strategy_mitigate">Mitigate</option>
        <option value="strategy_accept">Accept</option>
        <option value="strategy_transfer">Transfer</option>
        <option value="strategy_avoid">Avoid</option>
      </optgroup>
    </select>
    <button type="submit" class="btn btn-primary btn-sm" id="bulkApplyBtn">Apply</button>
    <button type="button" class="btn btn-ghost btn-sm" data-click="clearSelection">Clear</button>
  </form>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table risk-table">
      <thead>
        <tr>
          <th style="width:32px"><input type="checkbox" id="selectAll" data-change="toggleAllRisks"></th>
          <th>Risk ID</th><th>Title</th><th>Category</th>
          <th>Likelihood</th><th>Impact</th><th>Score</th><th>Level</th>
          <th>Residual</th><th>Status</th><th>Strategy</th><th>Assessment</th><th>Owner</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($risks): foreach ($risks as $risk):
          $level = riskLevel((int)$risk['inherent_score']);
          $resScore = $risk['residual_score'] ?? $risk['inherent_score'];
          $resLevel = riskLevel((int)$resScore);
        ?>
          <tr>
            <td><input type="checkbox" class="risk-cb" value="<?= $risk['id'] ?>" data-change="updateBulk"></td>
            <td><span class="mono text-sm"><?= Security::h($risk['risk_id'] ?? '—') ?></span></td>
            <td><a href="/risk/<?= $risk['id'] ?>" class="table-link fw-500"><?= Security::h($risk['title']) ?></a></td>
            <td>
              <?php if ($risk['category_name']): ?>
                <span class="category-dot" style="background:<?= Security::h($risk['category_color'] ?? '#666') ?>"></span>
                <?= Security::h($risk['category_name']) ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><div class="score-cell likelihood-<?= $risk['likelihood'] ?>"><?= $risk['likelihood'] ?></div></td>
            <td><div class="score-cell impact-<?= $risk['impact'] ?>"><?= $risk['impact'] ?></div></td>
            <td><strong class="score-num"><?= $risk['inherent_score'] ?></strong></td>
            <td><span class="risk-badge risk-<?= strtolower($level) ?>"><?= $level ?></span></td>
            <td>
              <?php if ($risk['residual_likelihood']): ?>
                <span class="risk-badge risk-<?= strtolower($resLevel) ?>"><?= $resScore ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $risk['status'] ?>"><?= ucfirst(str_replace('_',' ',$risk['status'])) ?></span></td>
            <td>
              <?php
              $strategies = json_decode($risk['treatment_strategies'] ?? '[]', true) ?: [];
              $stratColors = ['mitigate'=>'#2563eb','accept'=>'#b45309','transfer'=>'var(--secondary)','avoid'=>'#dc2626'];
              foreach ($strategies as $strat):
                $sc = $stratColors[$strat] ?? '#71717a';
              ?>
              <span style="font-size:11px;font-weight:600;padding:2px 7px;border-radius:20px;background:<?= $sc ?>18;color:<?= $sc ?>;border:1px solid <?= $sc ?>30;white-space:nowrap;margin-right:2px">
                <?= ucfirst($strat) ?>
              </span>
              <?php endforeach; if (empty($strategies)): ?>—<?php endif; ?>
            </td>
            <td>
              <?php
              $aStatus = $risk['assessment_status'] ?? 'draft';
              $aColors = ['draft'=>['#71717a','#f4f4f5'],'pending_review'=>['#d97706','#fffbeb'],'approved'=>['#16a34a','#f0fdf4']];
              [$aFg,$aBg] = $aColors[$aStatus] ?? $aColors['draft'];
              $aLabel = ['draft'=>'Draft','pending_review'=>'Pending Review','approved'=>'Approved'][$aStatus] ?? ucfirst($aStatus);
              ?>
              <span style="font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;background:<?= $aBg ?>;color:<?= $aFg ?>;white-space:nowrap"><?= $aLabel ?></span>
            </td>
            <td><?= Security::h($risk['owner_name'] ?? '—') ?></td>
            <td>
              <div class="action-btns">
                <a href="/risk/<?= $risk['id'] ?>" class="btn btn-ghost btn-sm" title="View"><i class="bi bi-eye"></i></a>
              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="14" class="empty-row">
            <div class="empty-state-sm"><i class="bi bi-shield-check"></i><p>No risks match your filters. <a href="/risk/create">Log a risk</a>.</p></div>
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
function updateBulk() {
  var checked = document.querySelectorAll('.risk-cb:checked');
  var bar = document.getElementById('bulkBar');
  bar.style.display = checked.length > 0 ? 'flex' : 'none';
  document.getElementById('bulkCount').textContent = checked.length + ' selected';
}
function toggleAllRisks(e) {
  var cb = e && e.target ? e.target : this;
  document.querySelectorAll('.risk-cb').forEach(function(c){ c.checked = cb.checked; });
  updateBulk();
}
function injectIds() {
  var container = document.getElementById('bulkIdsContainer');
  container.innerHTML = '';
  document.querySelectorAll('.risk-cb:checked').forEach(function(c) {
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = c.value;
    container.appendChild(inp);
  });
}
function clearSelection() {
  document.querySelectorAll('.risk-cb').forEach(function(c){ c.checked = false; });
  document.getElementById('selectAll').checked = false;
  updateBulk();
}
// Guard bulk form submit
(function() {
  var form = document.getElementById('bulkForm');
  if (!form) return;
  form.addEventListener('submit', function(e) {
    var action = document.querySelector('[name="bulk_action"]').value;
    if (!action) { e.preventDefault(); alert('Please choose an action.'); return; }
    if (!document.querySelectorAll('.risk-cb:checked').length) { e.preventDefault(); return; }
    injectIds();
  });
})();
</script>
<?php
function riskLevel(int $score): string {
  return $score > 14 ? 'Critical' : ($score > 9 ? 'High' : ($score > 4 ? 'Medium' : 'Low'));
}
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
