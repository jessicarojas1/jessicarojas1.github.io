<?php ob_start(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Documentation</h1>
    <p class="page-subtitle">AEGIS GRC Platform — Work Instructions, User Guide &amp; Module Reference</p>
  </div>
</div>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

<!-- Sidebar nav -->
<div style="flex:0 0 220px;min-width:180px">
  <div class="card" style="position:sticky;top:80px">
    <div class="card-body" style="padding:8px 0">
      <?php
      $sections = [
        'overview'       => ['icon'=>'bi-house-fill',              'label'=>'Overview'],
        'interconnect'   => ['icon'=>'bi-diagram-3-fill',          'label'=>'Module Map'],
        'quickstart'     => ['icon'=>'bi-rocket-takeoff-fill',     'label'=>'Quick Start'],
        'workflows'      => ['icon'=>'bi-person-lines-fill',       'label'=>'Role Workflows'],
        'compliance'     => ['icon'=>'bi-shield-check',            'label'=>'Compliance'],
        'ssp'            => ['icon'=>'bi-file-earmark-lock2-fill', 'label'=>'SSP'],
        'poam'           => ['icon'=>'bi-list-check',              'label'=>'POA&M'],
        'odp'            => ['icon'=>'bi-sliders',                 'label'=>'ODP Center'],
        'sprs'           => ['icon'=>'bi-speedometer2',            'label'=>'SPRS Score'],
        'raci'           => ['icon'=>'bi-people-fill',             'label'=>'RACI Matrix'],
        'audit_findings' => ['icon'=>'bi-journal-x',              'label'=>'Audit Findings'],
        'risk'           => ['icon'=>'bi-exclamation-triangle-fill','label'=>'Risk Management'],
        'threats'        => ['icon'=>'bi-crosshair',               'label'=>'Threat Register'],
        'treatment'      => ['icon'=>'bi-bandaid-fill',            'label'=>'Treatment Plans'],
        'audit'          => ['icon'=>'bi-clipboard2-check-fill',   'label'=>'Audits'],
        'policy'         => ['icon'=>'bi-file-earmark-text-fill',  'label'=>'Policies'],
        'playbooks'      => ['icon'=>'bi-book-fill',               'label'=>'Playbooks'],
        'incident'       => ['icon'=>'bi-fire',                    'label'=>'Incidents'],
        'issues'         => ['icon'=>'bi-bug-fill',                'label'=>'Issues'],
        'change'         => ['icon'=>'bi-arrow-repeat',            'label'=>'Change Management'],
        'bcp'            => ['icon'=>'bi-shield-fill-exclamation', 'label'=>'BCP / DR'],
        'vendor'         => ['icon'=>'bi-building',                'label'=>'Vendor Risk'],
        'questionnaire'  => ['icon'=>'bi-question-circle-fill',    'label'=>'Questionnaires'],
        'projects'       => ['icon'=>'bi-briefcase-fill',          'label'=>'GRC Projects'],
        'automation'     => ['icon'=>'bi-lightning-fill',          'label'=>'Automation Rules'],
        'dashboards'     => ['icon'=>'bi-layout-wtf',              'label'=>'Custom Dashboards'],
        'cui'            => ['icon'=>'bi-lock-fill',               'label'=>'CUI Inventory'],
        'kri'            => ['icon'=>'bi-activity',                'label'=>'KRI Dashboard'],
        'assets'         => ['icon'=>'bi-server',                  'label'=>'Asset Inventory'],
        'privacy'        => ['icon'=>'bi-shield-lock-fill',        'label'=>'Data Privacy'],
        'awareness'      => ['icon'=>'bi-mortarboard-fill',        'label'=>'Awareness Training'],
        'account_reviews'=> ['icon'=>'bi-person-check-fill',       'label'=>'Account Reviews'],
        'documents'      => ['icon'=>'bi-folder2-open',            'label'=>'Document Management'],
        'evidence'       => ['icon'=>'bi-paperclip',               'label'=>'Evidence'],
        'approvals'      => ['icon'=>'bi-check2-circle',           'label'=>'Approval Workflows'],
        'reports'        => ['icon'=>'bi-file-earmark-bar-graph',  'label'=>'Reports'],
        'export'         => ['icon'=>'bi-download',                'label'=>'Export'],
        'metrics'        => ['icon'=>'bi-graph-up',                'label'=>'Metrics &amp; Trends'],
        'api'            => ['icon'=>'bi-code-square',             'label'=>'API Reference'],
        'admin'          => ['icon'=>'bi-gear-fill',               'label'=>'Administration'],
        'integrations'   => ['icon'=>'bi-plug-fill',               'label'=>'Integrations'],
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
    <p>AEGIS is an enterprise Governance, Risk &amp; Compliance (GRC) platform built for security and compliance teams who need a unified system for compliance management, risk tracking, audit execution, policy governance, and automated remediation workflows.</p>

    <h4>Core Module Areas</h4>
    <div class="docs-grid">
      <div class="docs-card"><i class="bi bi-shield-check"></i><strong>Compliance</strong><p>Map controls to frameworks (ISO 27001, SOC 2, NIST 800-171, CMMC, HIPAA, PCI-DSS) and track implementation status with evidence.</p></div>
      <div class="docs-card"><i class="bi bi-exclamation-triangle-fill"></i><strong>Risk Register</strong><p>Capture, score (Likelihood × Impact), and treat risks. Formal exception workflow with approval chains.</p></div>
      <div class="docs-card"><i class="bi bi-clipboard2-check-fill"></i><strong>Audits</strong><p>Schedule and execute internal/external audits with evidence packages for auditor handoff.</p></div>
      <div class="docs-card"><i class="bi bi-file-earmark-text-fill"></i><strong>Policies</strong><p>Author, version-control, and publish policies with owner assignment and attestation campaigns.</p></div>
      <div class="docs-card"><i class="bi bi-fire"></i><strong>Incidents</strong><p>Log and track security incidents through their full lifecycle with SLA tracking and playbooks.</p></div>
      <div class="docs-card"><i class="bi bi-building"></i><strong>Vendor Risk</strong><p>Assess third-party vendors, track contract expiry, and score inherent risk by tier.</p></div>
      <div class="docs-card"><i class="bi bi-file-earmark-lock2-fill"></i><strong>SSP</strong><p>Author System Security Plans for NIST/CMMC certifications with per-control implementation statements.</p></div>
      <div class="docs-card"><i class="bi bi-list-check"></i><strong>POA&amp;M</strong><p>Auto-generate Plans of Action from non-compliant controls with milestone tracking.</p></div>
      <div class="docs-card"><i class="bi bi-lightning-fill"></i><strong>Automation</strong><p>Define rules that trigger automatic actions (create issues, fire webhooks, send alerts) when GRC events occur.</p></div>
      <div class="docs-card"><i class="bi bi-briefcase-fill"></i><strong>GRC Projects</strong><p>Manage remediation projects with task lists, budget tracking, and links to risks, controls, issues, and findings.</p></div>
      <div class="docs-card"><i class="bi bi-people-fill"></i><strong>RACI Matrix</strong><p>Define Responsible, Accountable, Consulted, and Informed roles per compliance domain across your team.</p></div>
      <div class="docs-card"><i class="bi bi-lock-fill"></i><strong>CUI Inventory</strong><p>Track Controlled Unclassified Information assets for CMMC/NIST SP 800-171 compliance.</p></div>
    </div>

    <h4>Recommended Starting Path</h4>
    <ol>
      <li>Go to <strong>Admin → Users</strong> and invite your team with appropriate roles</li>
      <li>Import a compliance framework via <strong>Compliance → Import Standard</strong></li>
      <li>Assign control owners and set status in <strong>Compliance → Packages</strong></li>
      <li>Define RACI roles per domain in <strong>RACI Matrix</strong></li>
      <li>Create your first risk in <strong>Risk → Risk Register</strong></li>
      <li>Set up automation rules to trigger issue creation for high risks and non-compliant controls</li>
      <li>Configure email notifications in <strong>Admin → Email Settings</strong></li>
    </ol>

    <h4>Key Concepts</h4>
    <table class="table">
      <tr><th>Term</th><th>Meaning</th></tr>
      <tr><td>Package</td><td>A compliance framework instance (e.g., your ISO 27001 2022 assessment)</td></tr>
      <tr><td>Objective / Control</td><td>A requirement within a framework (e.g., A.8.1 — Asset Inventory)</td></tr>
      <tr><td>Implementation</td><td>Your organization's record of how a control is addressed</td></tr>
      <tr><td>Evidence</td><td>Files or notes proving a control is implemented</td></tr>
      <tr><td>POA&amp;M Item</td><td>A remediation commitment for a non-compliant control, with milestones and deadlines</td></tr>
      <tr><td>SPRS Score</td><td>Supplier Performance Risk Score for NIST SP 800-171; starts at 110, deducted per gap</td></tr>
    </table>
  </div>
</div>

<?php elseif ($section === 'interconnect'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-diagram-3-fill"></i> Module Interconnections</h3></div>
  <div class="card-body docs-body">
    <p>AEGIS modules are deeply interconnected. Understanding how data flows between them helps you use the platform to its full potential.</p>

    <h4>The Core Flow</h4>
    <div style="background:var(--bg);border-radius:8px;padding:16px;margin-bottom:20px;font-size:0.85rem;line-height:2;">
      <strong>Compliance Package</strong> → defines controls → <strong>Control Testing</strong> evaluates each control →<br>
      non-compliant controls → <strong>POA&amp;M</strong> (remediation plan) + <strong>Issues</strong> (actionable tasks)<br>
      non-compliant controls → <strong>SPRS Score</strong> (deduction from 110 baseline)<br>
      non-compliant controls → <strong>Automation Rules</strong> (trigger: create issue, send webhook)<br>
      Compliance Package → <strong>SSP</strong> (attach packages; write per-control implementation statements)<br>
      Compliance Package → <strong>RACI</strong> (assign R/A/C/I per domain)<br>
      Compliance Package → <strong>ODP</strong> (set organization-specific parameter values)<br>
      Compliance Package → <strong>Audit</strong> (attach as scope for internal audit)<br>
      Compliance Package → <strong>Audit Findings</strong> (findings linked to package + control)
    </div>

    <h4>Risk ↔ Everything</h4>
    <table class="table">
      <tr><th>Module</th><th>How It Connects to Risk</th></tr>
      <tr><td>Compliance</td><td>Controls can be linked to risks; non-compliant controls raise inherent risk</td></tr>
      <tr><td>Issues</td><td>Issues can be created from risks; an issue resolving a risk is tracked in the risk record</td></tr>
      <tr><td>GRC Projects</td><td>Projects can be linked to risks; treatment plans within a project address specific risks</td></tr>
      <tr><td>Incidents</td><td>Incidents can create risks or be linked to existing risks that materialized</td></tr>
      <tr><td>Vendor Risk</td><td>Vendor assessments create inherent risks tied to the vendor's risk tier</td></tr>
      <tr><td>Threats</td><td>Threat register entries map to risks, pre-populating threat source and category</td></tr>
      <tr><td>KRI</td><td>KRI thresholds crossing trigger risk flags and notification alerts</td></tr>
      <tr><td>Assets</td><td>Assets linked to risks establish scope and potential impact</td></tr>
      <tr><td>BCP/DR</td><td>Business continuity scenarios reference risks; RTO/RPO drives risk treatment</td></tr>
      <tr><td>Automation</td><td>High risk score trigger fires webhook/email/issue automatically</td></tr>
    </table>

    <h4>Incident Response Chain</h4>
    <table class="table">
      <tr><th>Module</th><th>Role in Incident Response</th></tr>
      <tr><td>Incidents</td><td>Central record; captures timeline, severity, SLA clock, responders</td></tr>
      <tr><td>Playbooks</td><td>Step-by-step runbook attached to incident type; tracks completion per step</td></tr>
      <tr><td>Issues</td><td>Spawned from incident to track specific remediation tasks</td></tr>
      <tr><td>Change Requests</td><td>Emergency changes raised during incident response</td></tr>
      <tr><td>Risk</td><td>Materialized risk linked to incident for post-incident risk review</td></tr>
      <tr><td>Audit Findings</td><td>Post-incident audit may generate findings linked to the event</td></tr>
      <tr><td>Automation</td><td>Incident Created trigger → auto-create issue, notify team via webhook</td></tr>
    </table>

    <h4>Audit &amp; Compliance Chain</h4>
    <table class="table">
      <tr><th>Module</th><th>Connection</th></tr>
      <tr><td>Compliance Packages</td><td>Scoped to audit; controls assessed against implementation status</td></tr>
      <tr><td>Audit Findings</td><td>Formal findings from external auditors logged separately from internal testing</td></tr>
      <tr><td>POA&amp;M</td><td>Auto-generated from non-compliant controls; tracks remediation commitments</td></tr>
      <tr><td>Evidence</td><td>Uploaded to controls in compliance or audits; bundled in export ZIP</td></tr>
      <tr><td>SSP</td><td>Written before audit; per-control statements reference implementation and evidence</td></tr>
      <tr><td>Issues</td><td>Audit findings generate issues for operational teams to resolve</td></tr>
      <tr><td>GRC Projects</td><td>Large remediation bodies of work tracked as projects with budget</td></tr>
    </table>

    <h4>Vendor Risk Chain</h4>
    <table class="table">
      <tr><th>Module</th><th>Connection</th></tr>
      <tr><td>Vendor</td><td>Central vendor record with tier, inherent risk, and assessment scores</td></tr>
      <tr><td>Contracts</td><td>Contract expiry dates trigger automation rules (send alert, create issue)</td></tr>
      <tr><td>Questionnaires</td><td>Sent to vendors for self-assessment; responses update vendor risk posture</td></tr>
      <tr><td>Issues</td><td>Vendor assessment findings create issues assigned to vendor owner</td></tr>
      <tr><td>Risk</td><td>Vendor assessments may generate formal risks in the risk register</td></tr>
      <tr><td>Automation</td><td>Vendor Contract Expiring trigger → auto-create issue 30 days before expiry</td></tr>
    </table>

    <h4>CMMC / NIST Module Stack</h4>
    <p>For organizations pursuing CMMC or NIST SP 800-171, use these modules together:</p>
    <ol>
      <li><strong>Compliance Package</strong> — import CMMC Level 2 or NIST SP 800-171 framework</li>
      <li><strong>ODP Center</strong> — define organization-specific parameter values for each control</li>
      <li><strong>RACI Matrix</strong> — assign accountability across domains</li>
      <li><strong>SSP</strong> — document implementation statements for each practice</li>
      <li><strong>CUI Inventory</strong> — identify where CUI lives in your environment</li>
      <li><strong>POA&amp;M</strong> — generate remediation plans for non-compliant practices</li>
      <li><strong>SPRS Score</strong> — monitor your running SPRS score as gaps are closed</li>
      <li><strong>GRC Projects</strong> — track large remediation initiatives with budget</li>
      <li><strong>Audit Findings</strong> — log C3PAO or DIBCAC assessment findings</li>
    </ol>
  </div>
</div>

<?php elseif ($section === 'quickstart'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-rocket-takeoff-fill"></i> Quick Start Guide</h3></div>
  <div class="card-body docs-body">
    <h4>Day 1 — Set Up Your Environment</h4>
    <ol>
      <li>Log in as <strong>admin</strong></li>
      <li>Go to <strong>Admin → Users</strong> → Invite your compliance manager, risk analyst, and IT lead with appropriate roles</li>
      <li>Go to <strong>Admin → Email Settings</strong> → Configure SMTP so notifications go out</li>
      <li>Optionally, go to <strong>Admin → SSO / OIDC</strong> to configure Azure AD or Okta login</li>
    </ol>

    <h4>Day 2 — Load Your Compliance Framework</h4>
    <ol>
      <li>Go to <strong>Compliance → Import Standard</strong> and upload your framework JSON (ISO 27001, NIST 800-171, SOC 2, etc.)</li>
      <li>Open the new package and go through each domain, setting the control implementation status (Compliant / Partial / Non-Compliant / N/A)</li>
      <li>Attach evidence files to controls where implementation is complete</li>
      <li>Go to <strong>RACI Matrix</strong> → select your package → assign R/A/C/I roles per domain</li>
    </ol>

    <h4>Day 3 — Risk Register &amp; Remediation</h4>
    <ol>
      <li>Go to <strong>Risk → Risk Register → New Risk</strong> for each identified risk</li>
      <li>Set Likelihood (1–5) and Impact (1–5) — the score is calculated automatically</li>
      <li>Assign an owner and treatment type (Mitigate, Transfer, Accept, Avoid)</li>
      <li>For non-compliant controls, go to <strong>POA&amp;M</strong> → Generate from Package to auto-create remediation items</li>
      <li>Create a <strong>GRC Project</strong> to group related remediation tasks with a budget</li>
    </ol>

    <h4>Day 4 — Automation &amp; Monitoring</h4>
    <ol>
      <li>Go to <strong>Automation Rules → New Rule</strong></li>
      <li>Set trigger: <em>Risk Score High</em> (≥15), action: <em>Create Issue</em></li>
      <li>Add another rule: trigger <em>Vendor Contract Expiring</em> (30 days), action <em>Send Email</em></li>
      <li>Go to <strong>Custom Dashboards → New Dashboard</strong> and add widgets for open risks, compliance summary, and open incidents</li>
      <li>Go to <strong>KRI Dashboard</strong> to set thresholds for key risk indicators</li>
    </ol>

    <h4>Day 5 — Policies &amp; Training</h4>
    <ol>
      <li>Go to <strong>Policies → New Policy</strong> and upload or write your Information Security Policy</li>
      <li>Set review frequency and assign an owner</li>
      <li>Launch an attestation campaign: <strong>Policies → [Policy] → Attestations → New Campaign</strong></li>
      <li>Go to <strong>Awareness Training</strong> and create training modules for new joiners</li>
    </ol>

    <h4>Ongoing — Audit Cycle</h4>
    <ol>
      <li>Schedule an internal audit via <strong>Audits → New Audit</strong></li>
      <li>Assess all controls in scope, uploading evidence</li>
      <li>Export the evidence package ZIP for external auditors</li>
      <li>Log external auditor findings in <strong>Audit Findings</strong></li>
      <li>Track remediation via <strong>POA&amp;M</strong> and <strong>Issues</strong></li>
    </ol>
  </div>
</div>

<?php elseif ($section === 'compliance'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-shield-check"></i> Compliance Packages</h3></div>
  <div class="card-body docs-body">
    <h4>Creating a Package</h4>
    <p>A <strong>Compliance Package</strong> is your organization's instance of a framework. You can have multiple packages for different frameworks or separate scopes (e.g., one ISO 27001 for your SaaS product and another for your corporate environment).</p>
    <ol>
      <li>Go to <strong>Compliance → Import Standard</strong> to upload a JSON framework</li>
      <li>Or go to <strong>Compliance → New Package</strong> to create a manual package with custom controls</li>
      <li>Give the package a name and assign a primary owner</li>
    </ol>

    <h4>Control Implementation Statuses</h4>
    <table class="table">
      <tr><td><span class="badge badge-success">Compliant</span></td><td>Control is fully implemented and evidence collected</td><td>SPRS: no deduction</td></tr>
      <tr><td><span class="badge badge-warning">Partial</span></td><td>Control is partially implemented; gaps remain</td><td>SPRS: −0.5 pts</td></tr>
      <tr><td><span class="badge badge-danger">Non-Compliant</span></td><td>Control is not implemented; remediation required</td><td>SPRS: −1 pt</td></tr>
      <tr><td><span class="badge badge-secondary">Not Assessed</span></td><td>Not yet evaluated</td><td>SPRS: −1 pt</td></tr>
      <tr><td><span class="badge badge-info">N/A</span></td><td>Control does not apply to this organization</td><td>SPRS: no deduction</td></tr>
    </table>

    <h4>Control Testing</h4>
    <p>In <strong>Compliance → Control Testing</strong>, you can run structured tests on individual controls. Each test records:</p>
    <ul>
      <li>Test procedure performed</li>
      <li>Evidence references</li>
      <li>Pass / Fail / Partial result</li>
      <li>Tester and date</li>
    </ul>
    <p>Test results automatically update the control's implementation status.</p>

    <h4>Gap Analysis</h4>
    <p><strong>Compliance → Gap Analysis</strong> shows a heat map of your control coverage across all active packages. Domains with the most non-compliant controls are highlighted. Use this to prioritize remediation work and assign POA&amp;M items.</p>

    <h4>AI Suggestions</h4>
    <p>On any compliance package page, the <strong>AI Gap Analysis</strong> panel generates implementation suggestions for non-compliant controls. Requires <code>ai_api_key</code> configured in <strong>Admin → System Settings</strong>.</p>

    <h4>Downstream Effects</h4>
    <ul>
      <li>Non-compliant controls → auto-populate <strong>POA&amp;M</strong> when you click "Generate from Package"</li>
      <li>Non-compliant controls → reduce <strong>SPRS Score</strong> (for NIST/CMMC packages)</li>
      <li>Non-compliant controls trigger <strong>Automation Rules</strong> if a matching rule is active</li>
      <li>Package is selectable when creating an <strong>SSP</strong> or <strong>Audit</strong></li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'ssp'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-file-earmark-lock2-fill"></i> System Security Plans (SSP)</h3></div>
  <div class="card-body docs-body">
    <h4>What Is an SSP?</h4>
    <p>A System Security Plan (SSP) is a formal document that describes how a system meets security requirements. It is required for NIST SP 800-171, CMMC, FedRAMP, and many government contracts. AEGIS generates and maintains your SSP in a structured, editable format.</p>

    <h4>Creating an SSP</h4>
    <ol>
      <li>Go to <strong>Compliance → Sec. Plans (SSP) → New SSP</strong></li>
      <li>Enter system name, description, system type, and deployment model (cloud, on-premise, hybrid)</li>
      <li>Set impact levels (Confidentiality / Integrity / Availability: Low / Moderate / High)</li>
      <li>Select one or more compliance packages to attach to this SSP</li>
      <li>Click <strong>Create</strong></li>
    </ol>

    <h4>Writing Implementation Statements</h4>
    <ol>
      <li>Open the SSP and click <strong>Generate SSP Document</strong></li>
      <li>The document shows all controls from the attached packages</li>
      <li>For each control, click the <strong>Implementation Statement</strong> field and type your organization's description of how the control is implemented</li>
      <li>Optionally add <strong>Objective Responses</strong> (for NIST-style sub-objective notation like 3.1.1[a]) and <strong>Responsible Roles</strong></li>
      <li>Each field saves instantly via AJAX — no page reload required</li>
    </ol>

    <h4>Printing / Exporting</h4>
    <p>On the SSP document page, click <strong>Print / Export PDF</strong> (Ctrl+P). The print CSS hides the editing UI and renders a clean, audit-ready document with headers, control codes, and all implementation statements.</p>

    <h4>Best Practices</h4>
    <ul>
      <li>Complete all <strong>ODP (Organizationally Defined Parameter)</strong> values before writing your SSP — the parameters appear in control text</li>
      <li>Attach <strong>all relevant packages</strong> to the SSP (e.g., attach both your CMMC and your internal policy package)</li>
      <li>Review the SSP before each certification assessment; update implementation statements as your environment changes</li>
      <li>Link your SSP to a <strong>GRC Project</strong> to track the effort of completing all statements</li>
    </ul>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance Packages</strong> — SSP pulls controls and implementation status directly from attached packages</li>
      <li><strong>ODP Center</strong> — parameter values are referenced in control text within the SSP</li>
      <li><strong>RACI Matrix</strong> — responsible roles in the SSP align with your RACI assignments</li>
      <li><strong>Audit Findings</strong> — external findings against the SSP scope are logged separately</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'poam'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-list-check"></i> POA&amp;M — Plans of Action &amp; Milestones</h3></div>
  <div class="card-body docs-body">
    <h4>What Is a POA&amp;M?</h4>
    <p>A Plan of Action &amp; Milestones (POA&amp;M) documents how an organization will remediate identified security weaknesses. It is required by NIST SP 800-171, CMMC, FedRAMP, and most DoD contracts. Each POA&amp;M item describes a specific gap, the planned remediation, resources required, and target completion milestones.</p>

    <h4>Generating a POA&amp;M</h4>
    <ol>
      <li>Go to <strong>Compliance → POA&amp;M</strong></li>
      <li>Click <strong>Generate from Package</strong></li>
      <li>Select the compliance package to scan (e.g., NIST SP 800-171)</li>
      <li>AEGIS automatically creates a POA&amp;M item for every control with status <em>Non-Compliant</em> or <em>Partial</em></li>
      <li>Each item is numbered sequentially (POAM-0001, POAM-0002, …)</li>
    </ol>

    <h4>Managing POA&amp;M Items</h4>
    <p>Open a POA&amp;M item to:</p>
    <ul>
      <li>Add a <strong>remediation description</strong> (what will be done)</li>
      <li>Set <strong>resources required</strong> (staff, tools, budget)</li>
      <li>Assign a <strong>responsible individual</strong> and <strong>target completion date</strong></li>
      <li>Track <strong>milestones</strong> — discrete steps with individual due dates and completion checkboxes</li>
      <li>Record <strong>actual completion date</strong> when the gap is closed</li>
    </ul>

    <h4>POA&amp;M Workflow</h4>
    <ol>
      <li><strong>Open</strong> — Gap identified; remediation not started</li>
      <li><strong>In Progress</strong> — At least one milestone completed</li>
      <li><strong>Resolved</strong> — Gap closed; control implementation updated to Compliant</li>
      <li><strong>Risk Accepted</strong> — Leadership formally accepts the risk; POA&amp;M remains open as a waiver</li>
    </ol>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance Packages</strong> — POA&amp;M items are generated directly from non-compliant controls</li>
      <li><strong>SPRS Score</strong> — closing POA&amp;M items (by updating controls to Compliant) improves the score</li>
      <li><strong>GRC Projects</strong> — link a POA&amp;M item to a project for budget and task tracking</li>
      <li><strong>Issues</strong> — create granular issues for day-to-day remediation tasks supporting a POA&amp;M item</li>
      <li><strong>Audit Findings</strong> — C3PAO or DIBCAC findings often become formal POA&amp;M items</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'odp'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-sliders"></i> ODP Center — Organizationally Defined Parameters</h3></div>
  <div class="card-body docs-body">
    <h4>What Are ODPs?</h4>
    <p>Many NIST SP 800-171 and CMMC controls contain <strong>organization-defined parameters</strong> — blanks that each organization must fill in with specific values. For example, AC.1.001 requires defining the types of users authorized to access the system. The ODP Center is where you record these values.</p>

    <h4>How to Set ODP Values</h4>
    <ol>
      <li>Go to <strong>Compliance → ODP Center</strong></li>
      <li>Select a compliance package</li>
      <li>For each control that has ODPs, enter the parameter name (e.g., "authorized users") and your organization's defined value (e.g., "employees, contractors with signed NDA")</li>
      <li>Click <strong>Save</strong></li>
    </ol>

    <h4>Why ODPs Matter</h4>
    <ul>
      <li>Auditors (C3PAOs, DIBCACs) will verify that every ODP has a defined value in your SSP</li>
      <li>ODP values become part of your SSP implementation statements — they tell auditors exactly what "appropriate" or "defined" means for your organization</li>
      <li>Undefined ODPs are a common audit finding; the ODP Center tracks which are still missing</li>
    </ul>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance Packages</strong> — ODPs are defined per package</li>
      <li><strong>SSP</strong> — ODP values are referenced when writing control implementation statements</li>
      <li><strong>Audit Findings</strong> — undefined ODPs may appear as audit findings</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'sprs'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-speedometer2"></i> SPRS Score</h3></div>
  <div class="card-body docs-body">
    <h4>What Is the SPRS Score?</h4>
    <p>The <strong>Supplier Performance Risk Score (SPRS)</strong> is a DoD-mandated self-assessment score for NIST SP 800-171 compliance. It starts at <strong>110 points</strong> (all 110 practices compliant) and deducts points for each gap. DoD primes and their subcontractors must upload their SPRS score to the Supplier Performance Risk System before contract award.</p>

    <h4>Scoring Formula</h4>
    <table class="table">
      <tr><th>Control Status</th><th>Deduction per Practice</th></tr>
      <tr><td><span class="badge badge-danger">Non-Compliant</span></td><td>−1.0 point</td></tr>
      <tr><td><span class="badge badge-warning">Partial</span></td><td>−0.5 points</td></tr>
      <tr><td><span class="badge badge-secondary">Not Assessed</span></td><td>−1.0 point</td></tr>
      <tr><td><span class="badge badge-success">Compliant</span></td><td>No deduction</td></tr>
      <tr><td><span class="badge badge-info">N/A</span></td><td>No deduction</td></tr>
    </table>
    <p>The minimum possible score is <strong>−203</strong>. A score of 110 means full compliance. Negative scores are common and acceptable — the goal is steady improvement over time.</p>

    <h4>How to Use</h4>
    <ol>
      <li>Import your NIST SP 800-171 framework as a compliance package</li>
      <li>Set the status of all 110 practices</li>
      <li>Go to <strong>Compliance → SPRS Score</strong> to see your current score per package</li>
      <li>The score updates automatically as you change control statuses</li>
      <li>Use <strong>POA&amp;M</strong> to track remediation; each closure improves the score</li>
    </ol>

    <h4>Score Color Bands</h4>
    <ul>
      <li><span style="color:var(--success);font-weight:600;">Green (80–110)</span> — Strong posture; minor gaps only</li>
      <li><span style="color:var(--warning);font-weight:600;">Amber (40–79)</span> — Moderate gaps; active remediation needed</li>
      <li><span style="color:var(--danger);font-weight:600;">Red (&lt;40 or negative)</span> — Significant gaps; prioritize immediately</li>
    </ul>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance Packages</strong> — SPRS only applies to NIST SP 800-171 or CMMC packages (detected by standard code)</li>
      <li><strong>POA&amp;M</strong> — each closed item that changes a control to Compliant increases the score</li>
      <li><strong>GRC Projects</strong> — track the overall remediation effort driving score improvement</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'raci'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-people-fill"></i> RACI Matrix</h3></div>
  <div class="card-body docs-body">
    <h4>RACI Roles Explained</h4>
    <table class="table">
      <tr><th>Role</th><th>Meaning</th><th>Rule of Thumb</th></tr>
      <tr><td><strong>R — Responsible</strong></td><td>Does the work to complete the task</td><td>At least one R per domain; can be multiple</td></tr>
      <tr><td><strong>A — Accountable</strong></td><td>Ultimately answerable; signs off</td><td>Exactly one A per domain</td></tr>
      <tr><td><strong>C — Consulted</strong></td><td>Provides input before/during; two-way communication</td><td>Used for subject matter experts</td></tr>
      <tr><td><strong>I — Informed</strong></td><td>Kept up-to-date on progress; one-way communication</td><td>Management stakeholders</td></tr>
    </table>

    <h4>Editing the Matrix</h4>
    <ol>
      <li>Go to <strong>Compliance → RACI Matrix</strong></li>
      <li>Select a compliance package</li>
      <li>The matrix shows compliance domains (rows) × users (columns)</li>
      <li>Check R, A, C, and/or I checkboxes for each user per domain</li>
      <li>Click <strong>Save RACI Matrix</strong></li>
    </ol>

    <h4>Shared Responsibility Matrix</h4>
    <p>The <strong>Shared Responsibility</strong> view (button on the RACI page) goes one level deeper — per individual control, you define whether the responsibility is:</p>
    <ul>
      <li><strong>Customer</strong> — your organization owns this control entirely</li>
      <li><strong>Provider / Vendor</strong> — a cloud provider or vendor satisfies this control (e.g., AWS physical security)</li>
      <li><strong>Shared</strong> — both parties have responsibilities; document each party's scope</li>
    </ul>
    <p>This is essential for FedRAMP, CMMC, and cloud-hosted environments where a CSP (Cloud Service Provider) shares security responsibilities.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance Packages</strong> — RACI is defined per package; domains come from the package structure</li>
      <li><strong>SSP</strong> — the Responsible Roles field in SSP implementation statements should match your RACI assignments</li>
      <li><strong>Users</strong> — only active AEGIS users appear in the RACI matrix columns</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'audit_findings'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-journal-x"></i> External Audit Findings</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The <strong>Audit Findings</strong> module tracks findings from <em>external</em> assessors — third-party auditors, certification bodies (ISO, SOC 2), penetration testers, and regulatory inspectors. This is separate from internal audits, which are tracked in the <strong>Audits</strong> module.</p>

    <h4>Creating a Finding</h4>
    <ol>
      <li>Go to <strong>Compliance → Audit Findings → New Finding</strong></li>
      <li>Enter the finding title and description</li>
      <li>Select severity (Critical / High / Medium / Low / Info)</li>
      <li>Select source (External Audit / Penetration Test / Certification / Assessment / Regulatory)</li>
      <li>Enter the audit name (e.g., "ISO 27001 Certification Audit 2025") and auditor/firm name</li>
      <li>Assign an owner (the person responsible for remediation)</li>
      <li>Set a remediation deadline</li>
      <li>Optionally link to a compliance package and specific control</li>
    </ol>

    <h4>Tracking Remediation</h4>
    <p>Open a finding to:</p>
    <ul>
      <li>Write <strong>response notes</strong> documenting your remediation plan</li>
      <li>Update <strong>status</strong>: Open → In Progress → Resolved → Risk Accepted → Closed</li>
      <li>Set or change the <strong>deadline</strong></li>
      <li>Add <strong>timestamped updates</strong> with notes as progress is made</li>
    </ul>

    <h4>Overdue Alerts</h4>
    <p>Findings with a deadline in the past that are not Closed or Resolved are highlighted in red throughout the module. The index page shows an Overdue count card.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance Packages</strong> — findings can be linked to a specific package and control</li>
      <li><strong>POA&amp;M</strong> — major findings often become formal POA&amp;M items (especially for CMMC/NIST)</li>
      <li><strong>Issues</strong> — create granular issues for day-to-day remediation tasks</li>
      <li><strong>GRC Projects</strong> — findings that require multi-team effort can be linked to a project</li>
      <li><strong>Automation</strong> — although not yet a trigger type, findings update compliance posture which affects SPRS score</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'risk'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-exclamation-triangle-fill"></i> Risk Management</h3></div>
  <div class="card-body docs-body">
    <h4>Creating a Risk</h4>
    <ol>
      <li>Go to <strong>Risk → Risk Register → New Risk</strong></li>
      <li>Enter title, description, risk category, and threat source</li>
      <li>Set <strong>Likelihood</strong> (1–5) and <strong>Impact</strong> (1–5)</li>
      <li>The <strong>Inherent Score</strong> = Likelihood × Impact (max 25)</li>
      <li>Assign an owner and set a review date</li>
      <li>Select a treatment type</li>
    </ol>

    <h4>Risk Scoring</h4>
    <table class="table">
      <tr><th>Score</th><th>Level</th><th>Recommended Action</th></tr>
      <tr><td>1–4</td><td><span style="color:var(--success)">Low</span></td><td>Accept or monitor; low priority for treatment</td></tr>
      <tr><td>5–9</td><td><span style="color:var(--warning)">Medium</span></td><td>Mitigate within 90 days; assign treatment plan</td></tr>
      <tr><td>10–14</td><td><span style="color:var(--danger)">High</span></td><td>Mitigate within 30 days; escalate to management</td></tr>
      <tr><td>15–25</td><td><span style="color:var(--danger);font-weight:700">Critical</span></td><td>Immediate action; executive notification required</td></tr>
    </table>

    <h4>Treatment Types</h4>
    <ul>
      <li><strong>Mitigate</strong> — Implement controls to reduce likelihood or impact. Create a Treatment Plan with milestones.</li>
      <li><strong>Transfer</strong> — Transfer risk via insurance, contract, or outsourcing. Document the transfer mechanism.</li>
      <li><strong>Accept</strong> — Formally accept residual risk. Triggers an exception/waiver workflow requiring approval.</li>
      <li><strong>Avoid</strong> — Eliminate the activity that creates the risk (e.g., stop using a risky vendor).</li>
    </ul>

    <h4>Risk Reviews</h4>
    <p>Periodic risk reviews are tracked in <strong>Risk → Risk Reviews</strong>. Schedule reviews at a frequency matching your policy (quarterly for high risks, annually for low). Reviews update the risk score based on current environment.</p>

    <h4>Risk Exceptions</h4>
    <p>When treatment = Accept, submit a formal exception via <strong>Risk → [Risk] → Request Exception</strong>. Exceptions:</p>
    <ul>
      <li>Require a business rationale and approver</li>
      <li>Can have an expiry date (expired exceptions are auto-flagged)</li>
      <li>Are logged in the audit trail for compliance evidence</li>
    </ul>

    <h4>Bow-Tie Analysis</h4>
    <p>For critical risks, use <strong>Risk → [Risk] → Bow-Tie</strong> to map causes (left side) and consequences (right side) with barriers/controls in between. Useful for board-level reporting.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance</strong> — risks can be linked to specific controls; non-compliant controls increase risk likelihood</li>
      <li><strong>Issues</strong> — risks generate issues for operational teams to close specific gaps</li>
      <li><strong>Incidents</strong> — materialized risks are linked to the incident that triggered them</li>
      <li><strong>Assets</strong> — affected assets link to risks to quantify impact</li>
      <li><strong>Threats</strong> — threat catalog entries map to risk scenarios</li>
      <li><strong>KRI</strong> — key risk indicators monitor leading indicators for each risk</li>
      <li><strong>Automation</strong> — Risk Score High trigger (≥15) fires automatic actions</li>
      <li><strong>GRC Projects</strong> — large mitigation efforts tracked as projects with budget</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'audit'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-clipboard2-check-fill"></i> Internal Audits</h3></div>
  <div class="card-body docs-body">
    <h4>Audit Lifecycle</h4>
    <ol>
      <li><strong>Planned</strong> — Scheduled with a scope and assigned auditor</li>
      <li><strong>In Progress</strong> — Auditor is assessing controls</li>
      <li><strong>Completed</strong> — All items assessed; score calculated; evidence package ready</li>
    </ol>

    <h4>Creating an Audit</h4>
    <ol>
      <li>Go to <strong>Audits → New Audit</strong></li>
      <li>Enter a name, assign the lead auditor, set start/end dates</li>
      <li>Select a compliance package to use as the scope</li>
      <li>All controls from the package are imported as audit items</li>
    </ol>

    <h4>Assessing Controls</h4>
    <p>On the audit detail page:</p>
    <ul>
      <li>Expand each domain to see individual controls</li>
      <li>For each control: set pass/fail/partial, enter findings text, upload evidence, note risk level</li>
      <li>Progress bar tracks overall assessment completion</li>
    </ul>

    <h4>Evidence Management</h4>
    <p>Upload evidence files directly to controls or to the audit overall. Supported: PDF, Word, Excel, PNG, JPEG, CSV, ZIP (max 20 MB). Evidence is stored and linked to the specific control it supports.</p>

    <h4>Evidence Package Export</h4>
    <p>Click <strong>Export Package</strong> to download a ZIP containing:</p>
    <ul>
      <li>All evidence files organized by control code</li>
      <li><code>findings.csv</code> — complete assessment results</li>
      <li><code>README.txt</code> — audit summary with score and metadata</li>
    </ul>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance Packages</strong> — audit scope is drawn from a package; audit results can update control statuses</li>
      <li><strong>Audit Findings</strong> — external auditor findings from the same engagement are logged separately</li>
      <li><strong>Evidence</strong> — evidence uploaded to an audit is also available in the compliance package</li>
      <li><strong>Issues</strong> — failed audit items can generate issues for remediation teams</li>
      <li><strong>Approvals</strong> — audit reports may require management sign-off via the Approval workflow</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'policy'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-file-earmark-text-fill"></i> Policies</h3></div>
  <div class="card-body docs-body">
    <h4>Policy Lifecycle</h4>
    <ol>
      <li><strong>Draft</strong> — Being written; not visible to non-admin users</li>
      <li><strong>Under Review</strong> — Submitted for stakeholder review and approval</li>
      <li><strong>Published</strong> — Active policy; attestation campaigns can be launched</li>
      <li><strong>Archived</strong> — Superseded by a newer version; retained for audit trail</li>
    </ol>

    <h4>Creating a Policy</h4>
    <ol>
      <li>Go to <strong>Policies → New Policy</strong></li>
      <li>Enter title, category, and version number</li>
      <li>Assign a policy owner and set a review frequency (e.g., Annual)</li>
      <li>Upload the policy document (PDF or Word) or use the built-in editor</li>
      <li>Link to compliance controls the policy satisfies</li>
    </ol>

    <h4>Attestation Campaigns</h4>
    <p>After publishing a policy, launch an attestation campaign to collect employee acknowledgements:</p>
    <ol>
      <li>Open the policy → <strong>Attestations → New Campaign</strong></li>
      <li>Select target users (all users, specific roles, or a custom list)</li>
      <li>Set a deadline</li>
      <li>Users receive an email with a link to read and attest to the policy</li>
      <li>Track completion % in the campaign dashboard</li>
    </ol>

    <h4>Overdue Reviews</h4>
    <p>Policies past their <code>next_review_date</code> are flagged on the Policy index page and contribute to the <em>Overdue Policies</em> metric in Custom Dashboards. The <strong>Policy Review Due</strong> automation trigger can send automatic alerts.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance</strong> — policies are mapped to controls as evidence of implementation</li>
      <li><strong>Documents</strong> — policy files are stored in the Document management system</li>
      <li><strong>Awareness Training</strong> — policy content can form the basis of training modules</li>
      <li><strong>Automation</strong> — Policy Review Due trigger → auto-notify owner</li>
      <li><strong>Approvals</strong> — policy publication can require formal manager approval</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'incident'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-fire"></i> Incident Response</h3></div>
  <div class="card-body docs-body">
    <h4>Incident Lifecycle</h4>
    <ol>
      <li><strong>New</strong> — Reported, not yet assigned</li>
      <li><strong>Open</strong> — Assigned and under investigation</li>
      <li><strong>In Progress</strong> — Active response underway</li>
      <li><strong>Contained</strong> — Threat neutralized; monitoring for recurrence</li>
      <li><strong>Resolved</strong> — Root cause addressed; incident closed</li>
      <li><strong>Closed</strong> — Post-incident review completed</li>
    </ol>

    <h4>Logging an Incident</h4>
    <ol>
      <li>Go to <strong>Incidents → New Incident</strong></li>
      <li>Enter a descriptive title and select incident type and severity</li>
      <li>Set the detected date/time (preserves the SLA clock start)</li>
      <li>Assign a lead responder</li>
      <li>Link affected assets if known</li>
    </ol>

    <h4>SLA Tracking</h4>
    <p>Incident SLAs are configured in <strong>Admin → SLA Policy</strong> per severity level. The SLA clock starts from <code>detected_at</code>. The <strong>Incident SLA</strong> report shows all breached and at-risk incidents.</p>

    <h4>Playbooks</h4>
    <p>Playbooks provide step-by-step response runbooks. Attach a playbook when creating an incident or from the incident detail page. Each step can be checked off as completed, with timestamps recorded.</p>

    <h4>Updates Timeline</h4>
    <p>Add timestamped updates to document response actions. Updates are logged with the author's name and are part of the audit record for compliance evidence.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risks</strong> — materialized risks are linked to incidents; incidents can trigger new risk entries</li>
      <li><strong>Issues</strong> — remediation tasks spawned from the incident</li>
      <li><strong>Change Requests</strong> — emergency changes (e.g., emergency patching) raised during response</li>
      <li><strong>Playbooks</strong> — structured runbooks guide the response team step by step</li>
      <li><strong>Alerts</strong> — threshold breaches on KRIs or assets can trigger incident creation</li>
      <li><strong>Automation</strong> — Incident Created trigger → auto-notify via webhook or email</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'issues'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-bug-fill"></i> Issues</h3></div>
  <div class="card-body docs-body">
    <h4>What Are Issues?</h4>
    <p>Issues are operational remediation tasks — concrete, actionable items that need to be completed to address a gap, risk, finding, or incident. They differ from risks (which represent uncertainty) and are more granular than GRC Projects.</p>

    <h4>Issue Sources</h4>
    <ul>
      <li><strong>Automation Rules</strong> — auto-created when a trigger fires (e.g., risk score ≥ 15)</li>
      <li><strong>Audit Findings</strong> — created manually to track remediation of a finding</li>
      <li><strong>Incidents</strong> — spawned to address specific gaps exposed by an incident</li>
      <li><strong>Manual</strong> — created directly by any user with write access</li>
    </ul>

    <h4>Issue Workflow</h4>
    <ol>
      <li><strong>Open</strong> — Logged; awaiting assignment</li>
      <li><strong>In Progress</strong> — Assigned and actively being worked</li>
      <li><strong>Pending Review</strong> — Fix implemented; awaiting verification</li>
      <li><strong>Resolved</strong> — Verified fixed; issue closed</li>
    </ol>

    <h4>Severity Levels</h4>
    <table class="table">
      <tr><th>Severity</th><th>Expected Resolution</th></tr>
      <tr><td><span class="badge badge-danger">Critical</span></td><td>24 hours</td></tr>
      <tr><td><span class="badge badge-danger">High</span></td><td>72 hours</td></tr>
      <tr><td><span class="badge badge-warning">Medium</span></td><td>2 weeks</td></tr>
      <tr><td><span class="badge badge-info">Low</span></td><td>30 days</td></tr>
    </table>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Automation Rules</strong> — issues are the primary output of automation triggers</li>
      <li><strong>Risks</strong> — issues address specific risk treatment actions</li>
      <li><strong>Audit Findings</strong> — issues track day-to-day work resolving a finding</li>
      <li><strong>GRC Projects</strong> — issues can be linked to a project</li>
      <li><strong>Incidents</strong> — issues capture post-incident remediation tasks</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'change'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-arrow-repeat"></i> Change Management</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Change Management module tracks proposed and implemented changes to IT systems, infrastructure, and processes. It provides an audit trail required by ISO 27001 (A.12.1.2), SOC 2, and ITIL frameworks.</p>

    <h4>Change Types</h4>
    <ul>
      <li><strong>Standard</strong> — Pre-approved, low-risk, routine change (e.g., routine patch)</li>
      <li><strong>Normal</strong> — Requires CAB review; planned in advance</li>
      <li><strong>Emergency</strong> — Urgent; may bypass normal approval; requires post-hoc review</li>
    </ul>

    <h4>Change Workflow</h4>
    <ol>
      <li>Submit change request with description, impact assessment, and rollback plan</li>
      <li>Change Advisory Board (CAB) reviews and approves/rejects</li>
      <li>Approved change is implemented in the scheduled window</li>
      <li>Post-implementation review records outcome</li>
    </ol>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Incidents</strong> — emergency changes are often raised during incident response</li>
      <li><strong>Risks</strong> — failed changes can create risks; changes mitigating risks are linked</li>
      <li><strong>Approvals</strong> — normal changes require formal approval via the Approval workflow</li>
      <li><strong>Compliance</strong> — change management processes satisfy change control requirements in ISO 27001, SOC 2, PCI-DSS</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'bcp'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-shield-fill-exclamation"></i> Business Continuity Planning (BCP / DR)</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The BCP/DR module manages Business Continuity Plans and Disaster Recovery plans. It tracks critical business functions, recovery time objectives (RTO), recovery point objectives (RPO), and test exercises.</p>

    <h4>Creating a BCP</h4>
    <ol>
      <li>Go to <strong>BCP / DR → New Plan</strong></li>
      <li>Identify critical business functions and their dependencies</li>
      <li>Set RTO (how quickly the function must be restored) and RPO (maximum acceptable data loss window)</li>
      <li>Document recovery procedures and responsible teams</li>
      <li>Schedule regular DR exercises</li>
    </ol>

    <h4>DR Exercises</h4>
    <p>Record exercise results including test date, test type (tabletop, simulation, full failover), outcome, and lessons learned. Exercise history provides compliance evidence for ISO 22301 and ISO 27001 A.17.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risks</strong> — BCP scenarios address risks with availability impact; RTO/RPO drives residual risk scoring</li>
      <li><strong>Assets</strong> — critical assets are scoped into BCP plans</li>
      <li><strong>Incidents</strong> — major incidents may invoke the BCP; the incident record links to the BCP activated</li>
      <li><strong>Compliance</strong> — BCP satisfies ISO 27001 Annex A.17, SOC 2 Availability criteria, and HIPAA contingency planning</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'vendor'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-building"></i> Vendor Risk Management</h3></div>
  <div class="card-body docs-body">
    <h4>Vendor Tiers</h4>
    <table class="table">
      <tr><th>Tier</th><th>Description</th><th>Review Frequency</th></tr>
      <tr><td>Tier 1 — Critical</td><td>Access to sensitive data or critical systems; single point of failure</td><td>Annual full assessment</td></tr>
      <tr><td>Tier 2 — High</td><td>Significant data access or operational dependency</td><td>Annual questionnaire</td></tr>
      <tr><td>Tier 3 — Medium</td><td>Some data access; limited operational impact</td><td>Biennial</td></tr>
      <tr><td>Tier 4 — Low</td><td>No sensitive data access; easily replaceable</td><td>Every 3 years or on change</td></tr>
    </table>

    <h4>Vendor Assessments</h4>
    <ol>
      <li>Go to <strong>Vendor Risk → [Vendor] → New Assessment</strong></li>
      <li>Rate the vendor across security domains (Access Control, Data Protection, Incident Response, etc.)</li>
      <li>The assessment score updates the vendor's overall risk rating</li>
      <li>Alternatively, send a <strong>Questionnaire</strong> for the vendor to self-assess via the secure portal</li>
    </ol>

    <h4>Contract Tracking</h4>
    <p>Contracts are tracked under each vendor with: start date, end date, value, and type. Contracts expiring within 30 days trigger the <strong>Vendor Contract Expiring</strong> automation trigger.</p>

    <h4>Vendor Portal</h4>
    <p>Generate a secure, time-limited portal link (<strong>Vendor → [Vendor] → Generate Portal Link</strong>) to allow external vendors to submit evidence or respond to questionnaires without needing an AEGIS account.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risks</strong> — vendor assessments feed risk scores; high-risk vendors create risk register entries</li>
      <li><strong>Issues</strong> — assessment gaps become issues for the vendor owner to resolve</li>
      <li><strong>Questionnaires</strong> — sent to vendors for self-assessment input</li>
      <li><strong>Automation</strong> — Vendor Contract Expiring trigger → auto-create issue or send email 30 days before expiry</li>
      <li><strong>Compliance</strong> — vendor security satisfies supply chain controls in ISO 27001 A.15, SOC 2, and CMMC</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'projects'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-briefcase-fill"></i> GRC Projects</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>GRC Projects track large-scale remediation and compliance initiatives that span multiple teams and weeks or months of work. Use projects when you need budget tracking, progress reporting, and team task management in addition to the issue-level tracking.</p>

    <h4>Creating a Project</h4>
    <ol>
      <li>Go to <strong>Operations → GRC Projects → New Project</strong></li>
      <li>Enter project title, description, priority, and status</li>
      <li>Assign a Project Lead</li>
      <li>Set start/end dates and planned budget</li>
    </ol>

    <h4>Managing Tasks</h4>
    <p>Within a project, add tasks with:</p>
    <ul>
      <li>Title and description</li>
      <li>Assignee (selected from active users)</li>
      <li>Due date</li>
    </ul>
    <p>Mark tasks as done — the project progress bar reflects % completion. Overdue tasks are highlighted in red.</p>

    <h4>Budget Tracking</h4>
    <p>Set a <strong>Planned Budget</strong> at project creation. As you spend, update the <strong>Actual Budget</strong>. When actual exceeds planned, a warning indicator appears. Budget figures appear on the project index for portfolio-level visibility.</p>

    <h4>Linking Items</h4>
    <p>Link a project to any combination of risks, controls (by ID), issues, and audit findings. This creates traceability from the project back to the specific GRC items being addressed.</p>

    <h4>Project Statuses</h4>
    <table class="table">
      <tr><th>Status</th><th>Meaning</th></tr>
      <tr><td><span class="badge badge-info">Planning</span></td><td>Scoping and scheduling underway</td></tr>
      <tr><td><span class="badge badge-success">Active</span></td><td>Work in progress</td></tr>
      <tr><td><span class="badge badge-warning">On Hold</span></td><td>Paused; awaiting resource or decision</td></tr>
      <tr><td><span class="badge badge-secondary">Completed</span></td><td>All tasks done; outcomes verified</td></tr>
      <tr><td><span class="badge badge-danger">Cancelled</span></td><td>Discontinued; document reason</td></tr>
    </table>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risks</strong> — projects address risk treatment plans</li>
      <li><strong>POA&amp;M</strong> — large POA&amp;M remediation bodies of work become projects</li>
      <li><strong>Audit Findings</strong> — projects track the remediation of major findings</li>
      <li><strong>Issues</strong> — granular day-to-day tasks for a project are tracked as issues</li>
      <li><strong>SSP</strong> — completing SSP authoring is itself a project</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'automation'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-lightning-fill"></i> Automation Rules</h3></div>
  <div class="card-body docs-body">
    <h4>How Automation Works</h4>
    <p>An automation rule consists of a <strong>trigger</strong> (when something happens) and an <strong>action</strong> (what to do automatically). Rules run when the cron job (<code>run_workflows.php</code>) executes, or when the triggering event occurs.</p>

    <h4>Available Triggers</h4>
    <table class="table">
      <tr><th>Trigger</th><th>Fires When</th></tr>
      <tr><td>Risk Score High</td><td>A risk's score meets or exceeds the configured threshold (default: 15)</td></tr>
      <tr><td>Control Non-Compliant</td><td>A control's status changes to Non-Compliant</td></tr>
      <tr><td>Audit Overdue</td><td>An audit's end date passes without completion</td></tr>
      <tr><td>Incident Created</td><td>A new incident is logged</td></tr>
      <tr><td>Policy Review Due</td><td>A policy's next review date has passed</td></tr>
      <tr><td>Vendor Contract Expiring</td><td>A vendor contract expires within N days (configurable)</td></tr>
      <tr><td>Scheduled — Daily</td><td>Every day (cron job)</td></tr>
      <tr><td>Scheduled — Weekly</td><td>Every week (cron job)</td></tr>
    </table>

    <h4>Available Actions</h4>
    <table class="table">
      <tr><th>Action</th><th>What It Does</th></tr>
      <tr><td>Create Issue</td><td>Auto-creates an issue with a configurable title template and severity</td></tr>
      <tr><td>Send Webhook</td><td>POSTs a JSON payload to the configured URL (Slack, Teams, PagerDuty, SIEM, etc.)</td></tr>
      <tr><td>Send Email Notification</td><td>Sends an email to specified recipients with a configurable subject template</td></tr>
      <tr><td>Assign User</td><td>Assigns the triggering entity to the specified user</td></tr>
    </table>

    <h4>Creating a Rule</h4>
    <ol>
      <li>Go to <strong>GRC Tools → Automation Rules → New Rule</strong></li>
      <li>Name the rule and optionally add a description</li>
      <li>Select the trigger event and configure any parameters (e.g., risk threshold score)</li>
      <li>Select the action and configure its parameters (e.g., issue title template, webhook URL)</li>
      <li>Enable the rule immediately with the <strong>Active</strong> checkbox</li>
    </ol>

    <h4>Dry-Run Testing</h4>
    <p>On any rule's detail page, click <strong>Run Test</strong> to simulate the trigger without executing any actions. The test result shows which items would be affected — useful for verifying a rule before enabling it in production.</p>

    <h4>Template Variables</h4>
    <p>Use <code>{entity}</code> and <code>{trigger}</code> placeholders in issue title and email subject templates. Example: <code>High-risk item detected: {entity}</code> becomes <em>"High-risk item detected: SQL Injection Risk"</em>.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risks</strong> — Risk Score High is the most common trigger</li>
      <li><strong>Compliance</strong> — Control Non-Compliant fires when testing updates a control</li>
      <li><strong>Incidents</strong> — Incident Created trigger notifies on-call teams instantly</li>
      <li><strong>Issues</strong> — Create Issue is the most common action; auto-generated issues are labeled with the triggering rule</li>
      <li><strong>Vendor</strong> — Contract Expiring trigger keeps procurement teams ahead of renewals</li>
      <li><strong>Webhooks</strong> — Send Webhook integrates with Slack, PagerDuty, Teams, SIEM, or any HTTP endpoint</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'dashboards'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-layout-wtf"></i> Custom Dashboards</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>Custom Dashboards let each user build a personalized view of their most important GRC metrics. Unlike the main dashboard (which is fixed), custom dashboards support any combination of widgets and can be shared with all users.</p>

    <h4>Creating a Dashboard</h4>
    <ol>
      <li>Go to <strong>Analytics → Custom Dashboards → New Dashboard</strong></li>
      <li>Enter a name and optional description</li>
      <li>Check <strong>Share with all users</strong> to make it visible to everyone (useful for team/executive dashboards)</li>
    </ol>

    <h4>Available Widgets</h4>
    <table class="table">
      <tr><th>Widget</th><th>What It Shows</th></tr>
      <tr><td>Stat Card</td><td>A single large metric number (choose from: Open Risks, Non-Compliant Controls, Open Incidents, Open Issues, Overdue Policies, Pending Approvals)</td></tr>
      <tr><td>Recent High Risks</td><td>Top 5 open risks by score with link-through to each risk</td></tr>
      <tr><td>Recent Incidents</td><td>Last 5 incidents with severity badges</td></tr>
      <tr><td>Compliance Summary</td><td>Per-package progress bar showing % compliant controls</td></tr>
      <tr><td>Open Issues</td><td>Last 5 open issues with severity badges</td></tr>
    </table>

    <h4>Managing Widgets</h4>
    <p>Only the dashboard owner can add or remove widgets. Shared dashboards can be viewed by all users but edited only by the creator. Click the ✕ on a widget to remove it.</p>

    <h4>Example Dashboard Configurations</h4>
    <ul>
      <li><strong>CISO Executive View</strong>: 4 Stat Cards (open risks, incidents, non-compliant controls, pending approvals) + Compliance Summary</li>
      <li><strong>Risk Analyst View</strong>: Recent High Risks + Open Issues + Stat Card (open risks)</li>
      <li><strong>Compliance Team View</strong>: Compliance Summary (all packages) + Stat Card (non-compliant controls)</li>
    </ul>

    <h4>Interconnections</h4>
    <p>Dashboards pull live data from Risks, Incidents, Compliance Packages, Issues, Policies, and Approvals. All data is real-time — no caching.</p>
  </div>
</div>

<?php elseif ($section === 'cui'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-lock-fill"></i> CUI Inventory</h3></div>
  <div class="card-body docs-body">
    <h4>What Is CUI?</h4>
    <p><strong>Controlled Unclassified Information (CUI)</strong> is information the U.S. Government creates or possesses that requires safeguarding per law, regulation, or policy. Examples include: export-controlled technical data (ITAR/EAR), personal health information, privacy data (PII), law enforcement sensitive, and defense procurement information. Organizations with DoD contracts that handle CUI must comply with NIST SP 800-171 and CMMC.</p>

    <h4>Why Inventory CUI?</h4>
    <ul>
      <li>CMMC requires you to identify where all CUI lives in your environment</li>
      <li>The CUI boundary defines the scope of your NIST SP 800-171 assessment</li>
      <li>C3PAOs and DIBCACs will ask for your CUI inventory during assessments</li>
      <li>Knowing where CUI flows helps you apply the right controls (encryption, access control, audit logging)</li>
    </ul>

    <h4>Adding a CUI Item</h4>
    <ol>
      <li>Go to <strong>GRC Tools → CUI Inventory → New CUI Item</strong></li>
      <li>Enter a name (e.g., "Engineering Drawings — Widget Program") and description</li>
      <li>Select the CUI category (Technical Data, PII, PHI, Export-Controlled, Law Enforcement, etc.) or type a custom category</li>
      <li>Specify storage location (e.g., "SharePoint site: ITAR-Engineering", "AWS S3 bucket: prod-cui-store")</li>
      <li>Select storage type (Cloud / On-Premise / Hybrid / Portable Media)</li>
      <li>Mark whether data is encrypted at rest</li>
      <li>Describe the access controls in place</li>
    </ol>

    <h4>CUI Boundary</h4>
    <p>The collection of all CUI items in your inventory defines your <strong>CUI boundary</strong> — the systems and locations in scope for NIST SP 800-171. Include this inventory as an appendix to your SSP.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>SSP</strong> — CUI inventory is referenced in the SSP system boundary section</li>
      <li><strong>Compliance</strong> — CUI scope determines which systems are in scope for NIST/CMMC controls</li>
      <li><strong>Assets</strong> — CUI storage systems should also be in the Asset Inventory with proper classification</li>
      <li><strong>SPRS Score</strong> — adequately protecting CUI (encryption, access controls) is required for compliance</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'kri'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-activity"></i> KRI Dashboard — Key Risk Indicators</h3></div>
  <div class="card-body docs-body">
    <h4>What Are KRIs?</h4>
    <p>Key Risk Indicators (KRIs) are quantitative metrics that provide early warning signals for rising risk levels. Unlike KPIs (which measure past performance), KRIs are leading indicators — they signal that a risk <em>may</em> materialize if the trend continues.</p>

    <h4>Examples</h4>
    <ul>
      <li>Number of failed login attempts per day</li>
      <li>% of critical patches not applied within SLA</li>
      <li>Number of open critical vulnerabilities older than 30 days</li>
      <li>% of employees with overdue security training</li>
      <li>Number of active vendors without a current assessment</li>
    </ul>

    <h4>Creating a KRI</h4>
    <ol>
      <li>Go to <strong>Risk → KRI Dashboard → New KRI</strong></li>
      <li>Name the indicator and link it to a risk (optional)</li>
      <li>Set threshold values: <strong>Green</strong> (safe), <strong>Amber</strong> (warning), <strong>Red</strong> (breach)</li>
      <li>Set the unit of measure (count, %, days, etc.)</li>
      <li>Set reporting frequency (daily, weekly, monthly)</li>
    </ol>

    <h4>Recording Values</h4>
    <p>On each reporting cycle, go to the KRI detail page and enter the current value. The system plots the trend over time and triggers alerts when thresholds are crossed.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risks</strong> — each KRI is optionally linked to a risk it monitors; threshold breach flags the risk</li>
      <li><strong>Alerts</strong> — KRI threshold breaches generate system alerts in the notification center</li>
      <li><strong>Incidents</strong> — a KRI crossing red may indicate an incident is occurring</li>
      <li><strong>Custom Dashboards</strong> — add a stat card widget to surface KRI health</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'privacy'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-shield-lock-fill"></i> Data Privacy</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Data Privacy module tracks personal data processing activities and handles Data Subject Requests (DSRs) — required under GDPR, CCPA, HIPAA, and other privacy regulations.</p>

    <h4>Record of Processing Activities (RoPA)</h4>
    <p>Create a privacy record for each data processing activity (e.g., "Customer Marketing Emails", "Employee HR Records", "Support Ticket System"). Each record captures:</p>
    <ul>
      <li>Categories of personal data processed</li>
      <li>Purpose of processing and legal basis</li>
      <li>Data subjects (employees, customers, prospects)</li>
      <li>Retention period</li>
      <li>Third-party processors (vendors) who receive the data</li>
    </ul>

    <h4>Data Subject Requests</h4>
    <p>Log DSRs from individuals who exercise their privacy rights (right to access, right to erasure, right to rectification). Track status, response deadline, and completion. GDPR requires response within 30 days; CCPA within 45 days.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Vendor Risk</strong> — vendors who process personal data are assessed as Tier 1 or 2</li>
      <li><strong>Compliance</strong> — privacy records satisfy ISO 27001 A.18.1, SOC 2 Privacy criteria, GDPR Article 30</li>
      <li><strong>Incidents</strong> — personal data breaches trigger incident response and may require DPA notification</li>
      <li><strong>Policies</strong> — Privacy Policy and Data Retention Policy link to compliance controls</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'awareness'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-mortarboard-fill"></i> Security Awareness Training</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Awareness Training module tracks security awareness and compliance training for all staff. Required by ISO 27001 A.6.3, SOC 2 CC1.4, HIPAA Security Rule §164.308(a)(5), and CMMC MK.2.061.</p>

    <h4>Creating a Training Module</h4>
    <ol>
      <li>Go to <strong>Operations → Awareness Training → New Training</strong></li>
      <li>Enter title, description, and training content (URL or embedded content)</li>
      <li>Set a completion deadline</li>
      <li>Select target audience (all users, specific roles, or a user list)</li>
      <li>Assign users and send notifications</li>
    </ol>

    <h4>Tracking Completion</h4>
    <p>The training detail page shows completion % and which users have not yet completed. Send reminders to incomplete users. Completion is recorded with timestamp for audit evidence.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Policies</strong> — training modules reinforce policy content; completion is evidence of policy awareness</li>
      <li><strong>Compliance</strong> — training completion records satisfy security awareness controls</li>
      <li><strong>Incidents</strong> — phishing simulations and post-incident training are logged here</li>
      <li><strong>KRI</strong> — % employees with overdue training is a key risk indicator</li>
    </ul>
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
      <li><code>X-API-Key: &lt;your-key&gt;</code> header — generate keys in <strong>Admin → API Keys</strong></li>
      <li><code>Authorization: Bearer &lt;jwt&gt;</code> — obtain JWT from <code>POST /api/v1/auth/token</code></li>
    </ul>

    <h4>Rate Limiting</h4>
    <p>API requests are rate-limited to <strong>60 per minute per IP</strong>. Exceeding this returns HTTP 429. API key requests are rate-limited to 1000/hour.</p>

    <h4>Key Endpoints</h4>
    <table class="table">
      <tr><th>Method</th><th>Path</th><th>Description</th></tr>
      <tr><td>POST</td><td><code>/api/v1/auth/token</code></td><td>Get JWT token</td></tr>
      <tr><td>GET</td><td><code>/api/v1/risks</code></td><td>List risks</td></tr>
      <tr><td>POST</td><td><code>/api/v1/risks</code></td><td>Create risk</td></tr>
      <tr><td>GET</td><td><code>/api/v1/compliance/packages</code></td><td>List frameworks</td></tr>
      <tr><td>POST</td><td><code>/api/v1/ingest/tenable</code></td><td>Ingest Tenable findings</td></tr>
      <tr><td>POST</td><td><code>/api/v1/ingest/qualys</code></td><td>Ingest Qualys findings</td></tr>
      <tr><td>POST</td><td><code>/api/v1/ingest/wiz</code></td><td>Ingest Wiz findings</td></tr>
      <tr><td>POST</td><td><code>/api/v1/ingest/generic</code></td><td>Ingest generic scanner output</td></tr>
      <tr><td>GET</td><td><code>/api/v1/dashboard/stats</code></td><td>GRC summary stats</td></tr>
    </table>

    <h4>SIEM / Scanner Ingestion</h4>
    <pre><code>curl -X POST https://your-instance/api/v1/ingest/tenable \
  -H "X-API-Key: &lt;key&gt;" \
  -H "Content-Type: application/json" \
  -d '{"vulnerabilities": [{"pluginId": "19506", "severity": "high", ...}]}'</code></pre>
    <p>Findings are deduplicated by <code>source_external_id</code> within 30 days.</p>
  </div>
</div>

<?php elseif ($section === 'admin'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-gear-fill"></i> Administration</h3></div>
  <div class="card-body docs-body">
    <h4>User Roles</h4>
    <table class="table">
      <tr><th>Role</th><th>Capabilities</th></tr>
      <tr><td><strong>admin</strong></td><td>Full access to all modules and admin functions</td></tr>
      <tr><td><strong>manager</strong></td><td>Read/write all modules; approve requests; no admin settings access</td></tr>
      <tr><td><strong>auditor</strong></td><td>Read all modules; write audits and evidence only</td></tr>
      <tr><td><strong>analyst</strong></td><td>Read/write compliance, risk, incidents, issues</td></tr>
      <tr><td><strong>viewer</strong></td><td>Read-only access to assigned modules</td></tr>
    </table>

    <h4>Module Visibility</h4>
    <p>Go to <strong>Admin → Module Visibility</strong> to hide modules you don't use. Hidden modules disappear from the sidebar and their routes return 403. Useful for organizations that don't use BCP, Privacy, or other specific modules.</p>

    <h4>SSO / OIDC</h4>
    <p>Configure SSO at <strong>Admin → SSO / OIDC</strong>. Supported providers: Azure AD, Okta, Google Workspace, any OIDC-compliant IdP. Role mapping via ID token claims is supported.</p>

    <h4>Crontab Setup (Required for Automation)</h4>
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
    <p>Configure automatic data purge at <strong>Admin → Data Retention</strong>. Activity logs, notification logs, webhook deliveries, and alerts can be set to auto-delete after N days.</p>

    <h4>Storage</h4>
    <p>Configure file storage at <strong>Admin → Storage</strong>. Supports local disk (default) or any S3-compatible object store (AWS S3, MinIO, Cloudflare R2).</p>

    <h4>Security Policy</h4>
    <p>Set password complexity requirements, session timeout, MFA enforcement, and login attempt lockout at <strong>Admin → Security Policy</strong>.</p>
  </div>
</div>

<?php elseif ($section === 'integrations'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-plug-fill"></i> Integrations</h3></div>
  <div class="card-body docs-body">
    <h4>Outbound Webhooks</h4>
    <p>Configure at <strong>Admin → Webhooks</strong>. Destinations:</p>
    <ul>
      <li><strong>Slack</strong> — Block Kit formatted messages for incident and risk alerts</li>
      <li><strong>Microsoft Teams</strong> — Adaptive Card payloads</li>
      <li><strong>PagerDuty</strong> — Events API v2 with severity mapping</li>
      <li><strong>Jira</strong> — Auto-create Jira issues from AEGIS issues (ADF-formatted)</li>
      <li><strong>ServiceNow</strong> — Create incidents or change requests via REST API</li>
      <li><strong>Generic HTTP</strong> — JSON POST with HMAC-SHA256 signature verification</li>
    </ul>
    <p>Webhook payloads are signed with <code>X-AEGIS-Signature: sha256=&lt;hmac&gt;</code>. Verify against your configured secret to authenticate the payload.</p>

    <h4>SIEM / Scanner Ingestion</h4>
    <p>Push vulnerability findings from security tools directly into the risk register via the API:</p>
    <pre><code>curl -X POST https://your-instance/api/v1/ingest/tenable \
  -H "X-API-Key: &lt;key&gt;" \
  -d '{"vulnerabilities": [...]}'</code></pre>
    <p>Supported: Tenable, Qualys, Wiz, and generic JSON scanner output.</p>

    <h4>Email (SMTP)</h4>
    <p>Configure SMTP at <strong>Admin → Email Settings</strong>. Supports TLS/STARTTLS. Used for: notifications, approval reminders, scheduled reports, policy attestation campaigns.</p>

    <h4>AI Advisor</h4>
    <p>Configure at <strong>Admin → System Settings</strong>:</p>
    <table class="table">
      <tr><th>Provider</th><th>Setting</th></tr>
      <tr><td>Claude (Anthropic)</td><td><code>ai_provider=claude</code>, <code>ai_api_key=sk-ant-...</code></td></tr>
      <tr><td>OpenAI</td><td><code>ai_provider=openai</code>, <code>ai_api_key=sk-...</code></td></tr>
    </table>
    <p>Used for AI-generated control gap suggestions in Compliance packages.</p>

    <h4>LDAP / Active Directory</h4>
    <p>LDAP sync is configured in <strong>Admin → Settings</strong>. Once configured, AEGIS syncs user accounts from your AD/LDAP directory. Users log in with their domain credentials; roles are mapped from LDAP groups.</p>

    <h4>Automation Rule Webhooks</h4>
    <p>The <strong>Send Webhook</strong> automation action fires immediately when a trigger is met (not on cron). Use it to push real-time alerts to your SIEM, ITSM, or communication platform.</p>
  </div>
</div>

<?php elseif ($section === 'workflows'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-person-lines-fill"></i> Role-Based Workflows</h3></div>
  <div class="card-body docs-body">
    <h4>CISO / Security Director — Weekly Workflow</h4>
    <ol>
      <li><strong>KRI Dashboard</strong> — Review all KRI thresholds. Are any in red? Investigate immediately.</li>
      <li><strong>Custom Dashboards</strong> — Open your executive dashboard: check open critical risks, open incidents, non-compliant controls.</li>
      <li><strong>Risk Register</strong> — Review new risks created this week. Approve or reject exception requests.</li>
      <li><strong>SPRS Score</strong> — Check the NIST SP 800-171 score trend. Is it improving week over week?</li>
      <li><strong>POA&amp;M</strong> — Review overdue items. Escalate blockers to project owners.</li>
      <li><strong>Approval Workflows</strong> — Clear pending approvals (risk exceptions, policy publications, change requests).</li>
      <li><strong>Reports</strong> — Generate and review the weekly Board-level summary report before distribution.</li>
    </ol>

    <h4>Compliance Analyst — Daily Workflow</h4>
    <ol>
      <li><strong>Compliance Packages</strong> — Check the gap analysis view. Which domains have the most red controls?</li>
      <li><strong>Control Evidence</strong> — Upload new evidence files for controls recently moved to "Compliant".</li>
      <li><strong>POA&amp;M</strong> — Update milestone progress on open items. Set milestones to completed as work is done.</li>
      <li><strong>ODP Center</strong> — Verify all organization-defined parameters are filled in before an upcoming audit.</li>
      <li><strong>RACI Matrix</strong> — Confirm that new controls added to a package have RACI assignments.</li>
      <li><strong>SSP</strong> — Write or update implementation statements for recently completed controls.</li>
      <li><strong>Audit Findings</strong> — Check for findings approaching their remediation deadline.</li>
    </ol>

    <h4>IT Administrator — Workflow</h4>
    <ol>
      <li><strong>Asset Inventory</strong> — Log new hardware and software assets as they are provisioned. Set criticality and owner.</li>
      <li><strong>CUI Inventory</strong> — Verify CUI records for any new systems that handle sensitive data.</li>
      <li><strong>Issues</strong> — Review issues assigned to you. Update status and add progress notes.</li>
      <li><strong>Change Management</strong> — Submit change requests for planned infrastructure changes. Include rollback plan.</li>
      <li><strong>Automation Rules</strong> — Review automation rule execution logs. Are rules firing as expected?</li>
      <li><strong>Incidents</strong> — Check for open incidents assigned to IT. Update status and add timeline notes.</li>
    </ol>

    <h4>Internal Auditor — Audit Engagement Workflow</h4>
    <ol>
      <li><strong>Audits → New Audit</strong> — Create the audit engagement. Set scope (compliance package), date range, and assign the audit lead.</li>
      <li><strong>Control Assessment</strong> — Work through each domain systematically. For each control: test, record result (pass/fail/partial), and upload evidence.</li>
      <li><strong>Evidence Package</strong> — At completion, export the ZIP package: all evidence files + findings.csv + README.</li>
      <li><strong>Audit Findings</strong> — Log any formal findings discovered during the audit. Set severity and remediation deadline.</li>
      <li><strong>Issues</strong> — Create issues for controls that require remediation. Assign to the relevant team.</li>
      <li><strong>Approvals</strong> — Route the completed audit report through the approval workflow for management sign-off.</li>
      <li><strong>POA&amp;M</strong> — Generate POA&amp;M items from non-compliant controls found during the audit.</li>
    </ol>

    <h4>Risk Manager — Workflow</h4>
    <ol>
      <li><strong>Risk Register → New Risk</strong> — Capture newly identified risks. Set likelihood, impact, category, owner, and treatment type.</li>
      <li><strong>Threat Register</strong> — Map the risk to an existing threat actor or create a new threat record.</li>
      <li><strong>Treatment Plans</strong> — Create a treatment plan for each mitigate-type risk. Add milestones and assign sub-tasks.</li>
      <li><strong>KRI Dashboard</strong> — Configure KRIs for high-priority risks. Set thresholds that provide early warning.</li>
      <li><strong>Risk Reviews</strong> — Schedule and conduct quarterly reviews of open risks. Update scores based on treatment progress.</li>
      <li><strong>Reports</strong> — Generate the Risk Report for monthly distribution to the risk committee.</li>
    </ol>

    <h4>Security Operations — Incident Response Workflow</h4>
    <ol>
      <li><strong>Incidents → New Incident</strong> — Log the incident immediately. Set severity, category, and detected time (starts SLA clock).</li>
      <li><strong>Playbooks</strong> — Attach the appropriate playbook (e.g., "Ransomware Response", "Phishing Triage").</li>
      <li><strong>Playbook Execution</strong> — Work through each playbook step in order. Check off steps as completed.</li>
      <li><strong>Updates</strong> — Add timestamped updates every 30–60 minutes during active response. Document all actions taken.</li>
      <li><strong>Change Requests</strong> — Raise an emergency change request for any system changes made during containment.</li>
      <li><strong>Issues</strong> — Create issues for each remediation task that will outlast the incident (e.g., patch a vulnerable system).</li>
      <li><strong>Post-Incident Review</strong> — After closure, update the incident with root cause, lessons learned, and link affected risks.</li>
      <li><strong>Risk Register</strong> — If the incident revealed a new risk, create a risk record with the incident as context.</li>
    </ol>
  </div>
</div>

<?php elseif ($section === 'threats'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-crosshair"></i> Threat Register</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Threat Register is a catalog of known threat sources and scenarios that could affect your organization. It is distinct from the Risk Register: a <em>threat</em> is an agent or event that could exploit a vulnerability; a <em>risk</em> is the specific scenario of that threat materializing and the impact it would have. The Threat Register feeds the Risk Register by providing structured threat context.</p>

    <h4>Threat Actor Categories</h4>
    <table class="table">
      <tr><th>Category</th><th>Examples</th><th>Typical Motivation</th></tr>
      <tr><td>Nation-State / APT</td><td>APT29 (Cozy Bear), APT41, Lazarus Group</td><td>Espionage, IP theft, disruption</td></tr>
      <tr><td>Cybercriminal</td><td>Ransomware operators, financial fraud groups</td><td>Financial gain</td></tr>
      <tr><td>Insider Threat</td><td>Malicious employee, negligent user, compromised account</td><td>Grievance, financial, accidental</td></tr>
      <tr><td>Hacktivist</td><td>Anonymous affiliates, politically motivated actors</td><td>Ideology, notoriety</td></tr>
      <tr><td>Third-Party / Supply Chain</td><td>Compromised vendor, malicious software update</td><td>Indirect access to target</td></tr>
      <tr><td>Natural / Environmental</td><td>Flood, fire, earthquake, power failure</td><td>N/A — non-intentional</td></tr>
    </table>

    <h4>Adding a Threat</h4>
    <ol>
      <li>Go to <strong>Risk → Threat Register → New Threat</strong></li>
      <li>Enter the threat name (e.g., "Ransomware via Phishing Email")</li>
      <li>Select the threat category and threat actor type</li>
      <li>Optionally link to a MITRE ATT&amp;CK technique (e.g., T1566.001 — Spearphishing Attachment)</li>
      <li>Rate inherent likelihood (1–5) for your organization and industry</li>
      <li>Describe controls already in place that reduce this threat</li>
      <li>Save — the threat is now available to be linked from Risk Register entries</li>
    </ol>

    <h4>Field Definitions</h4>
    <table class="table">
      <tr><th>Field</th><th>Type</th><th>Description</th></tr>
      <tr><td>Name</td><td>Text (required)</td><td>Short label for the threat scenario</td></tr>
      <tr><td>Description</td><td>Text area</td><td>How this threat manifests; attack vector details</td></tr>
      <tr><td>Category</td><td>Dropdown</td><td>APT, Cybercriminal, Insider, Hacktivist, Supply Chain, Natural</td></tr>
      <tr><td>MITRE ATT&amp;CK ID</td><td>Text</td><td>Technique or sub-technique ID (e.g., T1078)</td></tr>
      <tr><td>Inherent Likelihood</td><td>1–5 scale</td><td>Probability this threat targets your org without controls</td></tr>
      <tr><td>Existing Controls</td><td>Text area</td><td>Controls already deployed that reduce this threat</td></tr>
      <tr><td>Intelligence Sources</td><td>Text</td><td>ISACs, threat feeds, or reports informing this entry</td></tr>
    </table>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risk Register</strong> — risks reference threat register entries as their threat source, pre-populating category and likelihood context</li>
      <li><strong>Incidents</strong> — post-incident, link the incident to the threat actor that caused it; update threat likelihood accordingly</li>
      <li><strong>Compliance</strong> — threat categories map to security domains (e.g., insider threat → access control controls)</li>
      <li><strong>BCP/DR</strong> — natural threat scenarios drive BCP recovery scenario planning</li>
    </ul>

    <h4>Best Practices</h4>
    <ul>
      <li>Review and update threat entries quarterly using current threat intelligence (CISA advisories, ISACs, vendor bulletins)</li>
      <li>Every high-priority risk should be linked to at least one threat register entry</li>
      <li>Use MITRE ATT&amp;CK IDs to align threat modeling with your detection engineering team</li>
      <li>After a security incident, update the relevant threat's likelihood and controls based on lessons learned</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'treatment'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-bandaid-fill"></i> Treatment Plans</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>A Treatment Plan is the formal, structured response to a risk. When a risk is assigned a treatment strategy of <strong>Mitigate</strong>, a treatment plan documents exactly how the risk will be reduced: what controls will be implemented, who owns each action, what it costs, and by when it will be done.</p>

    <h4>Treatment Strategies</h4>
    <table class="table">
      <tr><th>Strategy</th><th>When to Use</th><th>Required Documentation</th></tr>
      <tr><td><strong>Mitigate</strong></td><td>Risk is above appetite; controls can reduce it to acceptable level</td><td>Treatment plan with milestones, owner, cost, and target residual score</td></tr>
      <tr><td><strong>Transfer</strong></td><td>Risk can be shifted via insurance, contract, or outsourcing</td><td>Transfer mechanism (insurance policy reference, contract clause, SLA)</td></tr>
      <tr><td><strong>Accept</strong></td><td>Cost of mitigation exceeds potential impact; risk is within appetite</td><td>Formal exception with business justification, approver, and expiry date</td></tr>
      <tr><td><strong>Avoid</strong></td><td>Activity creating the risk can be eliminated entirely</td><td>Description of the avoided activity and confirmation it has ceased</td></tr>
    </table>

    <h4>Creating a Treatment Plan</h4>
    <ol>
      <li>Open a risk and set <strong>Treatment Type = Mitigate</strong></li>
      <li>Click <strong>Add Treatment Plan</strong></li>
      <li>Enter the treatment plan title and description of controls to be implemented</li>
      <li>Set the plan owner and target completion date</li>
      <li>Enter planned cost (budget) and assigned resources</li>
      <li>Set the <strong>target residual score</strong> — what the risk score should be after full implementation</li>
      <li>Add milestones: specific, verifiable steps (e.g., "Deploy MFA for all privileged accounts — due 2025-08-01")</li>
    </ol>

    <h4>Field Definitions</h4>
    <table class="table">
      <tr><th>Field</th><th>Type</th><th>Description</th></tr>
      <tr><td>Title</td><td>Text (required)</td><td>Brief name for this treatment plan</td></tr>
      <tr><td>Description</td><td>Text area</td><td>Detailed description of mitigating controls to be implemented</td></tr>
      <tr><td>Strategy</td><td>Dropdown</td><td>Mitigate / Transfer / Accept / Avoid</td></tr>
      <tr><td>Owner</td><td>User select</td><td>Person accountable for executing this plan</td></tr>
      <tr><td>Target Date</td><td>Date</td><td>When the treatment should be fully implemented</td></tr>
      <tr><td>Cost (Planned)</td><td>Decimal</td><td>Estimated cost of implementing the controls</td></tr>
      <tr><td>Target Residual Score</td><td>Integer 1–25</td><td>Expected risk score after all controls are deployed</td></tr>
      <tr><td>Status</td><td>Dropdown</td><td>Planned / In Progress / Completed / Cancelled</td></tr>
    </table>

    <h4>Milestone Tracking</h4>
    <p>Each milestone within a treatment plan has: description, assigned person, due date, and a completion checkbox. Completing all milestones automatically prompts you to update the risk's residual score and status.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risk Register</strong> — treatment plans are owned by risks; completing a plan reduces the residual score</li>
      <li><strong>Issues</strong> — each treatment plan milestone can spawn an issue for the operational team to execute</li>
      <li><strong>GRC Projects</strong> — large treatment plans with significant budget are linked to a GRC project for portfolio tracking</li>
      <li><strong>Compliance</strong> — implementing new controls as part of treatment may change compliance status for related controls</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'playbooks'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-book-fill"></i> Playbooks &amp; Runbooks</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>Playbooks (also called runbooks) are pre-defined, step-by-step response procedures for known security and operational scenarios. They ensure that when an incident occurs, responders follow a consistent, tested process regardless of experience level. Playbooks are created in advance and attached to incidents at the time of response.</p>

    <h4>Playbook Types</h4>
    <table class="table">
      <tr><th>Type</th><th>Use Case</th><th>Example Title</th></tr>
      <tr><td>Incident Response</td><td>Security events requiring structured response</td><td>"Ransomware Response", "Phishing Email Triage"</td></tr>
      <tr><td>Disaster Recovery</td><td>System restoration following outage or failure</td><td>"Database Failover", "Primary Data Center Recovery"</td></tr>
      <tr><td>Escalation</td><td>Defining who to contact and when</td><td>"Critical Incident Escalation Tree"</td></tr>
      <tr><td>Compliance</td><td>Step-by-step procedures for compliance tasks</td><td>"Annual Access Review Process"</td></tr>
      <tr><td>Change Advisory</td><td>Emergency change approval steps</td><td>"Emergency Change Process"</td></tr>
    </table>

    <h4>Creating a Playbook</h4>
    <ol>
      <li>Go to <strong>Operations → Playbooks → New Playbook</strong></li>
      <li>Enter a descriptive title and select the playbook type</li>
      <li>Add an overview / purpose statement</li>
      <li>Add steps in order: each step has a title, description, assigned role, and estimated time</li>
      <li>Add a "Completion Criteria" section — what must be true for the playbook to be considered complete</li>
      <li>Save and publish the playbook</li>
    </ol>

    <h4>Field Definitions</h4>
    <table class="table">
      <tr><th>Field</th><th>Type</th><th>Description</th></tr>
      <tr><td>Title</td><td>Text (required)</td><td>Descriptive name (e.g., "Phishing Email Triage")</td></tr>
      <tr><td>Type</td><td>Dropdown</td><td>Incident Response / DR / Escalation / Compliance / Change</td></tr>
      <tr><td>Version</td><td>Text</td><td>Version number (e.g., 1.2) — increment on significant changes</td></tr>
      <tr><td>Owner</td><td>User</td><td>Person responsible for keeping this playbook current</td></tr>
      <tr><td>Last Tested</td><td>Date</td><td>Date of the most recent tabletop or live exercise</td></tr>
      <tr><td>Steps</td><td>Ordered list</td><td>Each step: title, description, assigned role, est. time</td></tr>
      <tr><td>Completion Criteria</td><td>Text area</td><td>Conditions that must be met to declare the playbook complete</td></tr>
    </table>

    <h4>Using a Playbook During an Incident</h4>
    <ol>
      <li>When logging an incident, select a playbook from the "Attach Playbook" dropdown</li>
      <li>Or, from an open incident, click <strong>Attach Playbook</strong></li>
      <li>The playbook steps appear in the incident detail view with checkboxes</li>
      <li>Check off each step as it is completed — timestamps are recorded automatically</li>
      <li>Add notes to individual steps documenting what was done</li>
    </ol>

    <h4>Playbook Testing</h4>
    <p>Run tabletop exercises by creating a "test" incident and attaching the playbook. Record exercise results (time to complete, steps that failed, improvements identified) in the incident's post-exercise notes. Update the playbook's <strong>Last Tested</strong> date.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Incidents</strong> — playbooks are attached to incidents and tracked step-by-step during response</li>
      <li><strong>BCP/DR</strong> — DR playbooks are referenced in BCP plans as recovery procedures</li>
      <li><strong>Change Management</strong> — emergency change playbooks standardize the approval bypass process</li>
      <li><strong>Training</strong> — playbooks can be referenced in awareness training as part of role-based training modules</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'questionnaire'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-question-circle-fill"></i> Questionnaires</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Questionnaire module lets you create reusable assessment questionnaires and send them to vendors, internal stakeholders, or any third party. Responses are collected and scored, then linked to vendor risk assessments, audits, or compliance records.</p>

    <h4>Creating a Questionnaire Template</h4>
    <ol>
      <li>Go to <strong>Vendor Risk → Questionnaires → New Template</strong></li>
      <li>Enter a template name and description (e.g., "Annual Vendor Security Assessment")</li>
      <li>Add sections to organize questions (e.g., "Access Control", "Data Protection", "Incident Response")</li>
      <li>Within each section, add questions. For each question, select the type:
        <ul>
          <li><strong>Yes/No</strong> — binary answer with optional comment</li>
          <li><strong>Scale (1–5)</strong> — maturity or capability rating</li>
          <li><strong>Text</strong> — free-form response</li>
          <li><strong>Multiple Choice</strong> — select one from defined options</li>
          <li><strong>Multi-Select</strong> — select all that apply</li>
          <li><strong>File Upload</strong> — attach supporting evidence (e.g., SOC 2 report, pen test summary)</li>
        </ul>
      </li>
      <li>Set the weight of each question for scoring purposes</li>
      <li>Save as a reusable template</li>
    </ol>

    <h4>Sending a Questionnaire</h4>
    <ol>
      <li>Go to <strong>Vendor Risk → [Vendor] → Send Questionnaire</strong></li>
      <li>Select the template to use</li>
      <li>Enter the recipient's email address (does not need an AEGIS account)</li>
      <li>Set a response deadline</li>
      <li>Click <strong>Send</strong> — the recipient receives a secure link with their response portal</li>
    </ol>

    <h4>Scoring and Results</h4>
    <p>Once the questionnaire is submitted, AEGIS scores it based on question weights. The overall score maps to a risk rating:</p>
    <table class="table">
      <tr><th>Score Range</th><th>Risk Rating</th></tr>
      <tr><td>85–100</td><td><span class="badge badge-success">Low Risk</span></td></tr>
      <tr><td>65–84</td><td><span class="badge badge-warning">Medium Risk</span></td></tr>
      <tr><td>40–64</td><td><span class="badge badge-danger">High Risk</span></td></tr>
      <tr><td>&lt;40</td><td><span class="badge badge-danger">Critical Risk</span></td></tr>
    </table>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Vendor Risk</strong> — questionnaire responses update the vendor's overall risk assessment score</li>
      <li><strong>Audits</strong> — questionnaires can be sent to internal stakeholders as part of an audit information gathering phase</li>
      <li><strong>Evidence</strong> — file upload questions allow vendors to submit certifications, SOC 2 reports, and test results as evidence</li>
      <li><strong>Issues</strong> — gaps identified in questionnaire responses can be converted to issues for the vendor owner to track</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'assets'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-server"></i> Asset Inventory</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Asset Inventory provides a central registry of all information assets — hardware, software, cloud services, data repositories, and human resources. Maintaining an accurate asset inventory is a foundational requirement for ISO 27001 (A.8), CIS Control 1 &amp; 2, CMMC, and NIST SP 800-171.</p>

    <h4>Asset Types</h4>
    <table class="table">
      <tr><th>Type</th><th>Examples</th></tr>
      <tr><td>Hardware</td><td>Servers, workstations, laptops, network devices, storage, IoT</td></tr>
      <tr><td>Software</td><td>Operating systems, applications, SaaS subscriptions, databases</td></tr>
      <tr><td>Cloud Service</td><td>AWS, Azure, GCP accounts; SaaS platforms (Salesforce, Office 365)</td></tr>
      <tr><td>Data Repository</td><td>File shares, databases, SharePoint sites, S3 buckets containing sensitive data</td></tr>
      <tr><td>People</td><td>Privileged user accounts, service accounts, API keys</td></tr>
    </table>

    <h4>Adding an Asset</h4>
    <ol>
      <li>Go to <strong>Assets → New Asset</strong></li>
      <li>Enter asset name, description, and type</li>
      <li>Assign an asset owner (the person responsible for its security)</li>
      <li>Set the criticality level (Critical / High / Medium / Low)</li>
      <li>Set the data classification (Public / Internal / Confidential / Restricted)</li>
      <li>Specify the hosting environment (On-Premises / Cloud / Hybrid / Vendor-managed)</li>
      <li>Check <strong>CUI System</strong> if this asset processes or stores Controlled Unclassified Information</li>
      <li>Select applicable compliance scope (which frameworks include this asset in scope)</li>
    </ol>

    <h4>Field Definitions</h4>
    <table class="table">
      <tr><th>Field</th><th>Type</th><th>Description</th></tr>
      <tr><td>Asset Name</td><td>Text (required)</td><td>Unique, descriptive name (e.g., "prod-db-01" or "Salesforce CRM")</td></tr>
      <tr><td>Asset Type</td><td>Dropdown</td><td>Hardware / Software / Cloud / Data / People</td></tr>
      <tr><td>Criticality</td><td>Dropdown</td><td>Critical / High / Medium / Low — impact if asset is unavailable</td></tr>
      <tr><td>Classification</td><td>Dropdown</td><td>Public / Internal / Confidential / Restricted — data sensitivity</td></tr>
      <tr><td>Owner</td><td>User</td><td>Person accountable for the asset's security posture</td></tr>
      <tr><td>Location</td><td>Text</td><td>Physical location or URL (e.g., "AWS us-east-1", "Server Room A")</td></tr>
      <tr><td>CUI System</td><td>Boolean</td><td>Whether this asset is in the CUI boundary for CMMC/NIST</td></tr>
      <tr><td>Status</td><td>Dropdown</td><td>Active / Decommissioned / Under Review</td></tr>
      <tr><td>Compliance Scope</td><td>Multi-select</td><td>Which compliance packages include this asset</td></tr>
    </table>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risks</strong> — assets are linked to risks to define the scope and potential impact of each risk scenario</li>
      <li><strong>CUI Inventory</strong> — assets flagged as CUI Systems appear in the CUI boundary view</li>
      <li><strong>Incidents</strong> — affected assets are linked to incidents to track which systems were involved</li>
      <li><strong>BCP/DR</strong> — critical assets are scoped into BCP plans with RTO/RPO parameters</li>
      <li><strong>Compliance</strong> — asset scope determines which controls apply to a system</li>
      <li><strong>Vendor Risk</strong> — vendor-managed assets link to the vendor record for accountability</li>
    </ul>

    <h4>Best Practices</h4>
    <ul>
      <li>Review and update the asset inventory quarterly, or whenever assets are provisioned or decommissioned</li>
      <li>Every critical asset should have a named owner — avoid "IT Team" as an owner (use a specific person)</li>
      <li>Tag all assets that process CUI before a CMMC assessment — the boundary must be accurate</li>
      <li>Link high-criticality assets to your BCP/DR plan so recovery procedures are pre-defined</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'account_reviews'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-person-check-fill"></i> Account Reviews</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>Periodic Access Reviews (also called User Access Certifications) are a required control in ISO 27001 (A.9.2.5), SOC 2 (CC6.2), HIPAA, PCI-DSS (7.3.3), and CMMC (AC.2.006). They ensure that user access rights remain appropriate over time — employees who change roles or leave should not retain unnecessary access.</p>

    <h4>Review Types</h4>
    <table class="table">
      <tr><th>Review Type</th><th>Scope</th><th>Typical Frequency</th></tr>
      <tr><td>All Users</td><td>Every active account in scope systems</td><td>Annual</td></tr>
      <tr><td>Privileged Users</td><td>Admin accounts, service accounts, root access</td><td>Quarterly</td></tr>
      <tr><td>Departed Users</td><td>Accounts for people who have left the organization</td><td>Monthly or triggered by offboarding</td></tr>
      <tr><td>Role-Based</td><td>Users with a specific role or in a specific department</td><td>Semi-annual</td></tr>
    </table>

    <h4>Running an Account Review</h4>
    <ol>
      <li>Go to <strong>Operations → Account Reviews → New Review</strong></li>
      <li>Enter the review name (e.g., "Q3 2025 Privileged Access Certification")</li>
      <li>Select the review type and scope</li>
      <li>Set a review deadline</li>
      <li>Assign reviewers (managers certify their direct reports; IT certifies service accounts)</li>
      <li>AEGIS sends reviewers a list of accounts to certify</li>
      <li>Reviewers mark each account as: <strong>Certify</strong> (access is appropriate) or <strong>Revoke</strong> (access should be removed)</li>
      <li>All revoke decisions generate issues assigned to IT for immediate action</li>
    </ol>

    <h4>Segregation of Duties Checks</h4>
    <p>The review interface flags accounts that hold combinations of roles that violate segregation of duties (SoD) rules (e.g., a user who can both approve purchases and process payments). Flag these for mandatory revocation or documented exception.</p>

    <h4>Review Completion</h4>
    <p>A review is complete when 100% of in-scope accounts have been certified or revoked. The completed review is stored as audit evidence. Overdue reviews appear on the compliance dashboard.</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Issues</strong> — revoked access decisions auto-create issues for IT to execute the access removal</li>
      <li><strong>Compliance</strong> — completed reviews satisfy access control requirements in ISO 27001, SOC 2, PCI-DSS</li>
      <li><strong>Users</strong> — review scope pulls from the active AEGIS user directory</li>
      <li><strong>Evidence</strong> — review completion records are stored as compliance evidence</li>
      <li><strong>Automation</strong> — scheduled trigger can auto-initiate reviews on a defined cadence</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'documents'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-folder2-open"></i> Document Management</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Document Management module provides a centralized repository for GRC-related documents: policies, procedures, standards, guidelines, forms, templates, and records. It provides version control, access control, review scheduling, and links to compliance controls.</p>

    <h4>Document Types</h4>
    <table class="table">
      <tr><th>Type</th><th>Description</th><th>Examples</th></tr>
      <tr><td>Policy</td><td>High-level management directive</td><td>Information Security Policy, Acceptable Use Policy</td></tr>
      <tr><td>Standard</td><td>Mandatory technical or process requirements</td><td>Password Standard, Encryption Standard</td></tr>
      <tr><td>Procedure</td><td>Step-by-step instructions for carrying out a task</td><td>Incident Response Procedure, Onboarding Checklist</td></tr>
      <tr><td>Guideline</td><td>Recommended practices; not mandatory</td><td>Security Configuration Guidance, Developer Security Tips</td></tr>
      <tr><td>Template</td><td>Reusable forms or document skeletons</td><td>Risk Assessment Template, BCP Template</td></tr>
      <tr><td>Record</td><td>Evidence of an activity or decision</td><td>Meeting minutes, approval records, test results</td></tr>
    </table>

    <h4>Uploading a Document</h4>
    <ol>
      <li>Go to <strong>Documents → Upload Document</strong></li>
      <li>Select the file (PDF, Word, Excel, images — up to 20 MB)</li>
      <li>Enter title, document type, and description</li>
      <li>Assign an owner and set a review date</li>
      <li>Set the document classification (Public / Internal / Confidential)</li>
      <li>Tag with relevant compliance frameworks or control IDs</li>
      <li>Set the version number (start at 1.0 for new documents)</li>
    </ol>

    <h4>Field Definitions</h4>
    <table class="table">
      <tr><th>Field</th><th>Type</th><th>Description</th></tr>
      <tr><td>Title</td><td>Text (required)</td><td>Document display name</td></tr>
      <tr><td>Document Type</td><td>Dropdown</td><td>Policy / Standard / Procedure / Guideline / Template / Record</td></tr>
      <tr><td>Version</td><td>Text</td><td>Version number (e.g., 2.1); increment on each update</td></tr>
      <tr><td>Owner</td><td>User</td><td>Person responsible for keeping the document current</td></tr>
      <tr><td>Classification</td><td>Dropdown</td><td>Public / Internal / Confidential / Restricted</td></tr>
      <tr><td>Review Date</td><td>Date</td><td>Next scheduled review; overdue documents are flagged</td></tr>
      <tr><td>Expiry Date</td><td>Date</td><td>Date after which the document is no longer valid</td></tr>
      <tr><td>Tags</td><td>Text</td><td>Comma-separated tags for search (e.g., "ISO27001, access-control")</td></tr>
    </table>

    <h4>Version History</h4>
    <p>When you upload a new version of an existing document, AEGIS retains all prior versions. Click <strong>Version History</strong> on any document to see all versions with upload date, uploader, and version notes. Older versions remain downloadable for audit trail purposes.</p>

    <h4>Review Reminders</h4>
    <p>Documents past their <code>review_date</code> are highlighted on the Document index page. Owners receive email reminders 30 days before their document's review date (requires email configured and the Document Review Due automation rule enabled).</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Policies</strong> — policy documents are stored here; the Policy module references document IDs</li>
      <li><strong>Compliance</strong> — documents are linked to controls as evidence of implementation (e.g., a Password Standard satisfies password complexity controls)</li>
      <li><strong>Evidence</strong> — documents can be attached as evidence to audits, findings, or controls</li>
      <li><strong>Approvals</strong> — new or updated documents route through an approval workflow before publication</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'evidence'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-paperclip"></i> Evidence Management</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>Evidence Management handles the collection, storage, and organization of proof that controls are implemented, risks are treated, and compliance requirements are met. Strong evidence management is what turns your GRC program from a paper exercise into a defensible, audit-ready practice.</p>

    <h4>What Counts as Evidence?</h4>
    <table class="table">
      <tr><th>Evidence Type</th><th>Examples</th></tr>
      <tr><td>Configuration Screenshots</td><td>Firewall rules, MFA settings, RBAC configuration</td></tr>
      <tr><td>Log Exports</td><td>Authentication logs, audit logs, change logs</td></tr>
      <tr><td>Certificates &amp; Reports</td><td>SOC 2 Type II report, penetration test report, ISO 27001 certificate</td></tr>
      <tr><td>Policies &amp; Procedures</td><td>Signed policy documents, procedure guides</td></tr>
      <tr><td>Meeting Records</td><td>Security committee minutes, risk review decisions</td></tr>
      <tr><td>Scan Reports</td><td>Vulnerability scan results, SAST/DAST output</td></tr>
      <tr><td>Training Records</td><td>Awareness training completion reports, attestation records</td></tr>
      <tr><td>Contracts &amp; Agreements</td><td>Vendor NDAs, DPAs, BAAs, SLAs</td></tr>
    </table>

    <h4>Uploading Evidence</h4>
    <p>Evidence can be attached to any of the following entities throughout AEGIS:</p>
    <ul>
      <li><strong>Compliance Controls</strong> — in the control detail view, click <strong>Add Evidence</strong></li>
      <li><strong>Audits</strong> — on the audit assessment page, click the evidence clip icon next to a control</li>
      <li><strong>Audit Findings</strong> — attach evidence of remediation to a finding</li>
      <li><strong>Incidents</strong> — attach forensic evidence, logs, or post-incident reports</li>
      <li><strong>Vendors</strong> — attach SOC 2 reports, questionnaire responses, certifications</li>
      <li><strong>Risks</strong> — attach treatment evidence or risk acceptance letters</li>
    </ul>

    <h4>Field Definitions</h4>
    <table class="table">
      <tr><th>Field</th><th>Type</th><th>Description</th></tr>
      <tr><td>File</td><td>Upload (required)</td><td>The actual evidence file (PDF, image, CSV, ZIP, etc.)</td></tr>
      <tr><td>Description</td><td>Text</td><td>What this evidence proves (e.g., "MFA enabled for all admin accounts as of 2025-01")</td></tr>
      <tr><td>Evidence Date</td><td>Date</td><td>When this evidence was collected (not the upload date)</td></tr>
      <tr><td>Expiry Date</td><td>Date</td><td>When this evidence becomes stale and must be re-collected</td></tr>
      <tr><td>Collector</td><td>User</td><td>Who collected and validated this evidence</td></tr>
    </table>

    <h4>Evidence Integrity</h4>
    <p>AEGIS computes an SHA-256 hash of each uploaded file at the time of upload. The hash is stored and displayed on the evidence record. To verify a file has not been tampered with, recompute the hash and compare it to the stored value.</p>

    <h4>Evidence Packages for Auditors</h4>
    <ol>
      <li>Go to <strong>Audits → [Audit] → Export Package</strong></li>
      <li>AEGIS bundles all evidence files attached to the audit's controls into a single ZIP</li>
      <li>Files are organized by control code (e.g., <code>AC-1/mfa_config_screenshot.png</code>)</li>
      <li>A <code>findings.csv</code> and <code>README.txt</code> summary are included</li>
    </ol>

    <h4>Expiry Tracking</h4>
    <p>Evidence with an expiry date is flagged on the compliance package view when the expiry date is within 30 days or has passed. This prompts you to re-collect fresh evidence before it goes stale (especially important for annual certifications like SOC 2 reports).</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Compliance</strong> — evidence is the primary proof for control assertions; without it, controls cannot be certified as compliant</li>
      <li><strong>Audits</strong> — audits bundle all evidence for export to external auditors</li>
      <li><strong>Audit Findings</strong> — remediation evidence attached to findings demonstrates closure</li>
      <li><strong>Documents</strong> — policy and procedure documents double as evidence for policy-based controls</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'approvals'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-check2-circle"></i> Approval Workflows</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Approval Workflow module provides a formal, auditable approval chain for GRC decisions that require management authorization. It ensures that significant actions (accepting a risk, publishing a policy, deploying a change) are reviewed and explicitly authorized by the right stakeholders before proceeding.</p>

    <h4>What Triggers an Approval Request?</h4>
    <table class="table">
      <tr><th>Trigger</th><th>Module</th><th>Approver</th></tr>
      <tr><td>Risk Exception (Accept treatment)</td><td>Risk Register</td><td>CISO or Risk Committee</td></tr>
      <tr><td>Policy Publication</td><td>Policies</td><td>Policy Owner's Manager or CISO</td></tr>
      <tr><td>Change Request (Normal/Major)</td><td>Change Management</td><td>Change Advisory Board (CAB)</td></tr>
      <tr><td>Vendor Onboarding</td><td>Vendor Risk</td><td>Procurement + Security</td></tr>
      <tr><td>POA&amp;M Closure</td><td>POA&amp;M</td><td>ISSO or ISSM</td></tr>
      <tr><td>Audit Report Sign-Off</td><td>Audits</td><td>Audit Committee or Management</td></tr>
    </table>

    <h4>Approval Lifecycle</h4>
    <ol>
      <li><strong>Draft</strong> — Request initiated by the submitter; not yet submitted</li>
      <li><strong>Pending</strong> — Submitted; awaiting approver review</li>
      <li><strong>Approved</strong> — Approver has authorized the action; the triggering event proceeds</li>
      <li><strong>Rejected</strong> — Approver has declined; submitter must revise or escalate</li>
      <li><strong>Expired</strong> — Approval not acted on within the timeout window; request must be resubmitted</li>
    </ol>

    <h4>Escalation Rules</h4>
    <p>If an approver does not respond within the configured timeout (default: 5 business days), the request escalates to the approver's manager. The escalation chain is configured in <strong>Admin → Approval Settings</strong>.</p>

    <h4>Delegation</h4>
    <p>Approvers can delegate their approval authority to another user for a defined period (e.g., during annual leave) via <strong>My Profile → Approval Delegation</strong>. All approvals made by a delegate are recorded as "(delegated from [name])" in the audit trail.</p>

    <h4>Audit Trail</h4>
    <p>Every approval decision (approve/reject), timestamp, approver identity, and any comments are permanently recorded. The audit trail is accessible from the originating record (e.g., from the risk record's approval history tab).</p>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>Risk Register</strong> — risk exceptions require formal approval before the risk can be marked as "Accepted"</li>
      <li><strong>Policies</strong> — draft policies cannot be published without an approval</li>
      <li><strong>Change Management</strong> — normal and major changes are blocked pending CAB approval</li>
      <li><strong>Custom Dashboards</strong> — stat card widget can track "Pending Approvals" count</li>
      <li><strong>Automation</strong> — the Approval Workflow can be triggered by automation rules for certain events</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'reports'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-file-earmark-bar-graph"></i> Reports</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Reports module generates structured, exportable reports for different audiences — from technical compliance detail reports for analysts to high-level executive summaries for boards and leadership teams.</p>

    <h4>Available Report Types</h4>

    <h5>Board / Executive Report</h5>
    <p>A high-level summary including: SPRS score trend, top 5 open risks by score, compliance posture per framework (% compliant), open incidents this month, and upcoming key dates (POA&amp;M milestones, audit schedules, policy reviews). Designed for non-technical leadership.</p>

    <h5>Compliance Status Report</h5>
    <p>Per-framework breakdown showing: total controls, compliant count, partial count, non-compliant count, N/A count, and % gap. Includes a domain-by-domain heat map and a list of all non-compliant controls with owner and deadline.</p>

    <h5>Risk Report</h5>
    <p>Risk register snapshot including: risk count by severity, top 10 open risks, risk distribution heat map (likelihood × impact grid), treatment plan completion rates, and open risk exceptions awaiting approval.</p>

    <h5>Audit Report</h5>
    <p>Summary of an individual audit engagement: scope, auditor, dates, control pass/fail statistics, all findings with severity, evidence collected count, and recommendations.</p>

    <h5>Vendor Risk Report</h5>
    <p>Third-party risk summary: vendor count by tier, vendors with overdue assessments, high-risk vendors (score &lt;65), contracts expiring in 90 days, and open issues by vendor.</p>

    <h5>SPRS Readiness Report</h5>
    <p>Detailed NIST SP 800-171 self-assessment: current SPRS score, score by domain, list of all non-compliant and partial practices with deduction amounts, POA&amp;M item summary, and projected score after POA&amp;M completion.</p>

    <h5>Incident Summary Report</h5>
    <p>Incident metrics for a date range: total incidents by severity, mean time to contain (MTTC), mean time to resolve (MTTR), SLA compliance rate, and top incident categories.</p>

    <h4>Generating a Report</h4>
    <ol>
      <li>Go to <strong>Analytics → Reports</strong></li>
      <li>Select the report type</li>
      <li>Configure parameters: date range, frameworks, status filters</li>
      <li>Click <strong>Generate</strong></li>
      <li>Preview the report, then export as <strong>PDF</strong> (for presentation) or <strong>Excel/CSV</strong> (for further analysis)</li>
    </ol>

    <h4>Scheduled Reports</h4>
    <p>Configure automatic report generation and distribution via <strong>Reports → Scheduled Reports → New Schedule</strong>. Set frequency (weekly/monthly/quarterly), recipients, and format. Reports are emailed on schedule from the configured SMTP server.</p>

    <h4>Interconnections</h4>
    <ul>
      <li>All modules feed into reports — reports are read-only aggregations of live data</li>
      <li><strong>Custom Dashboards</strong> — dashboards provide interactive monitoring; reports provide point-in-time snapshots</li>
      <li><strong>Export</strong> — reports can be exported to PDF/Excel via the Export module</li>
    </ul>
  </div>
</div>

<?php elseif ($section === 'export'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-download"></i> Export</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Export module lets you extract data from any AEGIS module in bulk for use in external tools, regulatory submissions, offline analysis, or backup purposes.</p>

    <h4>What Can Be Exported?</h4>
    <table class="table">
      <tr><th>Module</th><th>Export Contents</th><th>Formats</th></tr>
      <tr><td>Risk Register</td><td>All risk fields, scores, treatment type, owner</td><td>CSV, Excel, JSON</td></tr>
      <tr><td>Compliance Package</td><td>All controls, statuses, owners, implementation notes</td><td>CSV, Excel</td></tr>
      <tr><td>POA&amp;M</td><td>All POA&amp;M items, milestones, responsible parties</td><td>CSV, Excel (OMB format)</td></tr>
      <tr><td>Audit Findings</td><td>All findings with severity, source, status, deadlines</td><td>CSV, Excel</td></tr>
      <tr><td>Vendors</td><td>Vendor details, tier, assessment scores, contracts</td><td>CSV, Excel</td></tr>
      <tr><td>Incidents</td><td>All incident fields, timeline, severity, status</td><td>CSV, Excel, JSON</td></tr>
      <tr><td>Issues</td><td>All issue fields, source, assignee, resolution</td><td>CSV, Excel</td></tr>
      <tr><td>Assets</td><td>Asset inventory with criticality, owner, classification</td><td>CSV, Excel</td></tr>
      <tr><td>Users</td><td>User list with roles (admin only)</td><td>CSV</td></tr>
    </table>

    <h4>Filtered Exports</h4>
    <p>Most exports support filters before download:</p>
    <ul>
      <li><strong>Date range</strong> — created_at or updated_at within a period</li>
      <li><strong>Status</strong> — e.g., only Open risks, only Non-Compliant controls</li>
      <li><strong>Owner</strong> — filter by assigned user</li>
      <li><strong>Severity / Tier</strong> — only High/Critical items</li>
    </ul>

    <h4>Evidence Package Export</h4>
    <p>The most powerful export feature — go to <strong>Audits → [Audit] → Export Package</strong> to download a ZIP archive containing:</p>
    <ul>
      <li>All evidence files organized by control code folder</li>
      <li><code>findings.csv</code> — assessment results for every control</li>
      <li><code>README.txt</code> — audit metadata, scope, auditor, dates, score summary</li>
    </ul>
    <p>This package is designed for direct handoff to external auditors and certification bodies.</p>

    <h4>SIEM / SOAR Integration</h4>
    <p>JSON exports can be consumed directly by SIEM or SOAR platforms. Use the API for automated recurring exports:</p>
    <pre><code>GET /api/v1/risks?format=json&amp;status=open&amp;severity=critical
Authorization: Bearer &lt;token&gt;</code></pre>

    <h4>Scheduled Exports</h4>
    <p>Configure recurring exports at <strong>Admin → Scheduled Jobs → New Export</strong>. Set frequency, filters, format, and delivery method (email attachment or S3 bucket upload).</p>
  </div>
</div>

<?php elseif ($section === 'metrics'): ?>
<div class="card">
  <div class="card-header"><h3><i class="bi bi-graph-up"></i> Metrics &amp; Trends</h3></div>
  <div class="card-body docs-body">
    <h4>Purpose</h4>
    <p>The Metrics module provides time-series tracking of key GRC program metrics. Where Custom Dashboards show current state, Metrics show <em>trends over time</em> — enabling you to demonstrate program improvement, identify deteriorating areas, and support data-driven decision making.</p>

    <h4>Built-In Metrics</h4>
    <table class="table">
      <tr><th>Metric</th><th>How Calculated</th><th>Frequency</th></tr>
      <tr><td>Compliance %</td><td>(Compliant controls / Total assessed controls) × 100</td><td>Daily snapshot</td></tr>
      <tr><td>SPRS Score</td><td>110 − (sum of deductions from non-compliant/partial practices)</td><td>Daily snapshot</td></tr>
      <tr><td>Open Critical Risks</td><td>Count of risks with score ≥ 15 and status = Open</td><td>Daily</td></tr>
      <tr><td>Mean Time to Resolve (MTTR)</td><td>Avg. days from incident detected_at to resolved_at (30-day window)</td><td>Weekly</td></tr>
      <tr><td>Overdue Issues %</td><td>(Issues past due date / Total open issues) × 100</td><td>Daily</td></tr>
      <tr><td>Vendor High-Risk Count</td><td>Count of vendors with risk_tier = critical or high</td><td>Weekly</td></tr>
      <tr><td>POA&amp;M Completion Rate</td><td>(Closed POA&amp;M items / Total POA&amp;M items) × 100</td><td>Weekly</td></tr>
      <tr><td>Training Completion %</td><td>(Users completed / Total users assigned) × 100 per campaign</td><td>Daily during campaign</td></tr>
    </table>

    <h4>Snapshots</h4>
    <p>Metric snapshots are captured automatically by the <code>capture_metrics_snapshot.php</code> cron job (runs at midnight daily). Each snapshot stores the value at that point in time, enabling trend charts across days, weeks, and months.</p>

    <h4>Trend Charts</h4>
    <p>Go to <strong>Analytics → Metrics</strong> and select a metric to view its trend chart. Options:</p>
    <ul>
      <li>Date range: Last 30 days / Last 90 days / Last 12 months / Custom range</li>
      <li>Chart type: Line chart (default), Bar chart, Area chart</li>
      <li>Overlay: Add a second metric for comparison (e.g., overlay SPRS Score with Compliance %)</li>
    </ul>

    <h4>Custom Metrics</h4>
    <p>Create custom metrics via <strong>Analytics → Metrics → New Custom Metric</strong>. Specify:</p>
    <ul>
      <li>Name and description</li>
      <li>SQL query or calculated formula (admin only — sanitized against injection)</li>
      <li>Unit of measure (count, %, score, days)</li>
      <li>Threshold values (green/yellow/red) for KRI integration</li>
    </ul>

    <h4>Interconnections</h4>
    <ul>
      <li><strong>KRI Dashboard</strong> — KRIs are metrics with defined thresholds; a metric exceeding its red threshold triggers a KRI alert</li>
      <li><strong>Custom Dashboards</strong> — metric values can be surfaced as stat card widgets</li>
      <li><strong>Reports</strong> — trend charts are embedded in Board and Executive reports</li>
      <li><strong>Automation</strong> — metric thresholds can trigger automation rules (e.g., when Overdue Issues % &gt; 30, create an escalation issue)</li>
    </ul>
  </div>
</div>

<?php else: ?>
<div class="card">
  <div class="card-header"><h3><?= Security::h($sections[$section]['label'] ?? ucfirst($section)) ?></h3></div>
  <div class="card-body docs-body">
    <p style="color:var(--text-muted);">Documentation for this section is being prepared. Check back soon, or refer to the <a href="?s=overview">Overview</a>.</p>
  </div>
</div>
<?php endif; ?>

</div><!-- end content -->
</div><!-- end flex wrapper -->

<style>
.docs-body h4 { margin:20px 0 8px; font-size:15px; font-weight:600; }
.docs-body h4:first-child { margin-top:0; }
.docs-body p { margin-bottom:10px; line-height:1.7; }
.docs-body ul, .docs-body ol { margin:0 0 12px; padding-left:24px; line-height:1.7; }
.docs-body li { margin-bottom:4px; }
.docs-body pre { background:#0f172a; color:#e2e8f0; padding:12px 16px; border-radius:8px; font-size:12px; overflow-x:auto; margin:8px 0 16px; }
.docs-body code { background:#f1f5f9; color:#1e3a5f; padding:1px 5px; border-radius:3px; font-size:12px; }
.docs-body pre code { background:none; color:inherit; padding:0; }
.docs-body .table td, .docs-body .table th { padding:8px 12px; vertical-align:top; font-size:0.875rem; }
.docs-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin:12px 0 20px; }
.docs-card { background:var(--bg); border:1px solid var(--border); border-radius:8px; padding:14px; }
.docs-card i { font-size:20px; color:var(--primary); display:block; margin-bottom:6px; }
.docs-card strong { display:block; margin-bottom:4px; }
.docs-card p { margin:0; font-size:13px; color:var(--text-muted); }
</style>
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
?>
