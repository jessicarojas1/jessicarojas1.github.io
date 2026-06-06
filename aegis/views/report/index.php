<?php
$pageTitle    = 'Reports';
$activeModule = 'report';
$breadcrumbs  = [['Reports', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Reports</h1>
    <p class="page-subtitle">Generate and export GRC reports for your organization</p>
  </div>
</div>

<style nonce="<?= Security::nonce() ?>">
.report-card {
  text-decoration: none;
  color: inherit;
  display: block;
  transition: box-shadow 0.2s, transform 0.15s;
}
.report-card:hover {
  box-shadow: 0 6px 24px rgba(0,0,0,.13);
  transform: translateY(-2px);
}
html[data-theme="dark"] .report-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.4); }
</style>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:8px">

  <a href="/report/executive" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-graph-up-arrow" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Executive Summary</div>
        <div style="font-size:13px;color:var(--text-muted)">GRC health score, top risks, upcoming reviews, and key metrics for leadership.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--primary);font-weight:500">View Report <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/report/board" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--indigo),var(--indigo));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-briefcase-fill" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Board Pack</div>
        <div style="font-size:13px;color:var(--text-muted)">Full board-ready risk report with KRIs, appetite breaches, compliance, and incident overview.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--indigo);font-weight:500">View Report <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/report/compliance" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--success),var(--success));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-shield-check" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Compliance Status</div>
        <div style="font-size:13px;color:var(--text-muted)">Per-package compliance breakdown, non-compliant controls, and recent activity.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--success);font-weight:500">View Report <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/report/risk" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--danger),var(--danger));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-exclamation-triangle-fill" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Risk Register</div>
        <div style="font-size:13px;color:var(--text-muted)">Full risk inventory with scores, categories, treatment plans, and open actions.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--danger);font-weight:500">View Report <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/audit" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--purple),var(--purple));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-clipboard2-check-fill" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Audit Status</div>
        <div style="font-size:13px;color:var(--text-muted)">Open audits, findings by severity, overdue items, and audit program summary.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--purple);font-weight:500">View Audits <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/incidents" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--danger),var(--danger));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-fire" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Incident Report</div>
        <div style="font-size:13px;color:var(--text-muted)">Open and resolved incidents, SLA compliance, severity breakdown, and MTTR trends.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--danger);font-weight:500">View Incidents <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/policy" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--info),var(--info));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-file-earmark-text-fill" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Policy Review</div>
        <div style="font-size:13px;color:var(--text-muted)">Policy lifecycle status, review schedules, pending approvals, and acknowledgement rates.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--info);font-weight:500">View Policies <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/vendors" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--warning),var(--warning));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-building-fill" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Vendor Risk</div>
        <div style="font-size:13px;color:var(--text-muted)">Third-party risk ratings, assessment status, contract expirations, and vendor tiers.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--warning);font-weight:500">View Vendors <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/export" class="card report-card">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--text-muted),#334155);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-download" style="color:var(--card-bg);font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Data Export</div>
        <div style="font-size:13px;color:var(--text-muted)">Export raw data as CSV or JSON for integration with external tools and GDPR portability.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--text-muted);font-weight:500">Go to Export <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

</div>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
