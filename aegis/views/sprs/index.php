<?php $breadcrumbs = [['SPRS', null]]; ?>
<div class="page-header">
  <div>
    <h1 class="page-title">SPRS Score</h1>
    <p class="page-subtitle">Supplier Performance Risk System score estimation based on NIST SP 800-171 compliance data</p>
  </div>
</div>

<?php if (!empty($nistPackages)): ?>
<div style="margin-bottom:24px;">
  <h3 style="margin:0 0 16px;font-size:1rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">NIST 800-171 / CMMC Packages</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
    <?php foreach ($nistPackages as $pkg):
      $score = $pkg['sprs_score'];
      $color = $score >= 90 ? 'var(--success)' : ($score >= 70 ? 'var(--warning)' : 'var(--danger)');
    ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:32px 20px;">
        <div style="font-size:4rem;font-weight:800;color:<?= $color ?>;line-height:1;"><?= number_format($score, 1) ?></div>
        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);margin:4px 0 16px;">SPRS Score / 110</div>
        <div style="font-weight:600;margin-bottom:8px;"><?= Security::h($pkg['name']) ?></div>
        <div style="font-size:0.8rem;color:var(--text-muted);"><?= Security::h($pkg['standard_code']) ?></div>
        <div style="margin-top:16px;background:var(--bg);border-radius:8px;height:8px;">
          <div style="width:<?= max(0, min(100, ($score / 110) * 100)) ?>%;background:<?= $color ?>;border-radius:8px;height:8px;transition:width 0.3s;"></div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:16px;font-size:0.75rem;">
          <div><div style="font-weight:700;color:var(--success);"><?= (int)$pkg['compliant'] ?></div><div style="color:var(--text-muted);">Compliant</div></div>
          <div><div style="font-weight:700;color:var(--warning);"><?= (int)$pkg['partial'] ?></div><div style="color:var(--text-muted);">Partial</div></div>
          <div><div style="font-weight:700;color:var(--danger);"><?= (int)$pkg['non_compliant'] ?></div><div style="color:var(--text-muted);">Non-Compliant</div></div>
          <div><div style="font-weight:700;color:var(--text-muted);"><?= (int)$pkg['not_assessed'] ?></div><div style="color:var(--text-muted);">Not Assessed</div></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:24px;border-left:4px solid var(--warning);">
  <div class="card-body">
    <h4 style="margin:0 0 8px;"><i class="bi bi-exclamation-triangle"></i> No NIST 800-171 Packages Detected</h4>
    <p style="margin:0;font-size:0.875rem;">Import a NIST SP 800-171 or CMMC compliance package to calculate your SPRS score. AEGIS detects packages with "800-171" or "CMMC" in their name or standard code.</p>
  </div>
</div>
<?php endif; ?>

<h3 style="margin:0 0 16px;font-size:1rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">All Compliance Packages</h3>
<div class="card">
  <table class="table">
    <thead>
      <tr><th>Package</th><th>Standard</th><th>Controls</th><th>Compliant</th><th>Partial</th><th>Non-Compliant</th><th>Not Assessed</th><th>Score Est.</th><th>%</th></tr>
    </thead>
    <tbody>
    <?php foreach ($packages as $pkg):
      $score = $pkg['sprs_score'];
      $sc = $score >= 90 ? 'var(--success)' : ($score >= 70 ? 'var(--warning)' : 'var(--danger)');
    ?>
      <tr>
        <td><?= Security::h($pkg['name']) ?></td>
        <td><?= Security::h($pkg['standard_code']) ?></td>
        <td><?= (int)$pkg['total_controls'] ?></td>
        <td style="color:var(--success);font-weight:600;"><?= (int)$pkg['compliant'] ?></td>
        <td style="color:var(--warning);font-weight:600;"><?= (int)$pkg['partial'] ?></td>
        <td style="color:var(--danger);font-weight:600;"><?= (int)$pkg['non_compliant'] ?></td>
        <td style="color:var(--text-muted);"><?= (int)$pkg['not_assessed'] ?></td>
        <td style="font-weight:700;color:<?= $sc ?>;"><?= number_format($score, 1) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;">
            <div style="flex:1;background:var(--border);border-radius:4px;height:6px;">
              <div style="width:<?= $pkg['pct'] ?>%;background:var(--success);border-radius:4px;height:6px;"></div>
            </div>
            <span style="font-size:0.78rem;"><?= $pkg['pct'] ?>%</span>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:20px;background:var(--bg);">
  <div class="card-body" style="font-size:0.85rem;color:var(--text-muted);">
    <strong>Scoring Methodology:</strong> Baseline of 110 points. Each non-compliant control deducts 1 point; each partial deducts 0.5 points; each unassessed control deducts 1 point (worst-case assumption). A score of 110 indicates full compliance. Negative scores are possible for severely non-compliant systems.<br><br>
    <strong>Note:</strong> Actual SPRS submission to the DoD SPRS system requires a formal self-assessment or C3PAO assessment. This estimate is for internal planning purposes only.
  </div>
</div>
