<?php
$pageTitle    = $package['name'];
$activeModule = 'compliance';
$breadcrumbs  = [['Compliance','/compliance'],[$package['standard_code'],null]];

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($package['name']) ?></h1>
    <p class="page-subtitle"><?= Security::h($package['standard_name']) ?> · <?= Security::h($package['authority'] ?? '') ?></p>
  </div>
  <div class="page-actions">
    <a href="/audit/create" class="btn btn-primary"><i class="bi bi-clipboard2-plus"></i> Start Audit</a>
  </div>
</div>

<!-- Overview cards -->
<div class="overview-bar">
  <?php
  $total     = array_sum(array_column($domains, 'child_count')) ?: 1;
  $compliant = array_sum(array_column($domains, 'compliant_count'));
  $partial   = array_sum(array_column($domains, 'partial_count'));
  $nonComp   = array_sum(array_column($domains, 'non_compliant_count'));
  $pct       = round(($compliant / $total) * 100);
  ?>
  <div class="overview-pct">
    <svg width="80" height="80" viewBox="0 0 80 80">
      <circle cx="40" cy="40" r="34" fill="none" stroke="#e2e8f0" stroke-width="8"/>
      <circle cx="40" cy="40" r="34" fill="none" stroke="#4f46e5" stroke-width="8"
        stroke-dasharray="<?= round(2*M_PI*34 * $pct/100, 2) ?> 999"
        stroke-linecap="round" transform="rotate(-90 40 40)"/>
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

<!-- AI Gap Analysis panel (lazy-loaded) -->
<div class="card" id="aiPanel" style="margin-bottom:16px;border-left:3px solid #6366f1">
  <div class="card-header" style="cursor:pointer;display:flex;justify-content:space-between;align-items:center"
       onclick="loadAiSuggestions(<?= (int)$package['id'] ?>)">
    <div style="display:flex;align-items:center;gap:10px">
      <i class="bi bi-stars" style="color:#6366f1;font-size:18px"></i>
      <h3 style="margin:0">AI Gap Analysis</h3>
    </div>
    <span id="aiToggleIcon" class="text-muted text-sm">Click to analyze</span>
  </div>
  <div id="aiPanelBody" style="display:none">
    <div class="card-body" id="aiContent">
      <div style="text-align:center;padding:24px;color:#9ca3af">
        <i class="bi bi-hourglass-split" style="font-size:24px;display:block;margin-bottom:8px"></i>
        Analyzing compliance gaps…
      </div>
    </div>
  </div>
</div>
<script>
var _aiLoaded = false;
function loadAiSuggestions(pkgId) {
  var panel = document.getElementById('aiPanelBody');
  var icon  = document.getElementById('aiToggleIcon');
  if (panel.style.display === 'block') {
    panel.style.display = 'none'; icon.textContent = 'Click to analyze'; return;
  }
  panel.style.display = 'block';
  if (_aiLoaded) return;
  icon.textContent = 'Analyzing…';
  fetch('/compliance/' + pkgId + '/ai-suggestions')
    .then(function(r){ return r.json(); })
    .then(function(data) {
      _aiLoaded = true;
      var c = document.getElementById('aiContent');
      if (!data.ai_enabled || !data.suggestions || !data.suggestions.length) {
        c.innerHTML = '<p class="text-muted" style="padding:16px">AI analysis is not configured. Set <code>ai_provider</code> and <code>ai_api_key</code> in Admin → System Settings to enable gap suggestions.</p>';
        icon.textContent = 'Not configured';
        return;
      }
      icon.textContent = data.suggestions.length + ' suggestions';
      var html = '';
      if (data.narrative) {
        html += '<div style="padding:16px 20px;background:#f8f7ff;border-bottom:1px solid #e5e7eb;color:#374151;line-height:1.6">' + data.narrative.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
      }
      html += '<ul style="margin:0;padding:16px 20px 16px 36px;display:flex;flex-direction:column;gap:8px">';
      data.suggestions.forEach(function(s, i) {
        html += '<li style="color:#374151;line-height:1.5"><span style="color:#6366f1;font-weight:600">' + (i+1) + '.</span> ' + s.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</li>';
      });
      html += '</ul>';
      c.innerHTML = html;
    })
    .catch(function() {
      document.getElementById('aiContent').innerHTML = '<p class="text-muted" style="padding:16px">Failed to load AI suggestions.</p>';
      icon.textContent = 'Error';
    });
}
</script>

<!-- Filter bar -->
<div class="filter-bar card">
  <form method="GET" class="filter-form">
    <input type="text" name="q" value="<?= Security::h($_GET['q'] ?? '') ?>" placeholder="Search controls..." class="form-control form-control-sm">
    <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <option value="not_started" <?= ($_GET['status']??'')==='not_started'?'selected':'' ?>>Not Started</option>
      <option value="compliant" <?= ($_GET['status']??'')==='compliant'?'selected':'' ?>>Compliant</option>
      <option value="partial" <?= ($_GET['status']??'')==='partial'?'selected':'' ?>>Partial</option>
      <option value="non_compliant" <?= ($_GET['status']??'')==='non_compliant'?'selected':'' ?>>Non-Compliant</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="/compliance/<?= $package['id'] ?>" class="btn btn-ghost btn-sm">Clear</a>
  </form>
</div>

<!-- Domain accordion -->
<?php foreach ($domains as $domain): ?>
<?php
  $dTotal = (int)$domain['child_count'];
  $dComp  = (int)$domain['compliant_count'];
  $dPct   = $dTotal > 0 ? round(($dComp/$dTotal)*100) : 0;
  $dColor = $dPct >= 80 ? '#059669' : ($dPct >= 50 ? '#d97706' : '#dc2626');
?>
<div class="domain-block card" id="domain-<?= $domain['id'] ?>">
  <div class="domain-header" onclick="toggleDomain(<?= $domain['id'] ?>)">
    <div class="domain-header-left">
      <div class="domain-code"><?= Security::h($domain['code']) ?></div>
      <div class="domain-title"><?= Security::h($domain['title']) ?></div>
    </div>
    <div class="domain-header-right">
      <div class="domain-mini-stats">
        <span class="mini-stat green"><?= $dComp ?> ✓</span>
        <span class="mini-stat red"><?= $domain['non_compliant_count'] ?> ✗</span>
        <span class="mini-stat gray"><?= max(0,$dTotal-$dComp-$domain['partial_count']-$domain['non_compliant_count']) ?> –</span>
      </div>
      <div class="domain-pct" style="color:<?= $dColor ?>"><?= $dPct ?>%</div>
      <div class="mini-progress">
        <div style="width:<?= $dPct ?>%;background:<?= $dColor ?>;height:100%;border-radius:4px;transition:width .3s"></div>
      </div>
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
    foreach ($controls as $ctrl):
      if ($sq && stripos($ctrl['code'].$ctrl['title'], $sq) === false) continue;
      $implStatus = $ctrl['impl_status'] ?? 'not_started';
      if ($sf && $implStatus !== $sf) continue;
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
        <a href="/compliance/<?= $package['id'] ?>/objective/<?= $ctrl['id'] ?>" class="btn btn-ghost btn-sm">
          <i class="bi bi-pencil-fill"></i> Assess
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php
function statusIcon(string $s): string {
  return match($s) {
    'compliant'     => 'check-circle-fill',
    'partial'       => 'dash-circle-fill',
    'non_compliant' => 'x-circle-fill',
    'not_applicable'=> 'slash-circle-fill',
    default         => 'circle',
  };
}
?>

<script>
function toggleDomain(id) {
  const el  = document.getElementById('controls-' + id);
  const ch  = document.getElementById('chevron-' + id);
  const vis = el.style.display !== 'none';
  el.style.display = vis ? 'none' : 'block';
  ch.style.transform = vis ? '' : 'rotate(180deg)';
}
// Auto-open first domain
document.addEventListener('DOMContentLoaded', () => {
  const first = document.querySelector('.domain-block');
  if (first) {
    const id = first.id.replace('domain-', '');
    toggleDomain(id);
  }
});
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
