<?php
$breadcrumbs = $breadcrumbs ?? [['Playbooks', null]];
$categoryColors = [
    'general'         => 'var(--primary)',
    'ransomware'      => 'var(--danger)',
    'data_breach'     => 'var(--warning)',
    'ddos'            => 'var(--info)',
    'phishing'        => 'var(--secondary)',
    'insider_threat'  => '#db2777',
    'system_failure'  => 'var(--text-muted)',
    'compliance'      => 'var(--success)',
];
$severityColors = [
    'critical' => 'var(--danger)',
    'high'     => 'var(--warning)',
    'medium'   => 'var(--info)',
    'low'      => 'var(--success)',
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Incident Response Playbooks</h1>
    <p class="page-subtitle">Step-by-step response procedures that can be attached to incidents and worked as checklists.</p>
  </div>
  <?php if (Auth::can('incident.write')): ?>
    <div class="page-actions">
      <a href="/playbooks/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New Playbook</a>
    </div>
  <?php endif; ?>
</div>

<?php if (empty($playbooks)): ?>
  <div class="card">
    <div class="card-body" style="text-align:center;padding:64px 20px">
      <i class="bi bi-journal-bookmark-fill" style="font-size:48px;color:var(--border);display:block;margin-bottom:16px"></i>
      <h3 style="margin:0 0 8px;color:var(--text-muted)">No playbooks yet</h3>
      <p style="color:var(--text-muted);margin:0 0 20px">Create your first incident response playbook to standardize how your team handles security events.</p>
      <?php if (Auth::can('incident.write')): ?>
        <a href="/playbooks/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create Playbook</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px">
    <?php foreach ($playbooks as $pb):
      $catKey   = strtolower(str_replace([' ','-'], '_', $pb['category']));
      $catColor = $categoryColors[$catKey] ?? 'var(--primary)';
      $catLabel = ucwords(str_replace('_', ' ', $pb['category']));
      $sevColor = $severityColors[strtolower($pb['severity_filter'] ?? '')] ?? null;
      $isActive = (bool)$pb['is_active'];
    ?>
      <div class="card" style="border-left:4px solid <?= $isActive ? $catColor : 'var(--text-muted)' ?>;position:relative">
        <?php if (!$isActive): ?>
          <div style="position:absolute;top:10px;right:10px">
            <span class="status-chip" style="background:var(--text-muted)20;color:var(--text-muted);border:1px solid var(--text-muted)40;font-size:10px">Inactive</span>
          </div>
        <?php endif; ?>
        <div class="card-body">
          <div style="margin-bottom:10px">
            <a href="/playbooks/<?= (int)$pb['id'] ?>" style="font-size:16px;font-weight:600;color:var(--text-primary);text-decoration:none">
              <?= Security::h($pb['title']) ?>
            </a>
          </div>

          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
            <span class="status-chip" style="background:<?= $catColor ?>20;color:<?= $catColor ?>;border:1px solid <?= $catColor ?>40">
              <?= Security::h($catLabel) ?>
            </span>
            <?php if ($pb['severity_filter'] && $sevColor): ?>
              <span class="status-chip" style="background:<?= $sevColor ?>20;color:<?= $sevColor ?>;border:1px solid <?= $sevColor ?>40">
                <?= Security::h(ucfirst($pb['severity_filter'])) ?> severity
              </span>
            <?php endif; ?>
          </div>

          <?php if ($pb['description']): ?>
            <p style="font-size:13px;color:var(--text-muted);margin:0 0 12px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
              <?= Security::h($pb['description']) ?>
            </p>
          <?php endif; ?>

          <div style="display:flex;gap:16px;font-size:12px;color:var(--text-muted);margin-bottom:14px">
            <span><i class="bi bi-list-ol"></i> <?= (int)$pb['step_count'] ?> step<?= $pb['step_count'] != 1 ? 's' : '' ?></span>
            <span><i class="bi bi-play-circle"></i> <?= (int)$pb['run_count'] ?> run<?= $pb['run_count'] != 1 ? 's' : '' ?></span>
            <?php if ($pb['creator_name']): ?>
              <span><i class="bi bi-person"></i> <?= Security::h($pb['creator_name']) ?></span>
            <?php endif; ?>
          </div>

          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <a href="/playbooks/<?= (int)$pb['id'] ?>" class="btn btn-sm btn-secondary">
              <i class="bi bi-eye"></i> View
            </a>
            <?php if (Auth::can('incident.write')): ?>
              <form method="post" action="/playbooks/<?= (int)$pb['id'] ?>/toggle" style="display:inline" data-confirm="<?= $isActive ? 'Deactivate' : 'Activate' ?> this playbook?">
                <?= Security::csrfField() ?>
                <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-ghost' : 'btn-primary' ?>">
                  <i class="bi bi-<?= $isActive ? 'pause-circle' : 'play-circle' ?>"></i>
                  <?= $isActive ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
