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

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:8px">

  <a href="/report/executive" class="card" style="text-decoration:none;color:inherit;transition:box-shadow 0.2s" onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow=''">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-graph-up-arrow" style="color:#fff;font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Executive Summary</div>
        <div style="font-size:13px;color:var(--text-muted)">GRC health score, top risks, upcoming reviews, and key metrics for leadership.</div>
        <div style="margin-top:12px;font-size:12px;color:var(--primary);font-weight:500">View Report <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/report/compliance" class="card" style="text-decoration:none;color:inherit;transition:box-shadow 0.2s" onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow=''">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#059669,#047857);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-shield-check" style="color:#fff;font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Compliance Status</div>
        <div style="font-size:13px;color:var(--text-muted)">Per-package compliance breakdown, non-compliant controls, and recent activity.</div>
        <div style="margin-top:12px;font-size:12px;color:#059669;font-weight:500">View Report <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/report/risk" class="card" style="text-decoration:none;color:inherit;transition:box-shadow 0.2s" onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow=''">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#dc2626,#b91c1c);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-exclamation-triangle-fill" style="color:#fff;font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Risk Register</div>
        <div style="font-size:13px;color:var(--text-muted)">Full risk inventory with scores, categories, treatment plans, and open actions.</div>
        <div style="margin-top:12px;font-size:12px;color:#dc2626;font-weight:500">View Report <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

  <a href="/export" class="card" style="text-decoration:none;color:inherit;transition:box-shadow 0.2s" onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow=''">
    <div class="card-body" style="display:flex;gap:16px;align-items:flex-start;padding:24px">
      <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#0284c7,#0369a1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-download" style="color:#fff;font-size:22px"></i>
      </div>
      <div>
        <div style="font-size:16px;font-weight:600;margin-bottom:4px">Data Export</div>
        <div style="font-size:13px;color:var(--text-muted)">Export raw data as CSV or JSON for integration with external tools.</div>
        <div style="margin-top:12px;font-size:12px;color:#0284c7;font-weight:500">Go to Export <i class="bi bi-arrow-right"></i></div>
      </div>
    </div>
  </a>

</div>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
