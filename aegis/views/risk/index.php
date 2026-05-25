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
    <a href="/risk/matrix" class="btn btn-ghost"><i class="bi bi-grid-3x3-gap-fill"></i> Matrix View</a>
    <a href="/risk/create" class="btn btn-danger"><i class="bi bi-plus-lg"></i> Log Risk</a>
  </div>
</div>

<?php if (!empty($_GET['deleted'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> Risk deleted.</div><?php endif; ?>
<?php if (!empty($_SESSION['flash_success'])): ?><div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($_SESSION['flash_success']) ?></div><?php unset($_SESSION['flash_success']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>

<!-- Summary KPIs -->
<div class="risk-kpi-grid">
  <div class="risk-kpi critical"><i class="bi bi-exclamation-octagon-fill"></i><span class="kpi-num"><?= $summary['critical'] ?></span><span>Critical</span></div>
  <div class="risk-kpi high"><i class="bi bi-exclamation-triangle-fill"></i><span class="kpi-num"><?= $summary['high'] ?></span><span>High</span></div>
  <div class="risk-kpi medium"><i class="bi bi-exclamation-circle-fill"></i><span class="kpi-num"><?= $summary['medium'] ?></span><span>Medium</span></div>
  <div class="risk-kpi low"><i class="bi bi-info-circle-fill"></i><span class="kpi-num"><?= $summary['low'] ?></span><span>Low</span></div>
  <div class="risk-kpi open"><i class="bi bi-circle-fill"></i><span class="kpi-num"><?= $summary['open'] ?></span><span>Open</span></div>
  <div class="risk-kpi mitigated"><i class="bi bi-check-circle-fill"></i><span class="kpi-num"><?= $summary['mitigated'] ?></span><span>Mitigated</span></div>
</div>

<!-- Filters -->
<div class="filter-bar card">
  <form method="GET" class="filter-form">
    <select name="category" class="form-control form-control-sm" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= ($_GET['category']??'')==$cat['id']?'selected':'' ?>><?= Security::h($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <?php foreach (['open','accepted','mitigated','closed','transferred'] as $s): ?>
        <option value="<?= $s ?>" <?= ($_GET['status']??'')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="level" class="form-control form-control-sm" onchange="this.form.submit()">
      <option value="">All levels</option>
      <option value="critical" <?= ($_GET['level']??'')==='critical'?'selected':'' ?>>Critical (>14)</option>
      <option value="high" <?= ($_GET['level']??'')==='high'?'selected':'' ?>>High (10-14)</option>
      <option value="medium" <?= ($_GET['level']??'')==='medium'?'selected':'' ?>>Medium (5-9)</option>
      <option value="low" <?= ($_GET['level']??'')==='low'?'selected':'' ?>>Low (≤4)</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="/risk" class="btn btn-ghost btn-sm">Clear</a>
  </form>
</div>

<div id="bulkBar" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:12px;align-items:center;gap:12px;flex-wrap:wrap">
  <span id="bulkCount" style="font-weight:600;color:#0369a1">0 selected</span>
  <form method="POST" action="/risk/bulk-update" id="bulkForm" style="display:flex;gap:8px;align-items:center">
    <?= Security::csrfField() ?>
    <div id="bulkIdsContainer"></div>
    <select name="bulk_action" class="form-control" style="width:auto">
      <option value="">Choose action…</option>
      <optgroup label="Set Status">
        <option value="status_open">Mark Open</option>
        <option value="status_closed">Mark Closed</option>
        <option value="status_transferred">Mark Transferred</option>
      </optgroup>
      <optgroup label="Set Treatment">
        <option value="treatment_accept">Accept Risk</option>
        <option value="treatment_mitigate">Set to Mitigate</option>
      </optgroup>
    </select>
    <button type="submit" class="btn btn-primary btn-sm" onclick="return injectIds()">Apply</button>
    <button type="button" class="btn btn-ghost btn-sm" onclick="clearSelection()">Clear</button>
  </form>
</div>

<div class="card">
  <div class="card-body p0">
    <table class="table risk-table">
      <thead>
        <tr>
          <th style="width:32px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
          <th>Risk ID</th><th>Title</th><th>Category</th>
          <th>Likelihood</th><th>Impact</th><th>Score</th><th>Level</th>
          <th>Residual</th><th>Status</th><th>Owner</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($risks): foreach ($risks as $risk):
          $level = riskLevel((int)$risk['inherent_score']);
          $resScore = $risk['residual_score'] ?? $risk['inherent_score'];
          $resLevel = riskLevel((int)$resScore);
        ?>
          <tr>
            <td><input type="checkbox" class="risk-cb" value="<?= $risk['id'] ?>" onchange="updateBulk()"></td>
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
            <td><span class="badge badge-<?= $risk['status'] ?>"><?= ucfirst($risk['status']) ?></span></td>
            <td><?= Security::h($risk['owner_name'] ?? '—') ?></td>
            <td>
              <div class="action-btns">
                <a href="/risk/<?= $risk['id'] ?>" class="btn btn-ghost btn-sm" title="View"><i class="bi bi-eye"></i></a>
              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="12" class="empty-row">
            <div class="empty-state-sm"><i class="bi bi-shield-check"></i><p>No risks match your filters. <a href="/risk/create">Log a risk</a>.</p></div>
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function updateBulk() {
  var checked = document.querySelectorAll('.risk-cb:checked');
  var bar = document.getElementById('bulkBar');
  bar.style.display = checked.length > 0 ? 'flex' : 'none';
  document.getElementById('bulkCount').textContent = checked.length + ' selected';
}
function toggleAll(cb) {
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
  var action = document.querySelector('[name="bulk_action"]').value;
  if (!action) { alert('Please choose an action.'); return false; }
  return document.querySelectorAll('.risk-cb:checked').length > 0;
}
function clearSelection() {
  document.querySelectorAll('.risk-cb').forEach(function(c){ c.checked = false; });
  document.getElementById('selectAll').checked = false;
  updateBulk();
}
</script>
<?php
function riskLevel(int $score): string {
  return $score > 14 ? 'Critical' : ($score > 9 ? 'High' : ($score > 4 ? 'Medium' : 'Low'));
}
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
