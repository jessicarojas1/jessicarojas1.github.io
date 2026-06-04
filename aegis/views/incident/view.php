<?php
$sevColors = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#0284c7','low'=>'#059669'];
$statusColors = ['open'=>'#dc2626','investigating'=>'#d97706','contained'=>'var(--secondary)','resolved'=>'#059669','closed'=>'#64748b'];
$sevColor = $sevColors[$incident['severity']] ?? '#64748b';
$stColor  = $statusColors[$incident['status']] ?? '#64748b';
$pageTitle    = 'Incident: ' . $incident['incident_number'];
$activeModule = 'incident';
$breadcrumbs  = [['Incidents','/incident'],[$incident['incident_number'],null]];
ob_start();

// ── SLA data ──────────────────────────────────────────────────────────────
$slaPolicy = Database::fetchOne(
    "SELECT * FROM incident_sla_policies WHERE severity=?", [$incident['severity']]
);
$slaEvents = Database::fetchAll(
    "SELECT * FROM incident_sla_events WHERE incident_id=? ORDER BY occurred_at", [$incident['id']]
);
$acknowledgedAt = null;
$resolvedAt     = null;
foreach ($slaEvents as $ev) {
    if ($ev['event_type'] === 'acknowledged') $acknowledgedAt = $ev['occurred_at'];
    if ($ev['event_type'] === 'resolved')     $resolvedAt     = $ev['occurred_at'];
}
// SLA status helper (inline)
$calcSlaStatus = function(?string $startedAt, ?string $eventAt, ?int $hoursAllowed): string {
    if (!$startedAt || !$hoursAllowed) return 'n/a';
    if ($eventAt) return 'met';
    $elapsed = (time() - strtotime($startedAt)) / 3600;
    if ($elapsed > $hoursAllowed) return 'breached';
    if ($elapsed > $hoursAllowed * 0.75) return 'at_risk';
    return 'on_track';
};
$ackStatus = $calcSlaStatus($incident['created_at'], $acknowledgedAt, $slaPolicy['acknowledge_hours'] ?? null);
$resStatus = $calcSlaStatus($incident['created_at'], $resolvedAt,     $slaPolicy['resolve_hours'] ?? null);
$slaBadgeStyle = function(string $status): string {
    $map = [
        'on_track' => ['#059669', 'On Track'],
        'at_risk'  => ['#d97706', 'At Risk'],
        'breached' => ['#dc2626', 'Breached'],
        'met'      => ['#64748b', 'Met'],
        'n/a'      => ['#94a3b8', 'N/A'],
    ];
    [$color, $label] = $map[$status] ?? ['#94a3b8', ucfirst($status)];
    $extra = $status === 'met' ? 'text-decoration:line-through;' : '';
    return '<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;'
         . 'background:' . $color . '20;color:' . $color . ';border:1px solid ' . $color . '40;' . $extra . '">'
         . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};
$ageSeconds  = time() - strtotime($incident['created_at']);
$ageHours    = round($ageSeconds / 3600, 1);
$ageDisplay  = $ageHours >= 48 ? round($ageHours / 24, 1) . ' days' : $ageHours . ' hrs';
?>
<div class="page-header">
  <div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <h1 class="page-title" style="margin:0"><?= Security::h($incident['incident_number']) ?>: <?= Security::h($incident['title']) ?></h1>
      <span class="status-chip" style="background:<?= $sevColor ?>20;color:<?= $sevColor ?>;border:1px solid <?= $sevColor ?>40;"><?= ucfirst(Security::h($incident['severity'])) ?></span>
      <span class="status-chip" style="background:<?= $stColor ?>20;color:<?= $stColor ?>;border:1px solid <?= $stColor ?>40;"><?= ucfirst(str_replace('_',' ',Security::h($incident['status']))) ?></span>
    </div>
    <p class="page-subtitle">Reported <?= date('M j, Y g:ia', strtotime($incident['created_at'])) ?><?= $incident['detected_at'] ? ' · Detected ' . date('M j, Y g:ia', strtotime($incident['detected_at'])) : '' ?></p>
  </div>
  <div class="page-actions">
    <?php if (in_array($incident['status'], ['open','investigating','contained'])): ?>
      <?php if (Auth::can('incident.write')): ?>
        <button data-show-modal="editModal" class="btn btn-secondary"><i class="bi bi-pencil"></i> Edit</button>
        <?php if ($incident['status'] !== 'closed'): ?>
          <form method="post" action="/incident/<?= $incident['id'] ?>/close" style="display:inline" data-confirm="Close this incident?">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
            <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle"></i> Close</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ── SLA Status Card ─────────────────────────────────────────────────── -->
<?php if ($slaPolicy): ?>
<div class="card" style="margin-bottom:20px">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-stopwatch" style="color:var(--primary)"></i>
      <span class="card-title">SLA Status</span>
      <span style="font-size:12px;color:var(--text-muted);margin-left:8px">Age: <strong><?= Security::h($ageDisplay) ?></strong></span>
    </div>
    <div class="card-header-right">
      <a href="/incident/sla" style="font-size:12px;color:var(--primary)">
        <i class="bi bi-bar-chart"></i> SLA Report
      </a>
    </div>
  </div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
      <!-- Acknowledge SLA -->
      <div style="border:1px solid var(--border);border-radius:8px;padding:14px">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:6px">
          Acknowledge SLA
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <?= $slaBadgeStyle($ackStatus) ?>
          <span style="font-size:12px;color:var(--text-muted)">
            within <?= (int)$slaPolicy['acknowledge_hours'] ?> hr<?= $slaPolicy['acknowledge_hours'] != 1 ? 's' : '' ?>
          </span>
        </div>
        <div style="margin-top:6px;font-size:12px;color:var(--text-muted)">
          <?php if ($acknowledgedAt): ?>
            Acknowledged: <?= date('M j, Y g:ia', strtotime($acknowledgedAt)) ?>
          <?php else: ?>
            <span style="color:<?= $ackStatus === 'breached' ? '#dc2626' : 'var(--text-muted)' ?>">
              <?= $ackStatus === 'breached' ? 'OVERDUE — not yet acknowledged' : 'Pending acknowledgement' ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Resolve SLA -->
      <div style="border:1px solid var(--border);border-radius:8px;padding:14px">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);margin-bottom:6px">
          Resolve SLA
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <?= $slaBadgeStyle($resStatus) ?>
          <?php $rh = (int)$slaPolicy['resolve_hours']; ?>
          <span style="font-size:12px;color:var(--text-muted)">
            within <?= $rh ?> hr<?= $rh != 1 ? 's' : '' ?>
            <?= $rh >= 24 ? '(' . round($rh / 24, 1) . ' days)' : '' ?>
          </span>
        </div>
        <div style="margin-top:6px;font-size:12px;color:var(--text-muted)">
          <?php if ($resolvedAt): ?>
            Resolved: <?= date('M j, Y g:ia', strtotime($resolvedAt)) ?>
          <?php else: ?>
            <span style="color:<?= $resStatus === 'breached' ? '#dc2626' : 'var(--text-muted)' ?>">
              <?= $resStatus === 'breached' ? 'OVERDUE — not yet resolved' : 'Pending resolution' ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Acknowledge button (if not yet acknowledged and incident is open) -->
    <?php if (!$acknowledgedAt && in_array($incident['status'], ['open','investigating','contained']) && Auth::can('incident.write')): ?>
    <form method="POST" action="/incident/<?= (int)$incident['id'] ?>/acknowledge" style="display:inline">
      <?= Security::csrfField() ?>
      <button type="submit" class="btn btn-primary btn-sm">
        <i class="bi bi-check2-circle"></i> Acknowledge Incident
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="two-col-layout">
  <!-- Left column -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <?php if ($incident['description']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-file-text" style="color:var(--primary)"></i><span class="card-title">Description</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['description']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($incident['affected_systems']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-hdd-network" style="color:#d97706"></i><span class="card-title">Affected Systems</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['affected_systems']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($incident['impact_description']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-exclamation-triangle" style="color:#dc2626"></i><span class="card-title">Impact</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['impact_description']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($incident['root_cause']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-search" style="color:var(--secondary)"></i><span class="card-title">Root Cause</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['root_cause']) ?></p></div>
    </div>
    <?php endif; ?>

    <?php if ($incident['lessons_learned']): ?>
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-lightbulb" style="color:#059669"></i><span class="card-title">Lessons Learned</span></div></div>
      <div class="card-body"><p style="white-space:pre-wrap;margin:0"><?= Security::h($incident['lessons_learned']) ?></p></div>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-clock-history" style="color:var(--primary)"></i><span class="card-title">Timeline</span></div></div>
      <div class="card-body">
        <?php if ($updates): foreach ($updates as $upd): ?>
          <div style="display:flex;gap:12px;margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--border);">
            <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;">
              <?= strtoupper(substr($upd['user_name'] ?? '?', 0, 1)) ?>
            </div>
            <div style="flex:1;">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                <strong style="font-size:13px"><?= Security::h($upd['user_name'] ?? 'System') ?></strong>
                <span style="font-size:11px;color:var(--text-muted)"><?= date('M j, Y g:ia', strtotime($upd['created_at'])) ?></span>
                <?php $typeColors=['status_change'=>'var(--secondary)','containment'=>'#d97706','resolution'=>'#059669','assignment'=>'#0284c7','comment'=>'#64748b']; ?>
                <span style="font-size:10px;padding:1px 6px;border-radius:3px;background:<?= ($typeColors[$upd['update_type']]??'#64748b') ?>20;color:<?= ($typeColors[$upd['update_type']]??'#64748b') ?>"><?= ucfirst(str_replace('_',' ',$upd['update_type'])) ?></span>
              </div>
              <p style="margin:0;white-space:pre-wrap;font-size:13px"><?= Security::h($upd['content']) ?></p>
            </div>
          </div>
        <?php endforeach; else: ?>
          <p style="color:var(--text-muted);text-align:center;padding:20px 0">No updates yet.</p>
        <?php endif; ?>

        <?php if (Auth::check()): ?>
        <form method="post" action="/incident/<?= $incident['id'] ?>/add-update" style="margin-top:16px;">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <div class="form-group">
            <label class="form-label">Add Update</label>
            <textarea name="content" class="form-control" rows="3" placeholder="Describe the update, action taken, or finding…" required></textarea>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;">
            <select name="update_type" class="form-control" style="width:auto">
              <option value="comment">Comment</option>
              <option value="status_change">Status Change</option>
              <option value="containment">Containment Action</option>
              <option value="resolution">Resolution</option>
              <option value="assignment">Assignment</option>
            </select>
            <select name="new_status" class="form-control" style="width:auto">
              <option value="">— Keep current status —</option>
              <option value="open">Open</option>
              <option value="investigating">Investigating</option>
              <option value="contained">Contained</option>
              <option value="resolved">Resolved</option>
              <option value="closed">Closed</option>
            </select>
            <button type="submit" class="btn btn-primary">Post Update</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><div class="card-header-left"><i class="bi bi-info-circle" style="color:var(--primary)"></i><span class="card-title">Incident Details</span></div></div>
      <div class="card-body">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <?php $rows=[
            ['Severity', '<span class="status-chip" style="background:'.$sevColor.'20;color:'.$sevColor.'">' . ucfirst(Security::h($incident['severity'])) . '</span>'],
            ['Status',   '<span class="status-chip" style="background:'.$stColor.'20;color:'.$stColor.'">' . ucfirst(str_replace('_',' ',Security::h($incident['status']))) . '</span>'],
            ['Category', Security::h($incident['category'] ?? '—')],
            ['Reported by', Security::h($incident['reported_by_name'] ?? '—')],
            ['Assigned to', Security::h($incident['assigned_to_name'] ?? 'Unassigned')],
            ['Detected', $incident['detected_at'] ? date('M j, Y g:ia', strtotime($incident['detected_at'])) : '—'],
            ['Contained', $incident['contained_at'] ? date('M j, Y g:ia', strtotime($incident['contained_at'])) : '—'],
            ['Resolved', $incident['resolved_at'] ? date('M j, Y g:ia', strtotime($incident['resolved_at'])) : '—'],
            ['Created', date('M j, Y g:ia', strtotime($incident['created_at']))],
            ['Last updated', date('M j, Y g:ia', strtotime($incident['updated_at']))],
          ]; foreach ($rows as [$label, $val]): ?>
          <tr style="border-bottom:1px solid var(--border-light)">
            <td style="padding:8px 0;color:var(--text-muted);width:120px"><?= $label ?></td>
            <td style="padding:8px 0"><?= $val ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── Playbooks Section ────────────────────────────────────────────── -->
<?php
$csrfTokenPlaybook = Security::generateCsrfToken();
?>
<div style="margin-top:24px">
  <div class="card">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-journal-bookmark-fill" style="color:var(--primary)"></i>
        <span class="card-title">Playbooks</span>
      </div>
      <div class="card-header-right">
        <a href="/playbooks" style="font-size:12px;color:var(--primary)">Browse all playbooks</a>
      </div>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:20px">

      <?php if (empty($playbookRuns)): ?>
        <p style="color:var(--text-muted);margin:0">No playbooks attached to this incident yet.</p>
      <?php else: ?>
        <?php foreach ($playbookRuns as $run):
          $totalSteps = (int)$run['total_steps'];
          $doneSteps  = (int)$run['done_steps'];
          $pct        = $totalSteps > 0 ? round($doneSteps / $totalSteps * 100) : 0;
          $isComplete = (bool)$run['completed_at'];
          $runSteps   = $playbookRunSteps[$run['id']] ?? [];
        ?>
          <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden">
            <!-- Run header -->
            <div style="padding:12px 16px;background:var(--bg-secondary);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;cursor:pointer"
                 data-click="togglePlaybookRun" data-arg="<?= (int)$run['id'] ?>">
              <div>
                <div style="font-weight:600"><?= Security::h($run['playbook_title']) ?></div>
                <div style="font-size:12px;color:var(--text-muted)">
                  Started by <?= Security::h($run['started_by_name'] ?? '—') ?>
                  on <?= date('M j, Y g:ia', strtotime($run['started_at'])) ?>
                  <?php if ($isComplete): ?>
                    &middot; Completed <?= date('M j, Y g:ia', strtotime($run['completed_at'])) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:10px">
                <?php if ($isComplete): ?>
                  <span class="status-chip" style="background:#05966920;color:#059669;border:1px solid #05966940">
                    <i class="bi bi-check-circle-fill"></i> Complete
                  </span>
                <?php else: ?>
                  <span class="status-chip" style="background:#d9770620;color:#d97706;border:1px solid #d9770640">In Progress</span>
                <?php endif; ?>
                <span style="font-size:12px;color:var(--text-muted)"><?= $doneSteps ?>/<?= $totalSteps ?> steps</span>
                <i class="bi bi-chevron-down" id="pb-chevron-<?= (int)$run['id'] ?>" style="color:var(--text-muted);transition:transform .2s"></i>
              </div>
            </div>

            <!-- Progress bar -->
            <div style="height:4px;background:var(--border);position:relative">
              <div id="pb-bar-<?= (int)$run['id'] ?>" style="height:100%;background:<?= $isComplete ? '#059669' : 'var(--primary)' ?>;width:<?= $pct ?>%;transition:width .3s"></div>
            </div>

            <!-- Steps checklist (collapsible) -->
            <div id="pb-steps-<?= (int)$run['id'] ?>" style="display:none;padding:8px 0">
              <?php foreach ($runSteps as $stepIdx => $step):
                $completed = !empty($step['completion_id']);
              ?>
                <div class="pb-step-row" id="pb-step-row-<?= (int)$run['id'] ?>-<?= (int)$step['id'] ?>"
                     style="padding:10px 16px;display:flex;gap:12px;align-items:flex-start;<?= $stepIdx > 0 ? 'border-top:1px solid var(--border-light)' : '' ?>;<?= $completed ? 'opacity:.7' : '' ?>">
                  <div style="flex-shrink:0;margin-top:2px">
                    <?php if (!$isComplete && !$completed && Auth::can('incident.write')): ?>
                      <button
                        type="button"
                        class="pb-complete-btn"
                        title="Mark complete"
                        style="width:20px;height:20px;border-radius:50%;border:2px solid var(--primary);background:none;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0"
                        data-run-id="<?= (int)$run['id'] ?>"
                        data-step-id="<?= (int)$step['id'] ?>"
                        data-csrf="<?= Security::h($csrfTokenPlaybook) ?>"
                      ></button>
                    <?php elseif ($completed): ?>
                      <div style="width:20px;height:20px;border-radius:50%;background:#059669;display:flex;align-items:center;justify-content:center">
                        <i class="bi bi-check" style="color:#fff;font-size:11px"></i>
                      </div>
                    <?php else: ?>
                      <div style="width:20px;height:20px;border-radius:50%;border:2px solid var(--border)"></div>
                    <?php endif; ?>
                  </div>
                  <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:<?= $completed ? '400' : '600' ?>;<?= $completed ? 'text-decoration:line-through;color:var(--text-muted)' : '' ?>">
                      <?= Security::h($step['step_number']) ?>. <?= Security::h($step['title']) ?>
                    </div>
                    <?php if ($step['description']): ?>
                      <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= Security::h($step['description']) ?></div>
                    <?php endif; ?>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;font-size:11px;color:var(--text-muted)">
                      <?php if ($step['owner_role']): ?>
                        <span><i class="bi bi-person"></i> <?= Security::h($step['owner_role']) ?></span>
                      <?php endif; ?>
                      <?php if ($step['due_minutes']): ?>
                        <?php
                        $mins = (int)$step['due_minutes'];
                        if ($mins >= 1440) {
                            $dl = round($mins/1440,1).' day'.(round($mins/1440,1)!=1?'s':'');
                        } elseif ($mins >= 60) {
                            $dl = round($mins/60,1).' hr'.(round($mins/60,1)!=1?'s':'');
                        } else {
                            $dl = $mins.' min';
                        }
                        ?>
                        <span><i class="bi bi-clock"></i> Due within <?= Security::h($dl) ?></span>
                      <?php endif; ?>
                      <?php if ($completed): ?>
                        <span style="color:#059669"><i class="bi bi-check-circle"></i>
                          Completed by <?= Security::h($step['completed_by_name'] ?? '—') ?>
                          at <?= date('M j, Y g:ia', strtotime($step['completed_at'])) ?>
                        </span>
                        <?php if ($step['completion_notes']): ?>
                          <span title="<?= Security::h($step['completion_notes']) ?>"><i class="bi bi-chat-left-text"></i> Has notes</span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Start a new playbook run -->
      <?php if (!empty($availablePlaybooks) && Auth::can('incident.write')): ?>
        <div style="border-top:1px solid var(--border);padding-top:16px">
          <form method="post" action="/incident/<?= (int)$incident['id'] ?>/playbook/start" id="pb-start-form">
            <?= Security::csrfField() ?>
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
              <div class="form-group" style="flex:1;min-width:200px;margin:0">
                <label class="form-label" style="font-size:13px">Attach a Playbook</label>
                <select name="playbook_id" class="form-control" required>
                  <option value="">— Select a playbook —</option>
                  <?php foreach ($availablePlaybooks as $pb): ?>
                    <option value="<?= (int)$pb['id'] ?>">
                      <?= Security::h($pb['title']) ?>
                      <?php if ($pb['severity_filter']): ?>
                        (<?= Security::h(ucfirst($pb['severity_filter'])) ?>)
                      <?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-primary" style="flex-shrink:0">
                <i class="bi bi-play-circle"></i> Start Playbook
              </button>
            </div>
          </form>
        </div>
      <?php elseif (empty($availablePlaybooks) && empty($playbookRuns)): ?>
        <p style="color:var(--text-muted);font-size:13px;margin:0">
          No active playbooks available. <a href="/playbooks/create">Create a playbook</a> to get started.
        </p>
      <?php elseif (empty($availablePlaybooks) && !empty($playbookRuns)): ?>
        <p style="color:var(--text-muted);font-size:13px;margin:0">All active playbooks are already attached to this incident.</p>
      <?php endif; ?>

    </div>
  </div>
</div>

<script nonce="<?= Security::nonce() ?>">
(function() {
  'use strict';

  // Toggle collapsible step list
  window.togglePlaybookRun = function(runId) {
    var el  = document.getElementById('pb-steps-' + runId);
    var chv = document.getElementById('pb-chevron-' + runId);
    if (!el) return;
    var isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : 'block';
    if (chv) chv.style.transform = isOpen ? '' : 'rotate(180deg)';
  };

  // AJAX step completion
  document.querySelectorAll('.pb-complete-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var runId  = btn.dataset.runId;
      var stepId = btn.dataset.stepId;
      var csrf   = btn.dataset.csrf;
      var notes  = ''; // Future: prompt for notes

      var fd = new FormData();
      fd.append('step_id',    stepId);
      fd.append('notes',      notes);
      fd.append('csrf_token', csrf);

      btn.disabled = true;
      btn.style.opacity = '0.5';

      fetch('/playbooks/run/' + runId + '/complete-step', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      })
      .then(function(resp) { return resp.json(); })
      .then(function(data) {
        if (!data.ok) { btn.disabled = false; btn.style.opacity = '1'; return; }

        // Swap button for green check
        var rowEl = document.getElementById('pb-step-row-' + runId + '-' + stepId);
        if (rowEl) {
          var btnWrap = rowEl.querySelector('.pb-complete-btn').parentNode;
          btnWrap.innerHTML = '<div style="width:20px;height:20px;border-radius:50%;background:#059669;display:flex;align-items:center;justify-content:center"><i class="bi bi-check" style="color:#fff;font-size:11px"></i></div>';
          var titleEl = rowEl.querySelector('div[style*="font-weight"]');
          if (titleEl) {
            titleEl.style.textDecoration = 'line-through';
            titleEl.style.color = 'var(--text-muted)';
            titleEl.style.fontWeight = '400';
          }
        }

        // Update progress bar and count
        var bar = document.getElementById('pb-bar-' + runId);
        var pct = data.total > 0 ? Math.round(data.done / data.total * 100) : 0;
        if (bar) {
          bar.style.width = pct + '%';
          if (data.done >= data.total) bar.style.background = '#059669';
        }
        // Update the done/total text (sibling of chevron)
        var header = bar ? bar.parentNode.previousElementSibling : null;
        if (header) {
          var countEl = header.querySelector('span[style*="font-size:12px"]');
          if (countEl) countEl.textContent = data.done + '/' + data.total + ' steps';
        }
      })
      .catch(function() { btn.disabled = false; btn.style.opacity = '1'; });
    });
  });
}());
</script>

<!-- Edit Modal -->
<?php if (Auth::can('incident.write')): ?>
<div class="modal-overlay" id="editModal" style="display:none">
  <div class="modal" style="max-width:640px;width:100%">
    <div class="modal-header">
      <span>Edit Incident</span>
      <button data-close-modal="editModal" style="background:none;border:none;cursor:pointer;font-size:18px">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" action="/incident/<?= $incident['id'] ?>/update">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div class="form-row">
          <div class="flex-2">
            <div class="form-group"><label class="form-label">Title *</label><input name="title" class="form-control" value="<?= Security::h($incident['title']) ?>" required></div>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1"><label class="form-label">Severity</label>
            <select name="severity" class="form-control">
              <?php foreach (['critical','high','medium','low'] as $s): ?>
                <option value="<?= $s ?>" <?= $incident['severity']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Status</label>
            <select name="status" class="form-control">
              <?php foreach (['open','investigating','contained','resolved','closed'] as $s): ?>
                <option value="<?= $s ?>" <?= $incident['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1"><label class="form-label">Assigned To</label>
            <select name="assigned_to" class="form-control">
              <option value="">Unassigned</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= ($incident['assigned_to']==$u['id'])?'selected':'' ?>><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?= Security::h($incident['description'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Affected Systems</label><textarea name="affected_systems" class="form-control" rows="2"><?= Security::h($incident['affected_systems'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Impact Description</label><textarea name="impact_description" class="form-control" rows="2"><?= Security::h($incident['impact_description'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Root Cause</label><textarea name="root_cause" class="form-control" rows="2"><?= Security::h($incident['root_cause'] ?? '') ?></textarea></div>
        <div class="form-group"><label class="form-label">Lessons Learned</label><textarea name="lessons_learned" class="form-control" rows="2"><?= Security::h($incident['lessons_learned'] ?? '') ?></textarea></div>
        <div class="modal-footer"><button type="button" data-close-modal="editModal" class="btn btn-secondary">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
