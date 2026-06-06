<?php
$breadcrumbs = $breadcrumbs ?? [['Incidents', '/incident'], ['SLA Report', null]];
// SLA badge helper
function slaBadge(string $status): string {
    $map = [
        'on_track' => ['success',  '#059669', 'On Track'],
        'at_risk'  => ['warning',  '#d97706', 'At Risk'],
        'breached' => ['danger',   '#dc2626', 'Breached'],
        'met'      => ['met',      '#71717a', 'Met'],
        'n/a'      => ['muted',    '#a1a1aa', 'N/A'],
    ];
    [$cls, $color, $label] = $map[$status] ?? ['muted', '#a1a1aa', ucfirst($status)];
    $extra = $status === 'met' ? 'text-decoration:line-through;' : '';
    return '<span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;'
         . 'background:' . $color . '20;color:' . $color . ';border:1px solid ' . $color . '40;' . $extra . '">'
         . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

// Fetch SLA policies
$slaPolicies = Database::fetchAll(
    "SELECT * FROM incident_sla_policies ORDER BY CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END"
);

// Compute summary counts across both ack & resolve SLAs
$ackCounts = ['on_track' => 0, 'at_risk' => 0, 'breached' => 0, 'met' => 0, 'n/a' => 0];
$resCounts = ['on_track' => 0, 'at_risk' => 0, 'breached' => 0, 'met' => 0, 'n/a' => 0];
foreach ($incidents as $inc) {
    $ackCounts[$inc['ack_sla_status']]     = ($ackCounts[$inc['ack_sla_status']] ?? 0) + 1;
    $resCounts[$inc['resolve_sla_status']] = ($resCounts[$inc['resolve_sla_status']] ?? 0) + 1;
}
$sevColors = ['critical' => '#dc2626', 'high' => '#d97706', 'medium' => '#0284c7', 'low' => '#059669'];
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-stopwatch" style="color:var(--primary)"></i> Incident SLA Report</h1>
    <p class="page-subtitle">Service Level Agreement tracking for open incidents</p>
  </div>
  <div class="page-actions">
    <a href="/incident" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> All Incidents</a>
    <a href="/admin/sla-policy" class="btn btn-secondary"><i class="bi bi-gear"></i> SLA Policies</a>
  </div>
</div>

<!-- ── SLA Policy Summary ──────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-table" style="color:var(--primary)"></i>
      <span class="card-title">SLA Policy Overview</span>
    </div>
  </div>
  <div class="card-body" style="padding:0">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:var(--bg-secondary)">
          <th style="padding:10px 16px;text-align:left;font-weight:600;color:var(--text-muted)">Severity</th>
          <th style="padding:10px 16px;text-align:center;font-weight:600;color:var(--text-muted)">Acknowledge Within</th>
          <th style="padding:10px 16px;text-align:center;font-weight:600;color:var(--text-muted)">Resolve Within</th>
          <th style="padding:10px 16px;text-align:center;font-weight:600;color:var(--text-muted)">Escalate After</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($slaPolicies as $pol): $sc = $sevColors[$pol['severity']] ?? '#71717a'; ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:10px 16px">
            <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700;background:<?= $sc ?>20;color:<?= $sc ?>;border:1px solid <?= $sc ?>40">
              <?= ucfirst(Security::h($pol['severity'])) ?>
            </span>
          </td>
          <td style="padding:10px 16px;text-align:center">
            <?= (int)$pol['acknowledge_hours'] ?> hour<?= $pol['acknowledge_hours'] != 1 ? 's' : '' ?>
          </td>
          <td style="padding:10px 16px;text-align:center">
            <?php $rh = (int)$pol['resolve_hours'];
            $days = $rh >= 24 ? round($rh / 24, 1) : null; ?>
            <?= $rh ?> hr<?= $rh != 1 ? 's' : '' ?><?= $days ? ' (' . $days . ' day' . ($days != 1 ? 's' : '') . ')' : '' ?>
          </td>
          <td style="padding:10px 16px;text-align:center;color:var(--text-muted)">
            <?= $pol['escalate_hours'] ? (int)$pol['escalate_hours'] . ' hr' . ($pol['escalate_hours'] != 1 ? 's' : '') : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Stat Chips ─────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px">
  <?php
  $chips = [
    ['label' => 'Ack On Track',  'count' => $ackCounts['on_track'],  'color' => '#059669'],
    ['label' => 'Ack At Risk',   'count' => $ackCounts['at_risk'],   'color' => '#d97706'],
    ['label' => 'Ack Breached',  'count' => $ackCounts['breached'],  'color' => '#dc2626'],
    ['label' => 'Res On Track',  'count' => $resCounts['on_track'],  'color' => '#059669'],
    ['label' => 'Res At Risk',   'count' => $resCounts['at_risk'],   'color' => '#d97706'],
    ['label' => 'Res Breached',  'count' => $resCounts['breached'],  'color' => '#dc2626'],
  ];
  foreach ($chips as $chip): ?>
  <div class="card" style="padding:16px;text-align:center;border-top:3px solid <?= $chip['color'] ?>">
    <div style="font-size:28px;font-weight:800;color:<?= $chip['color'] ?>"><?= (int)$chip['count'] ?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;font-weight:500"><?= Security::h($chip['label']) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Incidents Table ────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <div class="card-header-left">
      <i class="bi bi-list-ul" style="color:var(--primary)"></i>
      <span class="card-title">Open Incidents — SLA Status</span>
    </div>
    <div class="card-header-right" style="font-size:13px;color:var(--text-muted)">
      <?= count($incidents) ?> incident<?= count($incidents) != 1 ? 's' : '' ?>
    </div>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($incidents)): ?>
      <div style="text-align:center;padding:40px;color:var(--text-muted)">
        <i class="bi bi-check2-circle" style="font-size:32px;display:block;margin-bottom:8px"></i>
        No open incidents. All clear!
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="background:var(--bg-secondary)">
            <th style="padding:10px 14px;text-align:left;font-weight:600;color:var(--text-muted)">Severity</th>
            <th style="padding:10px 14px;text-align:left;font-weight:600;color:var(--text-muted)">Incident</th>
            <th style="padding:10px 14px;text-align:center;font-weight:600;color:var(--text-muted)">Age</th>
            <th style="padding:10px 14px;text-align:center;font-weight:600;color:var(--text-muted)">Acknowledge SLA</th>
            <th style="padding:10px 14px;text-align:center;font-weight:600;color:var(--text-muted)">Resolve SLA</th>
            <th style="padding:10px 14px;text-align:center;font-weight:600;color:var(--text-muted)"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incidents as $inc):
            $sc = $sevColors[$inc['severity']] ?? '#71717a';
            $ageH = $inc['age_hours'];
            $ageStr = $ageH >= 48 ? round($ageH / 24, 1) . ' days' : $ageH . ' hrs';
          ?>
          <tr style="border-top:1px solid var(--border);<?= ($inc['ack_sla_status'] === 'breached' || $inc['resolve_sla_status'] === 'breached') ? 'background:var(--danger-subtle);' : '' ?>">
            <td style="padding:10px 14px">
              <span style="display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;background:<?= $sc ?>20;color:<?= $sc ?>;border:1px solid <?= $sc ?>40">
                <?= ucfirst(Security::h($inc['severity'])) ?>
              </span>
            </td>
            <td style="padding:10px 14px">
              <div style="font-weight:600"><?= Security::h($inc['incident_number'] ?? '') ?></div>
              <div style="color:var(--text-muted);font-size:12px"><?= Security::h(mb_strimwidth($inc['title'] ?? '', 0, 60, '…')) ?></div>
            </td>
            <td style="padding:10px 14px;text-align:center;color:var(--text-muted)"><?= Security::h($ageStr) ?></td>
            <td style="padding:10px 14px;text-align:center"><?= slaBadge($inc['ack_sla_status']) ?></td>
            <td style="padding:10px 14px;text-align:center"><?= slaBadge($inc['resolve_sla_status']) ?></td>
            <td style="padding:10px 14px;text-align:center">
              <a href="/incident/<?= (int)$inc['id'] ?>" class="btn btn-ghost btn-sm" style="font-size:12px">
                <i class="bi bi-arrow-right"></i> View
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
