<?php
ob_start();
?>
<style>
@media print {
  .sidebar, .topbar, .no-print, #alertPanel, #alertOverlay { display: none !important; }
  .main-content { margin-left: 0 !important; padding: 0 !important; }
  .page-content { padding: 0 !important; }
  body { font-size: 11px; }
  table { page-break-inside: auto; }
  tr { page-break-inside: avoid; }
  th { background: #e4e4e7 !important; -webkit-print-color-adjust: exact; }
}
.score-summary { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.score-chip { text-align: center; padding: 12px 20px; border-radius: 8px; min-width: 90px; }
.score-bar { height: 16px; border-radius: 8px; overflow: hidden; display: flex; margin-bottom: 20px; }
.sig-block { margin-top: 40px; border-top: 1px solid #e4e4e7; padding-top: 24px; }
.sig-line { display: flex; gap: 40px; margin-bottom: 24px; }
.sig-field { flex: 1; border-bottom: 1px solid #333; padding-bottom: 4px; }
.scorecard-header { border-bottom: 2px solid #e4e4e7; margin-bottom: 24px; padding-bottom: 16px; }
.scorecard-logo { font-size: 22px; font-weight: 700; color: var(--text); letter-spacing: -0.5px; }
.scorecard-logo span { color: var(--primary); }
.domain-header-row { background: #111111; color: #fff; padding: 10px 14px; font-weight: 600; font-size: 13px; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.badge-compliant { background: #dcfce7; color: #166534; }
.badge-non_compliant { background: #fee2e2; color: #991b1b; }
.badge-partial { background: #fef9c3; color: #854d0e; }
.badge-not_applicable { background: #f4f4f5; color: var(--text-muted); }
.badge-not_started { background: #f4f4f5; color: var(--text-muted); }
.domain-section { margin-bottom: 24px; page-break-before: auto; }
</style>

<!-- Print bar (hidden when printing) -->
<div class="no-print" style="margin-bottom:16px">
  <button data-print class="btn btn-primary"><i class="bi bi-printer"></i> Print / Save PDF</button>
  <a href="/compliance/<?= $pkgId ?>" class="btn btn-ghost">← Back to Package</a>
</div>

<!-- Scorecard Header (appears on every printed page) -->
<div class="card" style="margin-bottom:20px">
  <div class="card-body">
    <div class="scorecard-header">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
        <div>
          <div class="scorecard-logo">AEGIS <span>GRC</span></div>
          <div style="font-size:13px;color:var(--text-muted);margin-top:2px"><?= Security::h(Auth::user()['org_name'] ?? 'Enterprise') ?></div>
        </div>
        <div style="text-align:right;font-size:13px;color:var(--text-muted)">
          <div><strong>Framework:</strong> <?= Security::h($package['standard_name']) ?> (<?= Security::h($package['standard_code']) ?>)</div>
          <div><strong>Generated:</strong> <?= date('F j, Y') ?></div>
          <div><strong>Prepared by:</strong> <?= Security::h(Auth::user()['name'] ?? '—') ?></div>
        </div>
      </div>
      <h2 style="margin:16px 0 0;font-size:20px;color:var(--text)">COMPLIANCE SCORECARD — <?= Security::h($package['name']) ?></h2>
    </div>

    <!-- Executive Summary -->
    <h3 style="margin-bottom:12px;font-size:15px;color:var(--text)">Executive Summary</h3>

    <?php
      $pctColor = $pct >= 80 ? 'var(--success)' : ($pct >= 60 ? 'var(--warning)' : 'var(--danger)');
      $pctBg    = $pct >= 80 ? '#dcfce7' : ($pct >= 60 ? '#fef9c3' : '#fee2e2');
    ?>

    <div class="score-summary">
      <div class="score-chip" style="background:var(--bg-secondary);color:var(--text)">
        <div style="font-size:22px;font-weight:700"><?= $total ?></div>
        <div style="font-size:12px;margin-top:4px">Total Controls</div>
      </div>
      <div class="score-chip" style="background:#dcfce7;color:#166534">
        <div style="font-size:22px;font-weight:700"><?= $compliant ?></div>
        <div style="font-size:12px;margin-top:4px">Compliant</div>
      </div>
      <div class="score-chip" style="background:#fee2e2;color:#991b1b">
        <div style="font-size:22px;font-weight:700"><?= $nonCompliant ?></div>
        <div style="font-size:12px;margin-top:4px">Non-Compliant</div>
      </div>
      <div class="score-chip" style="background:#fef9c3;color:#854d0e">
        <div style="font-size:22px;font-weight:700"><?= $partial ?></div>
        <div style="font-size:12px;margin-top:4px">Partial</div>
      </div>
      <div class="score-chip" style="background:var(--bg-secondary);color:var(--text-muted)">
        <div style="font-size:22px;font-weight:700"><?= $notApplicable ?></div>
        <div style="font-size:12px;margin-top:4px">Not Applicable</div>
      </div>
      <div class="score-chip" style="background:var(--bg-secondary);color:var(--text-muted)">
        <div style="font-size:22px;font-weight:700"><?= $notAssessed ?></div>
        <div style="font-size:12px;margin-top:4px">Not Assessed</div>
      </div>
      <div class="score-chip" style="background:<?= $pctBg ?>;color:<?= $pctColor ?>;font-size:28px;font-weight:800;min-width:110px">
        <div style="font-size:32px;font-weight:800"><?= $pct ?>%</div>
        <div style="font-size:12px;margin-top:4px">Compliance Score</div>
      </div>
    </div>

    <!-- Score bar -->
    <?php
      $barTotal = max(1, $total);
      $wCompliant    = round($compliant / $barTotal * 100, 2);
      $wPartial      = round($partial / $barTotal * 100, 2);
      $wNonCompliant = round($nonCompliant / $barTotal * 100, 2);
      $wNA           = round($notApplicable / $barTotal * 100, 2);
      $wNotAssessed  = max(0, 100 - $wCompliant - $wPartial - $wNonCompliant - $wNA);
    ?>
    <div class="score-bar" title="Compliance distribution">
      <?php if ($wCompliant > 0): ?>
        <div style="width:<?= $wCompliant ?>%;background:var(--success)" title="Compliant: <?= $compliant ?>"></div>
      <?php endif; ?>
      <?php if ($wPartial > 0): ?>
        <div style="width:<?= $wPartial ?>%;background:var(--warning)" title="Partial: <?= $partial ?>"></div>
      <?php endif; ?>
      <?php if ($wNonCompliant > 0): ?>
        <div style="width:<?= $wNonCompliant ?>%;background:var(--danger)" title="Non-Compliant: <?= $nonCompliant ?>"></div>
      <?php endif; ?>
      <?php if ($wNA > 0): ?>
        <div style="width:<?= $wNA ?>%;background:#a1a1aa" title="Not Applicable: <?= $notApplicable ?>"></div>
      <?php endif; ?>
      <?php if ($wNotAssessed > 0): ?>
        <div style="width:<?= $wNotAssessed ?>%;background:#e4e4e7" title="Not Assessed: <?= $notAssessed ?>"></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--text-muted);margin-bottom:4px">
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--success);border-radius:2px;margin-right:4px"></span>Compliant</span>
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--warning);border-radius:2px;margin-right:4px"></span>Partial</span>
      <span><span style="display:inline-block;width:10px;height:10px;background:var(--danger);border-radius:2px;margin-right:4px"></span>Non-Compliant</span>
      <span><span style="display:inline-block;width:10px;height:10px;background:#a1a1aa;border-radius:2px;margin-right:4px"></span>Not Applicable</span>
      <span><span style="display:inline-block;width:10px;height:10px;background:#e4e4e7;border-radius:2px;margin-right:4px"></span>Not Assessed</span>
    </div>
  </div>
</div>

<!-- Controls by Domain -->
<h3 style="margin-bottom:12px;font-size:15px;color:var(--text)">Controls by Domain</h3>

<?php foreach ($domains as $domain): ?>
<div class="domain-section card" style="margin-bottom:16px">
  <div class="domain-header-row">
    <?= Security::h($domain['code']) ?> — <?= Security::h($domain['title']) ?>
  </div>
  <?php $domainControls = $byDomain[(int)$domain['id']] ?? []; ?>
  <?php if ($domainControls): ?>
  <table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead>
      <tr>
        <th style="text-align:left;padding:8px 12px;background:var(--bg-secondary);border-bottom:1px solid var(--border);width:100px">Code</th>
        <th style="text-align:left;padding:8px 12px;background:var(--bg-secondary);border-bottom:1px solid var(--border)">Control Title</th>
        <th style="text-align:left;padding:8px 12px;background:var(--bg-secondary);border-bottom:1px solid var(--border);width:120px">Status</th>
        <th style="text-align:left;padding:8px 12px;background:var(--bg-secondary);border-bottom:1px solid var(--border);width:120px">Assigned To</th>
        <th style="text-align:left;padding:8px 12px;background:var(--bg-secondary);border-bottom:1px solid var(--border);width:90px">Due Date</th>
        <th style="text-align:left;padding:8px 12px;background:var(--bg-secondary);border-bottom:1px solid var(--border)">Notes</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($domainControls as $ctrl):
        $status = $ctrl['status'] ?? 'not_started';
        $badgeClass = 'badge-' . $status;
        $statusLabel = match($status) {
          'compliant'      => 'Compliant',
          'non_compliant'  => 'Non-Compliant',
          'partial'        => 'Partial',
          'not_applicable' => 'N/A',
          default          => 'Not Assessed',
        };
        $notes = $ctrl['notes'] ?? $ctrl['implementation_notes'] ?? '';
        if (mb_strlen($notes) > 100) {
            $notes = mb_substr($notes, 0, 100) . '…';
        }
      ?>
      <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:7px 12px;font-family:monospace;font-size:11px;color:var(--text-muted)"><?= Security::h($ctrl['code']) ?></td>
        <td style="padding:7px 12px"><?= Security::h($ctrl['title']) ?></td>
        <td style="padding:7px 12px"><span class="status-badge <?= $badgeClass ?>"><?= $statusLabel ?></span></td>
        <td style="padding:7px 12px;color:var(--text-muted)"><?= Security::h($ctrl['assigned_name'] ?? '—') ?></td>
        <td style="padding:7px 12px;color:var(--text-muted)"><?= $ctrl['due_date'] ? date('M j, Y', strtotime($ctrl['due_date'])) : '—' ?></td>
        <td style="padding:7px 12px;color:var(--text-muted);font-size:11px"><?= Security::h($notes) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <div style="padding:12px 16px;color:var(--text-muted);font-size:13px">No controls in this domain.</div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- Signature block -->
<div class="card">
  <div class="card-body">
    <div class="sig-block">
      <h3 style="margin-top:0;font-size:14px;color:var(--text);margin-bottom:20px">Authorization &amp; Approval</h3>
      <div class="sig-line">
        <div style="flex:2">
          <div class="sig-field">&nbsp;</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Prepared by</div>
        </div>
        <div style="flex:1">
          <div class="sig-field">&nbsp;</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Date</div>
        </div>
      </div>
      <div class="sig-line">
        <div style="flex:2">
          <div class="sig-field">&nbsp;</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Reviewed by</div>
        </div>
        <div style="flex:1">
          <div class="sig-field">&nbsp;</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Date</div>
        </div>
      </div>
      <div class="sig-line">
        <div style="flex:2">
          <div class="sig-field">&nbsp;</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Approved by</div>
        </div>
        <div style="flex:1">
          <div class="sig-field">&nbsp;</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:6px">Date</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
