<?php ob_start(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Documentation</h1>
    <p class="page-subtitle">AEGIS GRC Platform — User Guide &amp; Administration Reference</p>
  </div>
</div>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

<!-- Sidebar nav -->
<div style="flex:0 0 220px;min-width:180px">
  <div class="card" style="position:sticky;top:80px">
    <div class="card-body" style="padding:8px 0">
      <?php
      $sections = [
        'overview'     => ['icon'=>'bi-house-fill',            'label'=>'Overview'],
        'compliance'   => ['icon'=>'bi-shield-check',          'label'=>'Compliance'],
        'risk'         => ['icon'=>'bi-exclamation-triangle-fill','label'=>'Risk Management'],
        'audit'        => ['icon'=>'bi-clipboard2-check-fill', 'label'=>'Audits'],
        'policy'       => ['icon'=>'bi-file-earmark-text-fill','label'=>'Policies'],
        'incident'     => ['icon'=>'bi-fire',                  'label'=>'Incidents'],
        'change'       => ['icon'=>'bi-arrow-repeat',          'label'=>'Change Management'],
        'bcp'          => ['icon'=>'bi-shield-fill-exclamation','label'=>'BCP / DR'],
        'vendor'       => ['icon'=>'bi-building',              'label'=>'Vendor Risk'],
        'api'          => ['icon'=>'bi-code-square',           'label'=>'API Reference'],
        'admin'        => ['icon'=>'bi-gear-fill',             'label'=>'Administration'],
        'integrations' => ['icon'=>'bi-plug-fill',             'label'=>'Integrations'],
      ];
      foreach ($sections as $key => $s):
      ?>
        <a href="?s=<?= $key ?>" class="nav-item <?= $section === $key ? 'active' : '' ?>" style="border-radius:0;padding:8px 16px">
          <i class="bi <?= $s['icon'] ?>"></i><span><?= $s['label'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Content -->
<div style="flex:1;min-width:0">

<?php if ($section === 'overview'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-house-fill"></i> Overview</h3></div>
  <div class="card-body docs-body">
    <h4>What is AEGIS GRC?</h4>
    <p>AEGIS is an enterprise Governance, Risk &amp; Compliance (GRC) platform built for security teams who need a single pane of glass for compliance management, risk tracking, audit execution, and policy governance.</p>

    <h4>Core Modules</h4>
    <div class="docs-grid">
      <div class="docs-card"><i class="bi bi-shield-check"></i><strong>Compliance</strong><p>Map controls to frameworks (ISO 27001, SOC 2, NIST, HIPAA, PCI-DSS) and track implementation status.</p></div>
      <div class="docs-card"><i class="bi bi-exclamation-triangle-fill"></i><strong>Risk Register</strong><p>Capture, score, and treat risks. Formal exception/waiver workflow with approval chains.</p></div>
      <div class="docs-card"><i class="bi bi-clipboard2-check-fill"></i><strong>Audits</strong><p>Schedule and execute internal/external audits. Export evidence packages as ZIP for auditors.</p></div>
      <div class="docs-card"><i class="bi bi-file-earmark-text-fill"></i><strong>Policies</strong><p>Author, version-control, and publish policies with owner assignment and review scheduling.</p></div>
      <div class="docs-card"><i class="bi bi-fire"></i><strong>Incidents</strong><p>Log and track security incidents through their full lifecycle with timeline updates.</p></div>
      <div class="docs-card"><i class="bi bi-building"></i><strong>Vendor Risk</strong><p>Assess third-party vendors, track contract dates, and score inherent risk by tier.</p></div>
    </div>

    <h4>Quick Start</h4>
    <ol>
      <li>Import a compliance framework via <strong>Compliance → Import Standard</strong></li>
      <li>Assign control owners and due dates in <strong>Compliance → Packages</strong></li>
      <li>Create your first risk in <strong>Risk → Risk Register</strong></li>
      <li>Schedule an audit in <strong>Audits → Create Audit</strong></li>
      <li>Configure email notifications in <strong>Admin → Email Settings</strong></li>
    </ol>
  </div>
</div>

<?php elseif ($section === 'compliance'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-shield-check"></i> Compliance</h3></div>
  <div class="card-body docs-body">
    <h4>Importing a Framework</h4>
    <p>Navigate to <strong>Compliance → Import Standard</strong>. Upload a JSON framework file or choose from built-in packages (ISO 27001, SOC 2 Type II, NIST 800-53 Rev 5, HIPAA, PCI-DSS v4, CMMC Level 2).</p>

    <h4>Control Implementation Statuses</h4>
    <table class="table">
      <tr><td><span class="badge badge-compliant">Compliant</span></td><td>Control is fully implemented and evidence collected</td></tr>
      <tr><td><span class="badge badge-non_compliant">Non-Compliant</span></td><td>Control is not implemented; remediation required</td></tr>
      <tr><td><span class="badge badge-partial">Partial</span></td><td>Control is partially implemented</td></tr>
      <tr><td><span class="badge badge-not_applicable">N/A</span></td><td>Control does not apply to this organization</td></tr>
    </table>

    <h4>Cross-Framework Mapping</h4>
    <p>Controls can be mapped across frameworks using the <code>control_mappings</code> table. When a control is marked compliant in one framework, the mapped control in another framework is highlighted automatically.</p>

    <h4>AI Gap Analysis</h4>
    <p>On any compliance package page, expand the <strong>AI Gap Analysis</strong> panel to get AI-generated suggestions for non-compliant controls. Requires an API key configured in <strong>Admin → System Settings</strong> (<code>ai_provider</code> and <code>ai_api_key</code>).</p>
  </div>
</div>

<?php elseif ($section === 'risk'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-exclamation-triangle-fill"></i> Risk Management</h3></div>
  <div class="card-body docs-body">
    <h4>Risk Scoring</h4>
    <p>Inherent risk score = <strong>Likelihood × Impact</strong> (each 1–5). The score is automatically calculated. Residual score applies after treatment controls.</p>
    <table class="table">
      <tr><th>Score</th><th>Level</th><th>Color</th></tr>
      <tr><td>1–4</td><td>Low</td><td><span style="color:#22c55e">●</span> Green</td></tr>
      <tr><td>5–9</td><td>Medium</td><td><span style="color:#f59e0b">●</span> Amber</td></tr>
      <tr><td>10–14</td><td>High</td><td><span style="color:#f97316">●</span> Orange</td></tr>
      <tr><td>15–25</td><td>Critical</td><td><span style="color:#ef4444">●</span> Red</td></tr>
    </table>

    <h4>Treatment Types</h4>
    <ul>
      <li><strong>Mitigate</strong> — Implement controls to reduce likelihood or impact</li>
      <li><strong>Transfer</strong> — Transfer risk via insurance or contract</li>
      <li><strong>Accept</strong> — Formally accept the residual risk (triggers exception workflow)</li>
      <li><strong>Avoid</strong> — Eliminate the activity that creates the risk</li>
    </ul>

    <h4>Risk Exceptions</h4>
    <p>When a risk treatment type is 'Accept', submit a formal exception via <strong>Risk Register → [Risk] → Request Exception</strong>. Exceptions require manager/admin approval, a rationale, and optionally an expiry date. Expired exceptions are flagged automatically.</p>

    <h4>SIEM Ingestion</h4>
    <p>Automatically import findings from security scanners via the REST API:</p>
    <pre><code>POST /api/v1/ingest/tenable
POST /api/v1/ingest/qualys
POST /api/v1/ingest/wiz
POST /api/v1/ingest/generic</code></pre>
    <p>Findings are deduplicated by <code>source_external_id</code> within 30 days.</p>
  </div>
</div>

<?php elseif ($section === 'audit'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-clipboard2-check-fill"></i> Audits</h3></div>
  <div class="card-body docs-body">
    <h4>Audit Lifecycle</h4>
    <ol>
      <li><strong>Planned</strong> — Scheduled, not yet started</li>
      <li><strong>In Progress</strong> — Auditor is assessing controls</li>
      <li><strong>Completed</strong> — All items assessed; score calculated</li>
    </ol>

    <h4>Assessing Controls</h4>
    <p>On the audit detail page, expand each control domain to assess individual controls. Set status, enter findings, add evidence notes, and assign a risk level for non-compliant items.</p>

    <h4>Evidence Files</h4>
    <p>Attach evidence files directly to the audit or to individual controls via the evidence upload widget. Supported formats: PDF, Word, Excel, PNG, JPEG, CSV, ZIP (max 20 MB per file).</p>

    <h4>Evidence Package Export</h4>
    <p>Click <strong>Export Package</strong> on any audit to download a ZIP containing:</p>
    <ul>
      <li>All evidence files (organized by control code)</li>
      <li><code>findings.csv</code> — Complete assessment results</li>
      <li><code>README.txt</code> — Audit summary with score and metadata</li>
    </ul>
    <p>The ZIP is ready for handoff to external auditors (SOC 2, ISO, PCI).</p>
  </div>
</div>

<?php elseif ($section === 'api'): ?>
<div class="card">
  <div class="card-header">
    <h3><i class="bi bi-code-square"></i> API Reference</h3>
    <a href="/api/docs" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-up-right"></i> Open Swagger UI</a>
  </div>
  <div class="card-body docs-body">
    <h4>Authentication</h4>
    <p>All API endpoints (except <code>POST /api/v1/auth/token</code>) require one of:</p>
    <ul>
      <li><code>X-API-Key: &lt;your-key&gt;</code> header — generate keys in Admin → API Keys</li>
      <li><code>Authorization: Bearer &lt;jwt&gt;</code> — obtain JWT from <code>POST /api/v1/auth/token</code></li>
    </ul>

    <h4>Rate Limiting</h4>
    <p>API requests are rate-limited to 60 per minute per IP. Exceeding this returns HTTP 429.</p>

    <h4>Key Endpoints</h4>
    <table class="table">
      <tr><th>Method</th><th>Path</th><th>Description</th></tr>
      <tr><td>POST</td><td><code>/api/v1/auth/token</code></td><td>Get JWT token</td></tr>
      <tr><td>GET</td><td><code>/api/v1/risks</code></td><td>List risks</td></tr>
      <tr><td>POST</td><td><code>/api/v1/risks</code></td><td>Create risk</td></tr>
      <tr><td>GET</td><td><code>/api/v1/compliance/packages</code></td><td>List frameworks</td></tr>
      <tr><td>POST</td><td><code>/api/v1/ingest/tenable</code></td><td>Ingest Tenable findings</td></tr>
      <tr><td>GET</td><td><code>/api/v1/dashboard/stats</code></td><td>GRC summary stats</td></tr>
    </table>
    <p><a href="/api/docs" target="_blank" class="btn btn-ghost btn-sm"><i class="bi bi-book"></i> Full API docs (Swagger UI)</a></p>
  </div>
</div>

<?php elseif ($section === 'admin'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-gear-fill"></i> Administration</h3></div>
  <div class="card-body docs-body">
    <h4>User Management</h4>
    <p>Create and manage users at <strong>Admin → Users</strong>. Available roles:</p>
    <table class="table">
      <tr><th>Role</th><th>Capabilities</th></tr>
      <tr><td>admin</td><td>Full access to all modules and admin functions</td></tr>
      <tr><td>manager</td><td>Read/write all modules; approve requests; no admin settings</td></tr>
      <tr><td>auditor</td><td>Read all modules; write audits and evidence only</td></tr>
      <tr><td>analyst</td><td>Read/write compliance, risk, incidents, issues</td></tr>
      <tr><td>viewer</td><td>Read-only access to assigned modules</td></tr>
    </table>

    <h4>SSO / OIDC</h4>
    <p>Configure SSO at <strong>Admin → SSO / OIDC</strong>. Supported providers: Azure AD, Okta, Google Workspace, any OIDC-compliant IdP. Role mapping via ID token claims is supported.</p>

    <h4>Crontab (Required)</h4>
    <pre><code># Workflow automation (every 5 min)
*/5 * * * * php /var/www/aegis/scripts/run_workflows.php

# Webhook dispatch (every minute)
* * * * * php /var/www/aegis/scripts/dispatch_webhooks.php

# Daily metrics snapshot (midnight)
0 0 * * * php /var/www/aegis/scripts/capture_metrics_snapshot.php

# Email notifications (hourly)
0 * * * * php /var/www/aegis/scripts/send_notifications.php

# Scheduled report emails (hourly)
0 * * * * php /var/www/aegis/scripts/send_scheduled_reports.php</code></pre>

    <h4>Data Retention</h4>
    <p>Configure automatic data purge policies at <strong>Admin → Data Retention</strong>. Activity logs, notification logs, webhook deliveries, and alerts can be configured for automatic deletion after N days.</p>

    <h4>Storage</h4>
    <p>Configure file storage at <strong>Admin → Storage</strong>. Supports local disk (default) or any S3-compatible object store (AWS S3, MinIO, Cloudflare R2).</p>
  </div>
</div>

<?php elseif ($section === 'integrations'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-plug-fill"></i> Integrations</h3></div>
  <div class="card-body docs-body">
    <h4>Outbound Webhooks</h4>
    <p>Configure at <strong>Admin → Webhooks</strong>. Supported destinations:</p>
    <ul>
      <li><strong>Slack</strong> — Block Kit formatted messages</li>
      <li><strong>PagerDuty</strong> — Events API v2 with severity mapping</li>
      <li><strong>Jira</strong> — ADF-formatted issue creation</li>
      <li><strong>Generic HTTP</strong> — JSON POST with HMAC-SHA256 signature</li>
    </ul>
    <p>Webhook payloads are signed with <code>X-AEGIS-Signature: sha256=&lt;hmac&gt;</code>. Verify against your configured secret.</p>

    <h4>SIEM / Scanner Ingestion</h4>
    <p>Push vulnerability findings from security tools directly into the risk register:</p>
    <pre><code>curl -X POST https://your-instance/api/v1/ingest/tenable \
  -H "X-API-Key: &lt;key&gt;" \
  -H "Content-Type: application/json" \
  -d '{"vulnerabilities": [...]}'</code></pre>

    <h4>Email (SMTP)</h4>
    <p>Configure SMTP at <strong>Admin → Email Settings</strong>. Supports TLS/STARTTLS on any port. Used for notifications, scheduled reports, and approval reminders.</p>

    <h4>AI Advisor</h4>
    <p>Configure at <strong>Admin → System Settings</strong>. Supported providers:</p>
    <ul>
      <li><strong>Claude</strong> (Anthropic) — set <code>ai_provider=claude</code> and <code>ai_api_key</code></li>
      <li><strong>OpenAI</strong> — set <code>ai_provider=openai</code> and <code>ai_api_key</code></li>
    </ul>
  </div>
</div>

<?php else: ?>
<div class="card">
  <div class="card-header"><h3><?= Security::h($sections[$section]['label'] ?? ucfirst($section)) ?></h3></div>
  <div class="card-body docs-body">
    <p class="text-muted">Documentation for this section is being prepared. Check back soon, or refer to the <a href="?s=overview">Overview</a>.</p>
  </div>
</div>
<?php endif; ?>

</div><!-- end content -->
</div><!-- end flex wrapper -->

<style>
.docs-body h4 { margin:20px 0 8px; font-size:15px; font-weight:600; }
.docs-body p { margin-bottom:10px; line-height:1.7; }
.docs-body ul, .docs-body ol { margin:0 0 12px; padding-left:24px; line-height:1.7; }
.docs-body pre { background:#0f172a; color:#e2e8f0; padding:12px 16px; border-radius:8px; font-size:12px; overflow-x:auto; margin:8px 0 16px; }
.docs-body code { background:#f1f5f9; color:#1e3a5f; padding:1px 5px; border-radius:3px; font-size:12px; }
.docs-body pre code { background:none; color:inherit; padding:0; }
.docs-body .table td, .docs-body .table th { padding:8px 12px; vertical-align:top; }
.docs-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin:12px 0 20px; }
.docs-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; }
.docs-card i { font-size:20px; color:#6366f1; display:block; margin-bottom:6px; }
.docs-card strong { display:block; margin-bottom:4px; }
.docs-card p { margin:0; font-size:13px; color:#64748b; }
</style>
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
?>
