<?php
$pageTitle    = $process['process_code'] . ' — ' . $process['name'];
$activeModule = 'processes';
$breadcrumbs  = [['Processes', '/processes'], [$process['process_code'], null]];
ob_start();
$relLabels = ['related_policy'=>'Policy','related_procedure'=>'Procedure','related_control'=>'Control','related_risk'=>'Risk','related_department'=>'Department'];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($process['name']) ?> <?= View::statusBadge($process['status']) ?></h1>
    <p class="page-subtitle"><span class="chip"><?= Security::h($process['process_code']) ?></span> Version <?= Security::h($process['version']) ?></p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('process.edit')): ?><a href="/processes/<?= (int)$process['id'] ?>/edit" class="btn btn-primary"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
  <div>
    <?php if ($process['description']): ?>
    <div class="card" style="margin-bottom:18px"><div class="card-body"><p style="margin:0;color:var(--text-muted)"><?= Security::h($process['description']) ?></p></div></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-diagram-3"></i> Process Flow</span></div></div>
      <div class="card-body">
        <?php if (trim((string)$process['diagram']) !== ''): ?>
          <pre class="prose" style="white-space:pre-wrap;margin:0"><?= Security::h($process['diagram']) ?></pre>
        <?php else: ?>
          <div class="empty-state-sm">No process flow has been described yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-arrow-left-right"></i> Lifecycle</span></div></div>
      <div class="card-body">
        <p class="form-hint" style="margin-top:0">Current status: <?= View::statusBadge($process['status']) ?></p>
        <p class="form-hint" style="margin-bottom:0">Status changes are made from the <?php if (Auth::can('process.edit')): ?><a href="/processes/<?= (int)$process['id'] ?>/edit">edit screen</a><?php else: ?>edit screen<?php endif; ?>. Allowed transitions: draft &rarr; in review &rarr; published &rarr; retired.</p>
      </div>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:18px">
      <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> Metadata</span></div></div>
      <div class="card-body">
        <div class="meta-grid" style="grid-template-columns:1fr 1fr">
          <div class="meta-item"><div class="meta-label">Owner</div><div class="meta-value"><?= Security::h($process['owner_name'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Department</div><div class="meta-value"><?= Security::h($process['department'] ?: '—') ?></div></div>
          <div class="meta-item"><div class="meta-label">Space</div><div class="meta-value"><?= $process['space_id'] ? '<a href="/spaces/' . (int)$process['space_id'] . '">' . Security::h($process['space_key']) . '</a>' : '—' ?></div></div>
          <div class="meta-item"><div class="meta-label">Version</div><div class="meta-value"><?= Security::h($process['version']) ?></div></div>
          <div class="meta-item"><div class="meta-label">Status</div><div class="meta-value"><?= View::statusBadge($process['status']) ?></div></div>
          <div class="meta-item"><div class="meta-label">Created</div><div class="meta-value"><?= View::fmtDate($process['created_at']) ?></div></div>
        </div>
      </div>
    </div>

    <?php if ($relations): ?>
    <div class="card"><div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-link-45deg"></i> Relationships</span></div></div><div class="card-body">
      <?php foreach ($relations as $r): ?><div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border-light)"><span class="form-hint"><?= Security::h($relLabels[$r['relation_type']] ?? $r['relation_type']) ?></span><span style="font-size:.85rem;font-weight:500"><?= Security::h($r['target_label']) ?></span></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
