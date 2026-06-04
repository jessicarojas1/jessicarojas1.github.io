<?php
$csrf = Security::generateCsrfToken();
$isOwner = $dashboard['owner_id'] === Auth::id();
$metricOptions = [
    'open_risks'             => 'Open Risks',
    'non_compliant_controls' => 'Non-Compliant Controls',
    'open_incidents'         => 'Open Incidents',
    'open_issues'            => 'Open Issues',
    'overdue_policies'       => 'Overdue Policies',
    'pending_approvals'      => 'Pending Approvals',
];
$widgetTypes = [
    'stat_card'          => 'Stat Card',
    'recent_risks'       => 'Recent High Risks',
    'recent_incidents'   => 'Recent Incidents',
    'compliance_summary' => 'Compliance Summary',
    'open_issues'        => 'Open Issues',
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($dashboard['name']) ?></h1>
    <?php if ($dashboard['description']): ?>
    <p class="page-subtitle"><?= Security::h($dashboard['description']) ?></p>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <?php if ($dashboard['is_shared']): ?><span class="badge badge-info">Shared</span><?php endif; ?>
    <?php if ($isOwner): ?>
    <button id="btnOpenWidget" class="btn btn-secondary btn-sm"><i class="bi bi-plus-lg"></i> Add Widget</button>
    <form method="POST" action="/dashboards/<?= (int)$dashboard['id'] ?>/delete" data-confirm="Delete this dashboard?" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i></button>
    </form>
    <?php endif; ?>
    <a href="/dashboards" class="btn btn-secondary btn-sm">Back</a>
  </div>
</div>

<?php if (empty($widgets)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-grid-3x3-gap" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No Widgets Yet</h3>
  <?php if ($isOwner): ?>
  <p style="color:var(--text-muted);margin-bottom:20px;">Add widgets to build your custom view.</p>
  <button id="btnOpenWidget2" class="btn btn-primary">Add First Widget</button>
  <?php else: ?>
  <p style="color:var(--text-muted);">This dashboard has no widgets.</p>
  <?php endif; ?>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px;">
  <?php foreach ($widgets as $w): ?>
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <h3 class="card-title"><?= Security::h($w['title']) ?></h3>
      <?php if ($isOwner): ?>
      <form method="POST" action="/dashboards/<?= (int)$dashboard['id'] ?>/widget/<?= (int)$w['id'] ?>/remove" style="margin:0;" data-confirm="Remove widget?">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button type="submit" style="background:none;border:none;cursor:pointer;color:var(--text-muted);"><i class="bi bi-x-lg"></i></button>
      </form>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($w['widget_type'] === 'stat_card'): ?>
        <div style="font-size:2.5rem;font-weight:700;color:var(--primary);"><?= (int)($w['data'] ?? 0) ?></div>
        <div style="font-size:0.85rem;color:var(--text-muted);"><?= Security::h($metricOptions[$w['config']['metric'] ?? ''] ?? $w['config']['metric'] ?? '') ?></div>

      <?php elseif ($w['widget_type'] === 'recent_risks' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No open risks.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $r): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
            <a href="/risk/<?= (int)$r['id'] ?>" style="font-size:0.875rem;font-weight:500;"><?= Security::h($r['title']) ?></a>
            <span class="badge badge-danger"><?= (int)$r['score'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'recent_incidents' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No incidents.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $inc): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
            <a href="/incident/<?= (int)$inc['id'] ?>" style="font-size:0.875rem;"><?= Security::h($inc['title']) ?></a>
            <span class="badge <?= $inc['severity']==='critical'?'badge-danger':($inc['severity']==='high'?'badge-warning':'badge-secondary') ?>"><?= ucfirst($inc['severity']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'compliance_summary' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No compliance packages.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php foreach ($w['data'] as $cp):
            $pct = $cp['total'] > 0 ? round($cp['compliant']/$cp['total']*100) : 0;
          ?>
          <div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span style="font-size:0.8rem;font-weight:500;"><?= Security::h($cp['name']) ?></span>
              <span style="font-size:0.8rem;color:var(--text-muted);"><?= $pct ?>%</span>
            </div>
            <div style="background:var(--border);border-radius:4px;height:6px;">
              <div style="width:<?= $pct ?>%;background:<?= $pct>=80?'var(--success)':($pct>=50?'var(--warning)':'var(--danger)') ?>;height:100%;border-radius:4px;"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php elseif ($w['widget_type'] === 'open_issues' && is_array($w['data'])): ?>
        <?php if (empty($w['data'])): ?><p style="color:var(--text-muted);font-size:0.875rem;">No open issues.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($w['data'] as $iss): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);">
            <a href="/issue/<?= (int)$iss['id'] ?>" style="font-size:0.875rem;"><?= Security::h($iss['title']) ?></a>
            <span class="badge <?= $iss['severity']==='critical'?'badge-danger':($iss['severity']==='high'?'badge-warning':'badge-info') ?>"><?= ucfirst($iss['severity']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      <?php else: ?>
        <p style="color:var(--text-muted);font-size:0.875rem;">No data available.</p>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($isOwner): ?>
<!-- Add Widget Modal -->
<div id="addWidgetModal" style="display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
  <div style="background:var(--card-bg);border-radius:12px;padding:28px;width:460px;max-width:95vw;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">Add Widget</h3>
      <button id="btnCloseWidget" style="background:none;border:none;cursor:pointer;font-size:1.25rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/dashboards/<?= (int)$dashboard['id'] ?>/add-widget">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-group">
        <label class="form-label">Widget Type</label>
        <select name="widget_type" class="form-control" id="widgetTypeSelect">
          <?php foreach ($widgetTypes as $v => $l): ?>
          <option value="<?= $v ?>"><?= Security::h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Widget Title</label>
        <input type="text" name="title" class="form-control" placeholder="Display title">
      </div>
      <div class="form-group" id="metricGroup">
        <label class="form-label">Metric</label>
        <select name="metric" class="form-control">
          <?php foreach ($metricOptions as $v => $l): ?>
          <option value="<?= $v ?>"><?= Security::h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">Add Widget</button>
        <button type="button" id="btnCancelWidget" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script nonce="<?= Security::nonce() ?>">
(function() {
  var modal = document.getElementById('addWidgetModal');
  function open()  { modal.style.display = 'flex'; }
  function close() { modal.style.display = 'none'; }

  document.getElementById('btnOpenWidget').addEventListener('click', open);
  var btn2 = document.getElementById('btnOpenWidget2');
  if (btn2) btn2.addEventListener('click', open);
  document.getElementById('btnCloseWidget').addEventListener('click', close);
  document.getElementById('btnCancelWidget').addEventListener('click', close);
  modal.addEventListener('click', function(e) { if (e.target === modal) close(); });

  document.querySelectorAll('form[data-confirm]').forEach(function(f) {
    f.addEventListener('submit', function(e) {
      if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
  });

  function toggleMetric() {
    var t = document.getElementById('widgetTypeSelect').value;
    document.getElementById('metricGroup').style.display = t === 'stat_card' ? 'block' : 'none';
  }
  document.getElementById('widgetTypeSelect').addEventListener('change', toggleMetric);
  toggleMetric();
})();
</script>
<?php endif; ?>
