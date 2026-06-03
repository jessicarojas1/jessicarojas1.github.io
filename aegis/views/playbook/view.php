<?php
$categoryColors = [
    'general'         => '#6366f1',
    'ransomware'      => '#dc2626',
    'data_breach'     => '#d97706',
    'ddos'            => '#0284c7',
    'phishing'        => '#7c3aed',
    'insider_threat'  => '#db2777',
    'system_failure'  => '#64748b',
    'compliance'      => '#059669',
];
$severityColors = [
    'critical' => '#dc2626',
    'high'     => '#d97706',
    'medium'   => '#0284c7',
    'low'      => '#059669',
];
$catKey   = strtolower(str_replace([' ','-'], '_', $playbook['category']));
$catColor = $categoryColors[$catKey] ?? '#6366f1';
$catLabel = ucwords(str_replace('_', ' ', $playbook['category']));
$isActive = (bool)$playbook['is_active'];
$sevColor = $severityColors[strtolower($playbook['severity_filter'] ?? '')] ?? null;
?>

<div class="page-header">
  <div>
    <h1 class="page-title" style="margin-bottom:8px"><?= Security::h($playbook['title']) ?></h1>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span class="status-chip" style="background:<?= $catColor ?>20;color:<?= $catColor ?>;border:1px solid <?= $catColor ?>40">
        <?= Security::h($catLabel) ?>
      </span>
      <?php if ($playbook['severity_filter'] && $sevColor): ?>
        <span class="status-chip" style="background:<?= $sevColor ?>20;color:<?= $sevColor ?>;border:1px solid <?= $sevColor ?>40">
          <?= Security::h(ucfirst($playbook['severity_filter'])) ?> severity
        </span>
      <?php endif; ?>
      <span class="status-chip" style="background:<?= $isActive ? '#05966920' : '#94a3b820' ?>;color:<?= $isActive ? '#059669' : '#94a3b8' ?>;border:1px solid <?= $isActive ? '#05966940' : '#94a3b840' ?>">
        <?= $isActive ? 'Active' : 'Inactive' ?>
      </span>
    </div>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('incident.write')): ?>
      <form method="post" action="/playbooks/<?= (int)$playbook['id'] ?>/toggle" style="display:inline"
            data-confirm="<?= $isActive ? 'Deactivate' : 'Activate' ?> this playbook?">
        <?= Security::csrfField() ?>
        <button type="submit" class="btn <?= $isActive ? 'btn-secondary' : 'btn-primary' ?>">
          <i class="bi bi-<?= $isActive ? 'pause-circle' : 'play-circle' ?>"></i>
          <?= $isActive ? 'Deactivate' : 'Activate' ?>
        </button>
      </form>
    <?php endif; ?>
    <a href="/playbooks" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<div class="two-col-layout">

  <!-- Left: Description + Steps -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <?php if ($playbook['description']): ?>
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-file-text" style="color:var(--primary)"></i>
          <span class="card-title">Description</span>
        </div>
      </div>
      <div class="card-body">
        <p style="white-space:pre-wrap;margin:0"><?= Security::h($playbook['description']) ?></p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Steps List -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-list-ol" style="color:var(--primary)"></i>
          <span class="card-title">Response Steps</span>
        </div>
        <div class="card-header-right">
          <span style="font-size:12px;color:var(--text-muted)"><?= count($steps) ?> step<?= count($steps) != 1 ? 's' : '' ?></span>
        </div>
      </div>
      <div class="card-body" style="padding:0">
        <?php if (empty($steps)): ?>
          <div style="padding:24px;text-align:center;color:var(--text-muted)">No steps defined yet.</div>
        <?php else: ?>
          <?php foreach ($steps as $idx => $step): ?>
            <div style="padding:16px 20px;<?= $idx > 0 ? 'border-top:1px solid var(--border)' : '' ?>">
              <div style="display:flex;gap:12px">
                <div style="width:28px;height:28px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0">
                  <?= (int)$step['step_number'] ?>
                </div>
                <div style="flex:1">
                  <div style="font-weight:600;margin-bottom:4px"><?= Security::h($step['title']) ?></div>
                  <?php if ($step['description']): ?>
                    <p style="font-size:13px;color:var(--text-muted);white-space:pre-wrap;margin:0 0 8px"><?= Security::h($step['description']) ?></p>
                  <?php endif; ?>
                  <div style="display:flex;gap:12px;flex-wrap:wrap;font-size:12px">
                    <?php if ($step['owner_role']): ?>
                      <span style="color:var(--text-muted)"><i class="bi bi-person"></i> <?= Security::h($step['owner_role']) ?></span>
                    <?php endif; ?>
                    <?php if ($step['due_minutes']): ?>
                      <?php
                      $mins = (int)$step['due_minutes'];
                      if ($mins >= 1440) {
                          $dueLabel = round($mins / 1440, 1) . ' day' . (round($mins/1440,1) != 1 ? 's' : '');
                      } elseif ($mins >= 60) {
                          $dueLabel = round($mins / 60, 1) . ' hr' . (round($mins/60,1) != 1 ? 's' : '');
                      } else {
                          $dueLabel = $mins . ' min';
                      }
                      ?>
                      <span style="color:#d97706"><i class="bi bi-clock"></i> Due within <?= Security::h($dueLabel) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Runs Table -->
    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-play-circle" style="color:#059669"></i>
          <span class="card-title">Recent Runs</span>
        </div>
      </div>
      <div class="card-body" style="padding:0">
        <?php if (empty($runs)): ?>
          <div style="padding:24px;text-align:center;color:var(--text-muted)">
            <i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:8px;opacity:.4"></i>
            This playbook has not been used in any incidents yet.
          </div>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Incident</th>
                <th>Started By</th>
                <th>Started At</th>
                <th>Completed At</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($runs as $run): ?>
                <tr>
                  <td><a href="/incident/<?= (int)$run['incident_id'] ?>" style="color:var(--primary)"><?= Security::h($run['incident_title']) ?></a></td>
                  <td><?= Security::h($run['started_by_name'] ?? '—') ?></td>
                  <td class="text-sm text-muted"><?= date('M j, Y g:ia', strtotime($run['started_at'])) ?></td>
                  <td class="text-sm text-muted"><?= $run['completed_at'] ? date('M j, Y g:ia', strtotime($run['completed_at'])) : '—' ?></td>
                  <td>
                    <?php if ($run['completed_at']): ?>
                      <span class="status-chip" style="background:#05966920;color:#059669;border:1px solid #05966940">Complete</span>
                    <?php else: ?>
                      <span class="status-chip" style="background:#d9770620;color:#d97706;border:1px solid #d9770640">In Progress</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Right: Metadata + Usage Info -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <div class="card">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-info-circle" style="color:var(--primary)"></i>
          <span class="card-title">Playbook Details</span>
        </div>
      </div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <?php $rows = [
            ['Category',      Security::h($catLabel)],
            ['Severity Filter', $playbook['severity_filter'] ? '<span class="status-chip" style="background:' . ($sevColor??'#64748b') . '20;color:' . ($sevColor??'#64748b') . '">' . Security::h(ucfirst($playbook['severity_filter'])) . '</span>' : 'Any'],
            ['Steps',         count($steps)],
            ['Runs',          count($runs)],
            ['Status',        $isActive ? '<span class="status-chip" style="background:#05966920;color:#059669">Active</span>' : '<span class="status-chip" style="background:#94a3b820;color:#94a3b8">Inactive</span>'],
            ['Created By',    Security::h($playbook['creator_name'] ?? '—')],
            ['Created',       date('M j, Y', strtotime($playbook['created_at']))],
            ['Updated',       date('M j, Y', strtotime($playbook['updated_at']))],
          ]; foreach ($rows as [$label, $val]): ?>
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:8px 0;color:var(--text-muted);width:110px"><?= $label ?></td>
            <td style="padding:8px 0"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>

    <!-- Testing Note -->
    <div class="card" style="border-left:4px solid #6366f1">
      <div class="card-body">
        <div style="display:flex;gap:10px;align-items:flex-start">
          <i class="bi bi-info-circle-fill" style="color:#6366f1;font-size:18px;flex-shrink:0;margin-top:1px"></i>
          <div>
            <div style="font-weight:600;margin-bottom:4px">Using This Playbook</div>
            <p style="font-size:13px;color:var(--text-muted);margin:0">
              To use this playbook, open an incident and scroll to the <strong>Playbooks</strong> section at the bottom.
              Select this playbook and click <em>Start Playbook</em> to attach it and begin tracking step completion.
            </p>
          </div>
        </div>
      </div>
    </div>

  </div>

</div>
