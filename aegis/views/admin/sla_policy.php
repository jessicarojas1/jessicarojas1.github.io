<?php
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$sevColors = ['critical' => 'var(--danger)', 'high' => 'var(--warning)', 'medium' => '#0284c7', 'low' => 'var(--success)'];
$breadcrumbs = [['Admin', '/admin'], ['SLA Policies', null]];
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-stopwatch" style="color:var(--primary)"></i> Incident SLA Policy</h1>
    <p class="page-subtitle">Configure service level agreement targets per severity</p>
  </div>
  <div class="page-actions">
    <a href="/admin" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Admin</a>
    <a href="/incident/sla" class="btn btn-secondary"><i class="bi bi-bar-chart"></i> SLA Report</a>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert-box success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert-box error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div style="max-width:900px">

  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-table" style="color:var(--primary)"></i>
        <span class="card-title">SLA Targets by Severity</span>
      </div>
    </div>
    <div class="card-body">
      <form method="POST" action="/admin/sla-policy/save">
        <?= Security::csrfField() ?>

        <div style="overflow-x:auto">
          <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr style="background:var(--bg-secondary)">
                <th style="padding:10px 14px;text-align:left;font-weight:600;color:var(--text-muted);width:100px">Severity</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600;color:var(--text-muted)">Acknowledge Within (hours)</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600;color:var(--text-muted)">Resolve Within (hours)</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600;color:var(--text-muted)">Escalate After (hours, optional)</th>
                <th style="padding:10px 14px;text-align:left;font-weight:600;color:var(--text-muted)">Effective SLA</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($policies as $pol):
                $sc       = $sevColors[$pol['severity']] ?? '#71717a';
                $ackH     = (int)$pol['acknowledge_hours'];
                $resH     = (int)$pol['resolve_hours'];
                $resDays  = $resH >= 24 ? round($resH / 24, 1) : null;
              ?>
              <tr style="border-top:1px solid var(--border)">
                <td style="padding:12px 14px">
                  <input type="hidden" name="id[]" value="<?= (int)$pol['id'] ?>">
                  <span style="display:inline-block;padding:3px 12px;border-radius:12px;font-size:12px;font-weight:700;background:<?= $sc ?>20;color:<?= $sc ?>;border:1px solid <?= $sc ?>40">
                    <?= ucfirst(Security::h($pol['severity'])) ?>
                  </span>
                </td>
                <td style="padding:12px 14px">
                  <input type="number" name="acknowledge_hours[]" class="form-control" style="max-width:100px;display:inline-block"
                         value="<?= $ackH ?>" min="1" max="720" required>
                </td>
                <td style="padding:12px 14px">
                  <input type="number" name="resolve_hours[]" class="form-control" style="max-width:100px;display:inline-block"
                         value="<?= $resH ?>" min="1" max="8760" required>
                </td>
                <td style="padding:12px 14px">
                  <input type="number" name="escalate_hours[]" class="form-control" style="max-width:100px;display:inline-block"
                         value="<?= $pol['escalate_hours'] !== null ? (int)$pol['escalate_hours'] : '' ?>"
                         min="1" max="8760" placeholder="—">
                </td>
                <td style="padding:12px 14px;color:var(--text-muted);font-size:12px" id="eff-<?= (int)$pol['id'] ?>">
                  Ack: <?= $ackH ?> hr<?= $ackH != 1 ? 's' : '' ?> /
                  Res: <?= $resH ?> hr<?= $resH != 1 ? 's' : '' ?><?= $resDays ? ' (' . $resDays . 'd)' : '' ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px;align-items:center">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save All</button>
          <span style="font-size:12px;color:var(--text-muted)">Changes take effect immediately for new SLA calculations.</span>
        </div>
      </form>
    </div>
  </div>

  <!-- Info card -->
  <div class="card">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-info-circle" style="color:var(--primary)"></i>
        <span class="card-title">SLA Breach Detection</span>
      </div>
    </div>
    <div class="card-body" style="font-size:13px;line-height:1.7;color:var(--text-muted)">
      <p style="margin:0 0 10px">
        AEGIS GRC computes SLA status in real time on the <a href="/incident/sla" style="color:var(--primary)">SLA Report</a>
        and on each incident's detail page. Statuses are determined as follows:
      </p>
      <ul style="margin:0 0 10px;padding-left:20px">
        <li><strong style="color:var(--success)">On Track</strong> — Incident age is within the SLA target.</li>
        <li><strong style="color:var(--warning)">At Risk</strong> — Incident has consumed more than 75% of the allowed time without being acknowledged/resolved.</li>
        <li><strong style="color:var(--danger)">Breached</strong> — SLA deadline has passed and the event has not occurred.</li>
        <li><strong style="color:var(--text-muted)">Met</strong> — The event (acknowledgement or resolution) was recorded before the deadline.</li>
      </ul>
      <p style="margin:0">
        The <em>Acknowledge</em> SLA clock starts from the incident creation time. Acknowledgements are recorded via the
        <strong>Acknowledge Incident</strong> button on the incident detail page.
        The <em>Escalate After</em> field is informational — automated escalation workflows can be configured in
        <a href="/admin/workflows" style="color:var(--primary)">Admin &rarr; Workflows</a>.
      </p>
    </div>
  </div>

</div>
