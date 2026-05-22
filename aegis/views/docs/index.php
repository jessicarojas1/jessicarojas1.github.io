<?php
$pageTitle    = 'Documentation';
$activeModule = 'docs';
$breadcrumbs  = [['Documentation', null]];
ob_start();
?>

<div class="docs-layout">

  <!-- Scrollspy sidebar -->
  <nav class="docs-nav" id="docsNav">
    <div class="docs-nav-inner">
      <div class="docs-nav-title">Contents</div>

      <a href="#overview"       class="docs-link active">1. Overview</a>
      <a href="#getting-started" class="docs-link">2. Getting Started</a>
      <a href="#navigation"     class="docs-link">3. Navigation</a>
      <a href="#roles"          class="docs-link">4. Roles &amp; Permissions</a>
      <a href="#compliance"     class="docs-link">5. Compliance</a>
      <a href="#s-packages"     class="docs-link docs-sub">Packages</a>
      <a href="#s-objectives"   class="docs-link docs-sub">Control Objectives</a>
      <a href="#s-status"       class="docs-link docs-sub">Updating Status</a>
      <a href="#s-import"       class="docs-link docs-sub">Importing Standards</a>
      <a href="#audits"         class="docs-link">6. Audits</a>
      <a href="#a-create"       class="docs-link docs-sub">Creating an Audit</a>
      <a href="#a-conduct"      class="docs-link docs-sub">Conducting an Audit</a>
      <a href="#a-score"        class="docs-link docs-sub">Scores &amp; Completion</a>
      <a href="#policies"       class="docs-link">7. Policies</a>
      <a href="#p-lifecycle"    class="docs-link docs-sub">Lifecycle</a>
      <a href="#p-mapping"      class="docs-link docs-sub">Mapping to Controls</a>
      <a href="#risk"           class="docs-link">8. Risk Register</a>
      <a href="#r-scoring"      class="docs-link docs-sub">Scoring Model</a>
      <a href="#r-treatment"    class="docs-link docs-sub">Treatment</a>
      <a href="#r-matrix"       class="docs-link docs-sub">Risk Matrix</a>
      <a href="#metrics"        class="docs-link">9. Metrics</a>
      <a href="#export"         class="docs-link">10. Export</a>
      <a href="#dashboard"      class="docs-link">11. Dashboard</a>
      <a href="#admin"          class="docs-link">12. Administration</a>
      <a href="#adm-users"      class="docs-link docs-sub">User Management</a>
      <a href="#adm-perms"      class="docs-link docs-sub">Permissions Matrix</a>
      <a href="#adm-matrix"     class="docs-link docs-sub">Risk Matrix Config</a>
      <a href="#adm-workflows"  class="docs-link docs-sub">Workflows</a>
      <a href="#adm-alerts"     class="docs-link docs-sub">Alerts</a>
      <a href="#adm-apikeys"    class="docs-link docs-sub">API Keys</a>
      <a href="#adm-logs"       class="docs-link docs-sub">Activity Logs</a>
      <a href="#api"            class="docs-link">13. REST API</a>
      <a href="#api-auth"       class="docs-link docs-sub">Authentication</a>
      <a href="#api-endpoints"  class="docs-link docs-sub">Endpoints</a>
      <a href="#security"       class="docs-link">14. Security</a>
      <a href="#glossary"       class="docs-link">15. Glossary</a>
    </div>
  </nav>

  <!-- Main content -->
  <article class="docs-body" id="docsBody">

    <!-- ═══════════════════════════════════════════════════════ 1. OVERVIEW -->
    <section id="overview" class="docs-section">
      <h1 class="docs-h1"><i class="bi bi-shield-fill-check"></i> AEGIS GRC Platform</h1>
      <p class="docs-lead">AEGIS is a Governance, Risk &amp; Compliance (GRC) platform built for security-conscious organizations. It centralises compliance tracking, risk management, policy governance, and audit management into a single, self-hosted application.</p>

      <div class="docs-callout docs-callout-info">
        <i class="bi bi-info-circle-fill"></i>
        <div><strong>Version 1.0.0</strong> — Built with PHP 8.2, PostgreSQL, and zero external PHP dependencies. No Composer. No npm. Deployable as a single Docker container.</div>
      </div>

      <h2 class="docs-h2">Key Capabilities</h2>
      <div class="docs-feature-grid">
        <div class="docs-feature">
          <i class="bi bi-shield-check"></i>
          <div>
            <strong>Compliance Tracking</strong>
            <p>Track implementation status of controls across CMMC 2.0 L2, ISO 27001:2022, ISO 42001:2023, and any imported custom standard. Evidence, notes, and assignees on every control.</p>
          </div>
        </div>
        <div class="docs-feature">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div>
            <strong>Risk Register</strong>
            <p>Capture, score, and treat risks using a configurable 5×5 likelihood/impact matrix. Track inherent vs. residual scores and monitor open risks by category.</p>
          </div>
        </div>
        <div class="docs-feature">
          <i class="bi bi-clipboard2-check-fill"></i>
          <div>
            <strong>Audit Management</strong>
            <p>Schedule and conduct internal, external, and gap audits against compliance packages. Score findings and track completion rates over time.</p>
          </div>
        </div>
        <div class="docs-feature">
          <i class="bi bi-file-earmark-text-fill"></i>
          <div>
            <strong>Policy Governance</strong>
            <p>Author, version, approve, and publish policies. Map policies directly to compliance controls for traceability. Schedule periodic reviews.</p>
          </div>
        </div>
        <div class="docs-feature">
          <i class="bi bi-graph-up-arrow"></i>
          <div>
            <strong>Metrics &amp; Charts</strong>
            <p>Real-time KPIs: overall compliance %, open risk count, audit completion rate, published policy count. Five Chart.js visualisations for trend analysis.</p>
          </div>
        </div>
        <div class="docs-feature">
          <i class="bi bi-download"></i>
          <div>
            <strong>Export</strong>
            <p>Download any data module as CSV or XLSX, or pull a full ZIP containing all six modules. Formula-injection safe. No spreadsheet library required.</p>
          </div>
        </div>
        <div class="docs-feature">
          <i class="bi bi-people-fill"></i>
          <div>
            <strong>Role-Based Access</strong>
            <p>Five built-in roles (Admin, Manager, Auditor, Analyst, Viewer) with per-user, per-module overrides configurable from the Permissions matrix.</p>
          </div>
        </div>
        <div class="docs-feature">
          <i class="bi bi-code-slash"></i>
          <div>
            <strong>REST API</strong>
            <p>A full REST API (v1) secured by API keys or JWT Bearer tokens. Rate-limited at 60 req/min per IP. Supports CORS for same-origin clients.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ═══════════════════════════════════════════════════ 2. GETTING STARTED -->
    <section id="getting-started" class="docs-section">
      <h1 class="docs-h1">Getting Started</h1>

      <h2 class="docs-h2">First Login</h2>
      <p>After deployment, navigate to your AEGIS URL. The default administrator credentials are set via the <code>ADMIN_EMAIL</code> and <code>ADMIN_PASSWORD</code> environment variables. On Render these are provisioned during setup.</p>

      <div class="docs-callout docs-callout-warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div><strong>Change the admin password immediately</strong> after first login. Go to <strong>Administration → Users</strong>, click the admin account, and set a strong password (minimum 12 characters, upper case, number, special character).</div>
      </div>

      <ol class="docs-list">
        <li>Browse to your AEGIS URL and enter your email address and password on the login screen.</li>
        <li>Upon successful login you are redirected to the <strong>Dashboard</strong>.</li>
        <li>If no compliance packages exist yet, the dashboard KPIs will show zeroes — this is expected. Your first action should be to import or enable a compliance standard.</li>
        <li>If you were redirected to the login page from a protected URL, AEGIS will send you back to that page after you log in.</li>
      </ol>

      <h2 class="docs-h2">Session Management</h2>
      <p>Sessions are set to expire after <strong>8 hours of inactivity</strong> (configurable in <code>config/app.php</code>). Each page load refreshes the activity timestamp. When a session expires you are redirected to <code>/login?reason=timeout</code> and prompted to log in again.</p>

      <p>Sessions are hardened with the following cookie attributes:</p>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Attribute</th><th>Value</th><th>Purpose</th></tr></thead>
          <tbody>
            <tr><td><code>HttpOnly</code></td><td>true</td><td>Blocks JavaScript from reading the session cookie — prevents XSS theft</td></tr>
            <tr><td><code>SameSite</code></td><td>Lax</td><td>Blocks cross-site form submissions while allowing top-level navigation</td></tr>
            <tr><td><code>Secure</code></td><td>true (HTTPS only)</td><td>Cookie only sent over encrypted connections in production</td></tr>
            <tr><td><code>StrictMode</code></td><td>true</td><td>Rejects session IDs not created by the server — prevents session fixation</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ════════════════════════════════════════════════════ 3. NAVIGATION -->
    <section id="navigation" class="docs-section">
      <h1 class="docs-h1">Navigation</h1>

      <h2 class="docs-h2">Sidebar</h2>
      <p>The collapsible left sidebar organises all modules into sections. The active page is highlighted. On narrow screens the sidebar collapses; tap the <i class="bi bi-list"></i> hamburger button in the top-left to toggle it.</p>

      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Section</th><th>Item</th><th>URL</th><th>Minimum Role</th></tr></thead>
          <tbody>
            <tr><td rowspan="1">Overview</td><td>Dashboard</td><td><code>/</code></td><td>Any authenticated</td></tr>
            <tr><td rowspan="3">Compliance</td><td>Packages</td><td><code>/compliance</code></td><td>Viewer</td></tr>
            <tr><td>Import Standard</td><td><code>/compliance/import</code></td><td>Manager</td></tr>
            <tr><td rowspan="2">Operations</td><td>Audits</td><td><code>/audit</code></td><td>Viewer</td></tr>
            <tr><td>Policies</td><td><code>/policy</code></td><td>Viewer</td></tr>
            <tr><td rowspan="2"></td><td>Metrics</td><td><code>/metrics</code></td><td>Any authenticated</td></tr>
            <tr><td>Export</td><td><code>/export</code></td><td>Viewer (compliance.read)</td></tr>
            <tr><td rowspan="1">Resources</td><td>Documentation</td><td><code>/docs</code></td><td>Any authenticated</td></tr>
            <tr><td rowspan="2">Risk</td><td>Risk Register</td><td><code>/risk</code></td><td>Viewer</td></tr>
            <tr><td>Risk Matrix</td><td><code>/risk/matrix</code></td><td>Viewer</td></tr>
            <tr><td rowspan="7">Administration</td><td>Overview</td><td><code>/admin</code></td><td>Admin only</td></tr>
            <tr><td>Users</td><td><code>/admin/users</code></td><td>Admin only</td></tr>
            <tr><td>Risk Matrix Config</td><td><code>/admin/risk-matrix</code></td><td>Admin only</td></tr>
            <tr><td>Workflows</td><td><code>/admin/workflows</code></td><td>Admin only</td></tr>
            <tr><td>Alerts</td><td><code>/admin/alerts</code></td><td>Admin only</td></tr>
            <tr><td>API Keys</td><td><code>/admin/api-keys</code></td><td>Admin only</td></tr>
            <tr><td>Permissions</td><td><code>/admin/permissions</code></td><td>Admin only</td></tr>
          </tbody>
        </table>
      </div>

      <h2 class="docs-h2">Topbar</h2>
      <p>The topbar contains three elements:</p>
      <ul class="docs-list">
        <li><strong>Hamburger button</strong> — toggles the sidebar on all screen sizes.</li>
        <li><strong>Breadcrumb trail</strong> — shows your current location and links to parent pages.</li>
        <li><strong>Notification bell</strong> — shows a badge count of unread alerts. Click to open the slide-in notification panel. Individual alerts can be dismissed with the checkmark button.</li>
      </ul>
    </section>

    <!-- ════════════════════════════════════════════════════ 4. ROLES -->
    <section id="roles" class="docs-section">
      <h1 class="docs-h1">Roles &amp; Permissions</h1>

      <p>AEGIS uses a two-layer permission model: <strong>role defaults</strong> apply to all users of a given role, while <strong>explicit grants</strong> in the <code>user_permissions</code> table can extend or override those defaults for individual users. Admins always have full access.</p>

      <h2 class="docs-h2">Built-in Role Defaults</h2>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead>
            <tr><th>Role</th><th>Compliance</th><th>Audit</th><th>Policy</th><th>Risk</th><th>Admin</th></tr>
          </thead>
          <tbody>
            <tr><td><span class="docs-role admin">Admin</span></td><td>R W E</td><td>R W E</td><td>R W E</td><td>R W E</td><td>Full</td></tr>
            <tr><td><span class="docs-role manager">Manager</span></td><td>R W E</td><td>R W E</td><td>R W E</td><td>R W E</td><td>—</td></tr>
            <tr><td><span class="docs-role auditor">Auditor</span></td><td>R</td><td>R W E</td><td>R</td><td>R</td><td>—</td></tr>
            <tr><td><span class="docs-role analyst">Analyst</span></td><td>R</td><td>R</td><td>R</td><td>R W E</td><td>—</td></tr>
            <tr><td><span class="docs-role viewer">Viewer</span></td><td>R</td><td>R</td><td>R</td><td>R</td><td>—</td></tr>
          </tbody>
        </table>
      </div>
      <p class="docs-caption"><strong>R</strong> = Read &nbsp;|&nbsp; <strong>W</strong> = Write (create) &nbsp;|&nbsp; <strong>E</strong> = Edit/Delete</p>

      <h2 class="docs-h2">Explicit Permission Grants</h2>
      <p>Administrators can grant any combination of read/write/edit permissions for any module to any non-admin user. These grants are stored in <code>user_permissions</code> and are checked after role defaults. This allows, for example, giving a Viewer write access to the Risk module without changing their overall role.</p>
      <p>Navigate to <strong>Administration → Permissions</strong> to manage the permission matrix.</p>

      <div class="docs-callout docs-callout-info">
        <i class="bi bi-info-circle-fill"></i>
        <div>Permission checks are cached per HTTP request in memory, so there is no N+1 query cost for multiple <code>Auth::can()</code> calls within a single page load.</div>
      </div>
    </section>

    <!-- ════════════════════════════════════════════════════ 5. COMPLIANCE -->
    <section id="compliance" class="docs-section">
      <h1 class="docs-h1">Compliance Module</h1>
      <p>The compliance module tracks your organization's implementation status for controls across one or more regulatory standards. The data model has three layers: <strong>Standards → Packages → Objectives → Implementations</strong>.</p>

      <div class="docs-callout docs-callout-info">
        <i class="bi bi-info-circle-fill"></i>
        <div><strong>Three built-in standards are pre-loaded:</strong> CMMC 2.0 Level 2 (110 practices), ISO/IEC 27001:2022 Annex A (93 controls), and ISO/IEC 42001:2023 (AI management system requirements). Custom standards can be imported via JSON.</div>
      </div>

      <h2 id="s-packages" class="docs-h2">Compliance Packages</h2>
      <p>A <strong>Compliance Package</strong> is a versioned instance of a standard. Each package contains a hierarchy of <strong>Objectives</strong> (also called controls). Navigate to <strong>Compliance → Packages</strong> to see all active packages. Each package card shows:</p>
      <ul class="docs-list">
        <li>Standard name and code (e.g., ISO-27001)</li>
        <li>Total control count and a four-segment progress bar (Compliant / Partial / Non-Compliant / Not Started)</li>
        <li>Overall compliance percentage</li>
      </ul>
      <p>Click any package card to open the full control tree.</p>

      <h2 id="s-objectives" class="docs-h2">Control Objectives</h2>
      <p>Controls are organised in a two-level tree:</p>
      <ul class="docs-list">
        <li><strong>Level 1 — Domain / Theme / Clause</strong>: grouping header (e.g., "8. Technological Controls" in ISO 27001).</li>
        <li><strong>Level 2 — Individual Control</strong>: the assessable control that receives a status. This is where implementation notes, evidence, and assignees live.</li>
      </ul>
      <p>On the package page, domains are listed as collapsible rows. The compliance counts and bar for each domain reflect only its Level 2 children. Click a control row to open its detail page.</p>

      <p>The <strong>Filter bar</strong> on the package page lets you filter controls by status (All / Compliant / Partial / Non-Compliant / Not Started / Not Applicable) or by keyword search across code and title.</p>

      <h2 id="s-status" class="docs-h2">Updating Control Status</h2>
      <p>On the control detail page (<code>/compliance/{pkgId}/objective/{objId}</code>) you will find the <strong>Implementation Panel</strong> with these fields:</p>

      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Field</th><th>Description</th><th>Required</th></tr></thead>
          <tbody>
            <tr><td>Status</td><td>One of: Not Started, Compliant, Partial, Non-Compliant, Not Applicable</td><td>Yes</td></tr>
            <tr><td>Implementation Notes</td><td>Free-text description of how the control is satisfied</td><td>No</td></tr>
            <tr><td>Evidence</td><td>Links, document references, or evidence descriptions</td><td>No</td></tr>
            <tr><td>Assigned To</td><td>User responsible for implementing this control</td><td>No</td></tr>
            <tr><td>Due Date</td><td>Target date for completing the implementation</td><td>No</td></tr>
          </tbody>
        </table>
      </div>

      <p>Saving creates or updates a <code>control_implementations</code> row. The change is logged to the activity log. The reviewer is automatically set to the currently logged-in user and the <code>last_reviewed</code> timestamp is updated.</p>

      <div class="docs-callout docs-callout-warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>Requires <strong>compliance.write</strong> permission. Viewers can view control detail but cannot update it.</div>
      </div>

      <p>The control detail page also shows:</p>
      <ul class="docs-list">
        <li><strong>Mapped Policies</strong> — policies that have been linked to this control.</li>
        <li><strong>Audit Findings</strong> — the 5 most recent audit item results for this control across all audits.</li>
        <li><strong>Sub-objectives</strong> — if this is a Level 1 domain, its Level 2 children are listed with their current status.</li>
      </ul>

      <h2 id="s-import" class="docs-h2">Importing Custom Standards</h2>
      <p>Navigate to <strong>Compliance → Import Standard</strong>. You can upload a JSON file conforming to the AEGIS import schema:</p>

      <pre class="docs-code">{
  "name": "My Custom Framework v1.0",
  "version": "1.0",
  "description": "Internal security baseline",
  "objectives": [
    {
      "code": "CC-1",
      "title": "Access Control Policy",
      "category": "Access Control",
      "description": "Establish a formal access control policy."
    },
    {
      "code": "CC-2",
      "title": "Privileged Access Management",
      "category": "Access Control"
    }
  ]
}</pre>

      <p>You can also select an existing standard to associate the package with, or leave it blank to create an orphan package. The system validates the JSON structure and file size (max 5 MB). MIME type is verified against file content (not the browser-supplied type header).</p>

      <p>All imported objectives are created as Level 1 controls. To create a two-level hierarchy, the JSON importer currently creates flat Level 1 entries. Use the built-in standards (CMMC, ISO 27001, ISO 42001) to see a two-level tree in action.</p>
    </section>

    <!-- ════════════════════════════════════════════════════ 6. AUDITS -->
    <section id="audits" class="docs-section">
      <h1 class="docs-h1">Audit Module</h1>
      <p>Audits are structured assessments of a compliance package. Each audit consists of a header record and a set of <strong>Audit Items</strong> — one per control in the selected package.</p>

      <h2 id="a-create" class="docs-h2">Creating an Audit</h2>
      <p>Navigate to <strong>Audits → Create Audit</strong>. Complete the form:</p>

      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Field</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td>Name</td><td>A descriptive name for the audit (e.g., "Q2 2025 ISO 27001 Internal Review")</td></tr>
            <tr><td>Audit Type</td><td>Internal, External, Gap Assessment, Surveillance, or Certification</td></tr>
            <tr><td>Compliance Package</td><td>The package this audit will assess — determines which controls appear as items</td></tr>
            <tr><td>Auditor</td><td>The user responsible for conducting the audit</td></tr>
            <tr><td>Scheduled Date</td><td>The planned start date</td></tr>
            <tr><td>Frequency</td><td>How often this audit recurs (Annual, Quarterly, Monthly, Ad-hoc)</td></tr>
            <tr><td>Description / Notes</td><td>Optional context, scope statement, or pre-audit notes</td></tr>
          </tbody>
        </table>
      </div>

      <p>On creation, audit items are automatically generated for every Level 2 control in the selected package, each with status <code>not_assessed</code>.</p>

      <h2 id="a-conduct" class="docs-h2">Conducting an Audit</h2>
      <p>Open an audit from the list view. The detail page shows the audit header, a score gauge (updates as items are assessed), and a table of all audit items. For each item you can record:</p>

      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Field</th><th>Options / Format</th></tr></thead>
          <tbody>
            <tr><td>Status</td><td>Not Assessed, Compliant, Finding, Not Applicable</td></tr>
            <tr><td>Finding</td><td>Free text — describe the gap or non-conformance</td></tr>
            <tr><td>Evidence</td><td>Reference to artefacts or documentation reviewed</td></tr>
            <tr><td>Risk Level</td><td>Low, Medium, High, Critical</td></tr>
            <tr><td>Remediation</td><td>Recommended corrective action</td></tr>
            <tr><td>Remediation Due</td><td>Target date for remediation</td></tr>
            <tr><td>Notes</td><td>Internal auditor notes</td></tr>
          </tbody>
        </table>
      </div>

      <p>Each item is saved individually. There is no draft mode — changes are written to the database immediately when you submit an item's row form.</p>

      <h2 id="a-score" class="docs-h2">Scores &amp; Completion</h2>
      <p>The audit score is calculated as:</p>
      <pre class="docs-code">score = (compliant_items / (total_items - not_applicable_items)) × 100</pre>
      <p>This is stored on the <code>audits</code> row and displayed as a percentage. To mark the audit as complete, click the <strong>Complete Audit</strong> button at the top of the detail page. This sets <code>status = 'completed'</code>, records <code>completed_date = today</code>, and locks further editing.</p>

      <div class="docs-callout docs-callout-info">
        <i class="bi bi-info-circle-fill"></i>
        <div>Completed audit scores feed into the <strong>Audit Score Trend</strong> line chart on the Metrics page and the <strong>Audit Performance by Type</strong> table.</div>
      </div>
    </section>

    <!-- ════════════════════════════════════════════════════ 7. POLICIES -->
    <section id="policies" class="docs-section">
      <h1 class="docs-h1">Policy Module</h1>
      <p>Policies are formal documents that govern your organization's information security practices. AEGIS tracks policies through a lifecycle from draft to publication, with version history and review scheduling.</p>

      <h2 id="p-lifecycle" class="docs-h2">Policy Lifecycle</h2>
      <p>Each policy moves through the following statuses:</p>

      <div class="docs-flow">
        <div class="docs-flow-step">Draft</div>
        <i class="bi bi-arrow-right"></i>
        <div class="docs-flow-step">Review</div>
        <i class="bi bi-arrow-right"></i>
        <div class="docs-flow-step">Approved</div>
        <i class="bi bi-arrow-right"></i>
        <div class="docs-flow-step docs-flow-active">Published</div>
        <i class="bi bi-arrow-right"></i>
        <div class="docs-flow-step docs-flow-retire">Retired</div>
      </div>

      <div class="docs-table-wrap" style="margin-top:16px">
        <table class="docs-table">
          <thead><tr><th>Status</th><th>Meaning</th><th>Who can set</th></tr></thead>
          <tbody>
            <tr><td><code>draft</code></td><td>Policy is being written; not yet in review</td><td>policy.write</td></tr>
            <tr><td><code>review</code></td><td>Policy submitted for approval; read-only to most users</td><td>policy.write</td></tr>
            <tr><td><code>approved</code></td><td>Approved by the designated approver; ready to publish</td><td>policy.edit (or approver)</td></tr>
            <tr><td><code>published</code></td><td>Active, enforced policy; appears in published count KPI</td><td>policy.edit</td></tr>
            <tr><td><code>retired</code></td><td>Superseded or withdrawn; no longer active</td><td>policy.edit</td></tr>
          </tbody>
        </table>
      </div>

      <p>When creating a policy you supply:</p>
      <ul class="docs-list">
        <li><strong>Title</strong> and <strong>Policy Number</strong> (e.g., <code>POL-001</code>)</li>
        <li><strong>Category</strong> (e.g., Access Control, Data Protection, Incident Management)</li>
        <li><strong>Version</strong> (e.g., <code>1.0</code>)</li>
        <li><strong>Owner</strong> and <strong>Approver</strong> — must be active users</li>
        <li><strong>Review Frequency</strong> — Annual, Bi-annual, Quarterly</li>
        <li><strong>Content</strong> — the full policy text</li>
      </ul>

      <h2 id="p-mapping" class="docs-h2">Mapping Policies to Controls</h2>
      <p>On the policy detail page, use the <strong>Map Control</strong> panel to link this policy to one or more compliance objectives. Select a package, then select the specific control. This creates a <code>policy_mappings</code> record.</p>

      <p>The reverse linkage is also visible: on the control detail page, a <strong>Mapped Policies</strong> section lists all policies that reference that control. This bidirectional traceability is critical for demonstrating control coverage during audits.</p>

      <p>Remove a mapping using the Unmap button (×) on the policy detail page. Requires <strong>policy.write</strong>.</p>
    </section>

    <!-- ════════════════════════════════════════════════════ 8. RISK -->
    <section id="risk" class="docs-section">
      <h1 class="docs-h1">Risk Register</h1>
      <p>The risk register is the central repository for all identified risks. Each risk is scored using a <strong>likelihood × impact</strong> model on a configurable scale (default: 1–5).</p>

      <h2 id="r-scoring" class="docs-h2">Scoring Model</h2>
      <p>Two scores are maintained per risk:</p>
      <ul class="docs-list">
        <li><strong>Inherent Score</strong> = Likelihood × Impact (the raw risk before any controls)</li>
        <li><strong>Residual Score</strong> = Residual Likelihood × Residual Impact (risk after controls are applied)</li>
      </ul>
      <p>Both scores are integers computed in PHP when a risk is created or updated. The default matrix maps scores to levels:</p>

      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Level</th><th>Score Range (5×5)</th><th>Default Colour</th></tr></thead>
          <tbody>
            <tr><td><span class="risk-badge risk-low">Low</span></td><td>1–4</td><td>#22c55e</td></tr>
            <tr><td><span class="risk-badge risk-medium">Medium</span></td><td>5–9</td><td>#f59e0b</td></tr>
            <tr><td><span class="risk-badge risk-high">High</span></td><td>10–14</td><td>#f97316</td></tr>
            <tr><td><span class="risk-badge risk-critical">Critical</span></td><td>15–25</td><td>#ef4444</td></tr>
          </tbody>
        </table>
      </div>

      <p>Thresholds are configurable via <strong>Administration → Risk Matrix Config</strong>.</p>

      <h2 id="r-treatment" class="docs-h2">Risk Treatment</h2>
      <p>Every open risk should have a treatment type selected:</p>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Type</th><th>Meaning</th></tr></thead>
          <tbody>
            <tr><td>Mitigate</td><td>Implement controls to reduce likelihood or impact</td></tr>
            <tr><td>Accept</td><td>Acknowledge the risk and accept residual exposure within tolerance</td></tr>
            <tr><td>Transfer</td><td>Shift the risk (e.g., insurance, third-party contracts)</td></tr>
            <tr><td>Avoid</td><td>Eliminate the activity or asset that creates the risk</td></tr>
          </tbody>
        </table>
      </div>

      <p>Document the treatment in the <strong>Treatment Description</strong> field and set a <strong>Review Date</strong>. Risks with past review dates appear in the <strong>Overdue</strong> bucket on the Dashboard due-items widget.</p>

      <h2 id="r-matrix" class="docs-h2">Risk Matrix</h2>
      <p>Navigate to <strong>Risk → Risk Matrix</strong> to see all open risks plotted on the configurable heatmap. Cells are coloured by their risk level. Each cell shows the count of risks at that likelihood/impact intersection.</p>

      <p>The matrix can be reconfigured in <strong>Administration → Risk Matrix Config</strong>. You can change:</p>
      <ul class="docs-list">
        <li>Matrix dimensions (row/column count)</li>
        <li>Row labels (Likelihood axis) and Column labels (Impact axis)</li>
        <li>Score thresholds that define Low/Medium/High/Critical boundaries</li>
        <li>Colours for each risk level</li>
      </ul>

      <div class="docs-callout docs-callout-warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>Changing the matrix dimensions affects how existing risk scores are displayed on the matrix. Risk scores (likelihood × impact) are stored as raw integers and are recalculated against the new thresholds at display time.</div>
      </div>
    </section>

    <!-- ════════════════════════════════════════════════════ 9. METRICS -->
    <section id="metrics" class="docs-section">
      <h1 class="docs-h1">Metrics</h1>
      <p>The Metrics page (<code>/metrics</code>) provides an at-a-glance view of your GRC programme health. It requires no configuration — all data is derived from existing records in real time.</p>

      <h2 class="docs-h2">KPI Cards</h2>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>KPI</th><th>Calculation</th></tr></thead>
          <tbody>
            <tr><td>Overall Compliance %</td><td>Compliant control implementations ÷ total Level-2 objectives × 100</td></tr>
            <tr><td>Open Risks</td><td>COUNT of risks WHERE status = 'open'; sub-label shows critical count (score &gt; 14)</td></tr>
            <tr><td>Audit Completion %</td><td>Completed audits ÷ total audits × 100</td></tr>
            <tr><td>Published Policies</td><td>COUNT of policies WHERE status = 'published'; sub-label shows total policies</td></tr>
          </tbody>
        </table>
      </div>

      <h2 class="docs-h2">Charts</h2>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Chart</th><th>Type</th><th>Data Source</th></tr></thead>
          <tbody>
            <tr><td>Compliance by Package</td><td>Stacked bar</td><td>Per-package counts: compliant / partial / non-compliant / not-started</td></tr>
            <tr><td>Control Status</td><td>Doughnut</td><td>All Level-2 control implementations grouped by status</td></tr>
            <tr><td>Risk by Category</td><td>Horizontal stacked bar</td><td>Open risks grouped by category and score band (Critical/High/Medium/Low)</td></tr>
            <tr><td>Risk Intake Trend</td><td>Multi-line</td><td>Monthly new risk count (total + critical) over last 12 months</td></tr>
            <tr><td>Audit Score Trend</td><td>Line with fill</td><td>Average completed audit score per month over last 12 months</td></tr>
          </tbody>
        </table>
      </div>

      <h2 class="docs-h2">Data Tables</h2>
      <ul class="docs-list">
        <li><strong>Top Open Risks</strong> — the 5 highest-scoring open risks with inherent and residual scores.</li>
        <li><strong>Audit Performance by Type</strong> — total, completed, and average score for each audit type.</li>
      </ul>

      <p>All chart data is rendered server-side as JSON into <code>const</code> variables and consumed by Chart.js 4.4.3 on the client side. Charts are fully responsive.</p>
    </section>

    <!-- ════════════════════════════════════════════════════ 10. EXPORT -->
    <section id="export" class="docs-section">
      <h1 class="docs-h1">Export Module</h1>
      <p>The export module (<code>/export</code>) lets you download structured data from any part of the platform in <strong>CSV</strong> or <strong>XLSX</strong> format, or pull everything in a single ZIP file.</p>

      <h2 class="docs-h2">Available Exports</h2>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Module</th><th>Data Included</th></tr></thead>
          <tbody>
            <tr><td>Risks</td><td>Risk ID, title, description, category, likelihood, impact, inherent/residual scores, status, treatment, owner, review date</td></tr>
            <tr><td>Standards</td><td>Standard code, name, version, category, authority, package name, compliance counts per status</td></tr>
            <tr><td>Audits</td><td>Audit name, type, status, frequency, package, dates, auditor, score, item counts, notes</td></tr>
            <tr><td>Policies</td><td>Policy number, title, category, version, status, owner, approver, review frequency, approval/publish dates, mapped control count</td></tr>
            <tr><td>Controls</td><td>Package, control code, title, category, level, implementation status, notes, evidence, assignee, due date, last reviewed</td></tr>
            <tr><td>Evidence</td><td>Controls that have evidence recorded — package, code, title, status, notes, evidence text, last reviewed, reviewer</td></tr>
          </tbody>
        </table>
      </div>

      <h2 class="docs-h2">CSV vs XLSX</h2>
      <ul class="docs-list">
        <li><strong>CSV</strong> — plain text, universally compatible, opens in any spreadsheet or text editor. Values that begin with <code>=</code>, <code>+</code>, <code>-</code>, <code>@</code>, tab, or carriage return are prefixed with a single quote to prevent spreadsheet formula injection.</li>
        <li><strong>XLSX</strong> — native Excel format, generated as a pure-PHP ZipArchive (no external library). Numeric columns are typed as numbers; all others as shared strings. Compatible with Excel, LibreOffice Calc, and Google Sheets.</li>
      </ul>

      <h2 class="docs-h2">Full Export (ZIP)</h2>
      <p>The <strong>Export All</strong> button generates a ZIP archive containing one CSV file per module, named <code>aegis_{module}.csv</code>. The ZIP is streamed directly to the browser without being stored on disk beyond a temporary file that is deleted immediately after transfer.</p>

      <div class="docs-callout docs-callout-info">
        <i class="bi bi-info-circle-fill"></i>
        <div>All exports require <strong>compliance.read</strong> permission. The export does not filter by user — it exports all records regardless of ownership.</div>
      </div>
    </section>

    <!-- ════════════════════════════════════════════════════ 11. DASHBOARD -->
    <section id="dashboard" class="docs-section">
      <h1 class="docs-h1">Dashboard</h1>
      <p>The Dashboard (<code>/</code>) is the first page shown after login. It combines high-level KPIs with actionable due-item tracking.</p>

      <h2 class="docs-h2">KPI Cards</h2>
      <p>Four cards across the top row show the same overall metrics as the Metrics page: compliance %, open risks, audit completion, published policies. Each card links to its respective module.</p>

      <h2 class="docs-h2">Due Items Widget</h2>
      <p>The due-items widget is divided into four tabs:</p>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Tab</th><th>Definition</th><th>Sources</th></tr></thead>
          <tbody>
            <tr><td>Overdue</td><td>Past due date, no completion</td><td>Risks (review_date), Policies (next_review_date), Audits (scheduled_date, status ≠ completed), Controls (due_date)</td></tr>
            <tr><td>Due in 7 Days</td><td>Due within the next 7 calendar days</td><td>Same sources</td></tr>
            <tr><td>Due in 30 Days</td><td>Due within the next 8–30 calendar days</td><td>Same sources</td></tr>
            <tr><td>Expired</td><td>Past their expiry without explicit closure</td><td>API keys (expires_at), Policies (next_review_date significantly overdue)</td></tr>
          </tbody>
        </table>
      </div>
      <p>Each item in the widget shows a type badge, the item name, and the due/expired date. Clicking the item name navigates directly to that record.</p>

      <h2 class="docs-h2">Recent Activity Log</h2>
      <p>Below the due-items widget, the dashboard shows the 20 most recent entries from the activity log — actions taken by any user. Each entry shows the action, entity affected, user, and timestamp.</p>
    </section>

    <!-- ════════════════════════════════════════════════════ 12. ADMIN -->
    <section id="admin" class="docs-section">
      <h1 class="docs-h1">Administration</h1>
      <p>All administration pages are accessible only to users with the <strong>admin</strong> role. The Administration section is hidden from the sidebar for non-admin users.</p>

      <h2 id="adm-users" class="docs-h2">User Management</h2>
      <p>Navigate to <strong>Administration → Users</strong>. The user list shows all accounts with their role, department, last login, and active status.</p>

      <p>To <strong>create a user</strong>, click the <strong>Add User</strong> button and complete the form. Passwords must satisfy the policy: minimum 12 characters, at least one uppercase letter, one number, and one special character.</p>

      <p>To <strong>edit a user</strong>, click the edit icon in their row. You can change name, role, department, job title, and active status. You can also set a new password — if left blank, the existing password is unchanged.</p>

      <p>To <strong>deactivate a user</strong>, toggle the <em>Active</em> checkbox in the edit form. Deactivated users cannot log in. Their historical data (audit records, risk ownership, etc.) is preserved.</p>

      <div class="docs-callout docs-callout-warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>You cannot deactivate your own account or delete the last admin user.</div>
      </div>

      <h2 id="adm-perms" class="docs-h2">Permissions Matrix</h2>
      <p>Navigate to <strong>Administration → Permissions</strong>. This page shows a sticky-column table with every non-admin user as a row and every module/permission combination (Compliance R/W/E, Audit R/W/E, Policy R/W/E, Risk R/W/E) as columns.</p>

      <ul class="docs-list">
        <li><strong>Green cells</strong> — permission is granted by the user's role default. Checking or unchecking these will create explicit DB grants that can override defaults.</li>
        <li><strong>Blue cells</strong> — permission has been explicitly granted in the DB (overriding or extending role defaults).</li>
        <li><strong>Amber header row</strong> — a row is highlighted amber when it has unsaved changes (client-side only until you click Save).</li>
      </ul>

      <p>Each user's row has its own Save button. Changes are submitted as a form array (<code>permissions[]</code>), processed server-side against a whitelist, and written to <code>user_permissions</code>. Only checked permissions are stored; unchecked permissions rely on role defaults.</p>

      <h2 id="adm-matrix" class="docs-h2">Risk Matrix Configuration</h2>
      <p>Navigate to <strong>Administration → Risk Matrix</strong>. You can configure:</p>
      <ul class="docs-list">
        <li><strong>Dimensions</strong> — row (Likelihood) and column (Impact) axis sizes. Default is 5×5.</li>
        <li><strong>Labels</strong> — comma-separated labels for each row and column.</li>
        <li><strong>Thresholds</strong> — score boundaries that separate Low/Medium/High/Critical.</li>
        <li><strong>Colours</strong> — hex colour for each risk level, used in risk badges and the matrix heatmap.</li>
      </ul>

      <h2 id="adm-workflows" class="docs-h2">Workflows</h2>
      <p>Workflows define automated actions triggered by GRC events. Navigate to <strong>Administration → Workflows</strong>.</p>

      <p>A workflow has:</p>
      <ul class="docs-list">
        <li><strong>Trigger type</strong> — the event that fires the workflow (e.g., <code>risk_created</code>, <code>audit_completed</code>, <code>policy_approved</code>).</li>
        <li><strong>Trigger config</strong> — JSON configuration for the trigger (e.g., minimum risk score).</li>
        <li><strong>Actions</strong> — JSON array of actions to execute (e.g., create alert, send notification).</li>
      </ul>

      <p>Workflows can be toggled active/inactive without deletion. The workflow engine runs at the point of the triggering event in the relevant controller.</p>

      <h2 id="adm-alerts" class="docs-h2">Alerts</h2>
      <p>Navigate to <strong>Administration → Alerts</strong>. This page has two sections:</p>
      <ul class="docs-list">
        <li><strong>Alert Configurations</strong> — rules that govern when system alerts are generated (e.g., "create a critical alert when a new risk with score &gt; 14 is created").</li>
        <li><strong>Recent Alerts</strong> — the last 50 alerts delivered to any user, with type, severity, recipient, and read status.</li>
      </ul>

      <p>Users see their own alerts in the notification bell panel in the topbar. Alerts can be marked as read individually from the panel. Alert badges auto-update on each page load.</p>

      <h2 id="adm-apikeys" class="docs-h2">API Keys</h2>
      <p>Navigate to <strong>Administration → API Keys</strong>. API keys allow programmatic access to the REST API without session-based authentication.</p>

      <p>To create a key:</p>
      <ol class="docs-list">
        <li>Click <strong>Create API Key</strong>.</li>
        <li>Select the user the key acts on behalf of.</li>
        <li>Enter a descriptive name (e.g., "SIEM integration - readonly").</li>
        <li>Select permissions: <code>read</code>, <code>write</code>, or <code>admin</code>.</li>
        <li>Optionally set an expiry date.</li>
        <li>Click Create. <strong>The full key is shown only once — copy it immediately.</strong></li>
      </ol>

      <p>Only a SHA-256 hash of the key is stored. The key prefix (<code>aegis_xxxx</code>) is stored for identification. Expired or revoked keys return 401.</p>

      <div class="docs-callout docs-callout-warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>If you lose the key value, you must revoke it and create a new one. There is no way to recover the original key from the stored hash.</div>
      </div>

      <h2 id="adm-logs" class="docs-h2">Activity Logs</h2>
      <p>Navigate to <strong>Administration → Activity Logs</strong> (<code>/admin/logs</code>) for a complete, immutable record of every action taken in AEGIS.</p>

      <h3 class="docs-h3">What is Logged</h3>
      <p>Every significant state change writes a row to the <code>activity_log</code> table including: the acting user, the action name, the entity type and ID affected, a JSON diff of changed values, the client IP address, and a precise timestamp.</p>

      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Action</th><th>Triggered by</th></tr></thead>
          <tbody>
            <tr><td>login / logout</td><td>User authentication events</td></tr>
            <tr><td>create_user / update_user / delete_user</td><td>Admin user management</td></tr>
            <tr><td>update_control</td><td>Saving a control implementation status</td></tr>
            <tr><td>import_package</td><td>Uploading a JSON compliance package</td></tr>
            <tr><td>create_api_key / revoke_api_key</td><td>API key lifecycle</td></tr>
            <tr><td>create_workflow / toggle_workflow</td><td>Workflow management</td></tr>
            <tr><td>update_risk_matrix</td><td>Risk matrix configuration changes</td></tr>
            <tr><td>update_permissions</td><td>Per-user permission grant changes</td></tr>
          </tbody>
        </table>
      </div>

      <h3 class="docs-h3">Filtering</h3>
      <p>Use the filter panel to narrow down logs by: user, action type, entity type, IP address, and date range. Active filters are shown as a badge on the filter toggle. All filters can be cleared with the Clear button. Filtered URLs are bookmarkable.</p>

      <h3 class="docs-h3">Change Details</h3>
      <p>Rows with a change payload show a <strong>Show details</strong> toggle. Clicking expands an inline panel showing the JSON change data as a formatted key/value table — useful for auditing exactly what changed on a record.</p>

      <h3 class="docs-h3">Export</h3>
      <p>Click <strong>Export CSV</strong> to download the complete, unfiltered activity log as a CSV file. The file includes all columns including the JSON changes field. Formula injection is prevented on all exported values.</p>

      <h3 class="docs-h3">Auto-Refresh</h3>
      <p>Enable the <strong>Auto-refresh</strong> toggle in the page header to have the log reload every 30 seconds — useful when monitoring live activity during an incident or deployment.</p>

      <h3 class="docs-h3">Statistics Panels</h3>
      <p>Below the main log table, two panels provide aggregate visibility:</p>
      <ul class="docs-list">
        <li><strong>Most Active Users (30d)</strong> — top 5 users by action count in the last 30 days, with a proportional bar.</li>
        <li><strong>Action Breakdown</strong> — top 10 actions across all time, with counts and proportional bars coloured by action type.</li>
      </ul>
    </section>

    <!-- ════════════════════════════════════════════════════ 13. API -->
    <section id="api" class="docs-section">
      <h1 class="docs-h1">REST API</h1>
      <p>AEGIS exposes a REST API at <code>/api/v1/</code>. All responses are JSON with envelope format:</p>
      <pre class="docs-code">// Success
{ "success": true, "data": { ... }, "meta": { "timestamp": "...", "version": "v1" } }

// Error
{ "success": false, "error": "message", "meta": { "timestamp": "..." } }</pre>

      <h2 id="api-auth" class="docs-h2">Authentication</h2>
      <p>Two authentication methods are supported:</p>

      <h3 class="docs-h3">API Key</h3>
      <pre class="docs-code">GET /api/v1/risks
X-API-Key: aegis_your_api_key_here</pre>

      <h3 class="docs-h3">JWT Bearer Token</h3>
      <p>First obtain a token:</p>
      <pre class="docs-code">POST /api/v1/auth/token
Content-Type: application/json

{ "email": "user@example.com", "password": "yourpassword" }

// Response
{ "token": "eyJ...", "expires_in": 86400, "user": { "id": 1, "name": "...", "role": "..." } }</pre>

      <p>Then use it in subsequent requests:</p>
      <pre class="docs-code">GET /api/v1/risks
Authorization: Bearer eyJ...</pre>

      <div class="docs-callout docs-callout-info">
        <i class="bi bi-info-circle-fill"></i>
        <div>JWT tokens expire after <strong>24 hours</strong>. All tokens must contain an <code>exp</code> claim — tokens without expiry are rejected. The algorithm is HS256.</div>
      </div>

      <h3 class="docs-h3">Rate Limiting</h3>
      <p>The API is rate-limited to <strong>60 requests per minute per IP address</strong>. Exceeding the limit returns HTTP 429. The window resets every 60 seconds.</p>

      <h2 id="api-endpoints" class="docs-h2">Endpoints</h2>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Method</th><th>Endpoint</th><th>Auth Required</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td><span class="api-badge post">POST</span></td><td><code>/auth/token</code></td><td>None</td><td>Issue a JWT for email/password credentials</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/standards</code></td><td>Any</td><td>List all active standards</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/compliance/packages</code></td><td>Any</td><td>List active compliance packages with standard info</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/compliance/packages/{id}</code></td><td>Any</td><td>Get a single compliance package</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/compliance/packages/{id}/objectives</code></td><td>Any</td><td>List all objectives for a package with implementation status</td></tr>
            <tr><td><span class="api-badge put">PUT</span></td><td><code>/compliance/objectives/{id}/status</code></td><td>write</td><td>Update the implementation status of a single objective</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/risks</code></td><td>Any</td><td>List all risks ordered by inherent score descending</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/risks/{id}</code></td><td>Any</td><td>Get a single risk</td></tr>
            <tr><td><span class="api-badge post">POST</span></td><td><code>/risks</code></td><td>write</td><td>Create a new risk</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/policies</code></td><td>Any</td><td>List policies with owner information</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/audits</code></td><td>Any</td><td>List audits with package information</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/dashboard/stats</code></td><td>Any</td><td>Aggregate KPI counts for external dashboard integration</td></tr>
            <tr><td><span class="api-badge get">GET</span></td><td><code>/users</code></td><td>admin</td><td>List all users (admin API key or admin JWT only)</td></tr>
          </tbody>
        </table>
      </div>

      <h3 class="docs-h3">Creating a Risk via API</h3>
      <pre class="docs-code">POST /api/v1/risks
X-API-Key: aegis_your_key
Content-Type: application/json

{
  "title": "Unpatched servers in production",
  "description": "Three web servers running EOL OS versions.",
  "likelihood": 4,
  "impact": 5,
  "owner_id": 2
}

// Response 201
{
  "success": true,
  "data": {
    "id": 42,
    "risk_id": "RSK-API-1716400000",
    "title": "Unpatched servers in production",
    "inherent_score": 20,
    ...
  }
}</pre>

      <h3 class="docs-h3">CORS</h3>
      <p>Cross-origin requests are allowed only from the origin matching the <code>APP_URL</code> environment variable. If <code>APP_URL</code> is not set, no CORS headers are emitted. To use the API from a browser on a different origin, set <code>APP_URL</code> to that origin's exact URL (including scheme and port).</p>
    </section>

    <!-- ════════════════════════════════════════════════════ 14. SECURITY -->
    <section id="security" class="docs-section">
      <h1 class="docs-h1">Security</h1>

      <h2 class="docs-h2">Authentication Security</h2>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Control</th><th>Implementation</th></tr></thead>
          <tbody>
            <tr><td>Password hashing</td><td>Argon2ID — 64 MB memory, 4 iterations, 2 threads</td></tr>
            <tr><td>Brute-force protection</td><td>5 failed attempts per 5-minute window per IP → 15-minute lockout. Tracked in <code>rate_limits</code> table.</td></tr>
            <tr><td>Session regeneration</td><td><code>session_regenerate_id(true)</code> called on every successful login — prevents session fixation</td></tr>
            <tr><td>Session timeout</td><td>8-hour inactivity timeout enforced in <code>Auth::requireAuth()</code></td></tr>
            <tr><td>Cookie hardening</td><td>HttpOnly, SameSite=Lax, Secure (HTTPS), StrictMode</td></tr>
          </tbody>
        </table>
      </div>

      <h2 class="docs-h2">Request Security</h2>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Control</th><th>Implementation</th></tr></thead>
          <tbody>
            <tr><td>CSRF protection</td><td>Session-bound token, 2-hour lifetime, <code>hash_equals</code> comparison on every POST. Token in meta tag for AJAX calls.</td></tr>
            <tr><td>Open redirect prevention</td><td>Post-login redirect validated against <code>/[a-zA-Z0-9/_?=&%.-]*</code> pattern</td></tr>
            <tr><td>SQL injection</td><td>All queries use PDO prepared statements with bound parameters. No raw interpolation in queries.</td></tr>
            <tr><td>XSS prevention</td><td>All output through <code>Security::h()</code> (htmlspecialchars ENT_QUOTES|ENT_HTML5). CSP header restricts inline scripts.</td></tr>
            <tr><td>Content-Security-Policy</td><td>Restricts scripts to self + cdn.jsdelivr.net; fonts to fonts.gstatic.com; blocks frame embedding</td></tr>
            <tr><td>HSTS</td><td>max-age=31536000; includeSubDomains — emitted when X-Forwarded-Proto = https</td></tr>
            <tr><td>install.php blocking</td><td>.htaccess <code>RewriteRule ^install\.php$ - [F,L]</code> returns 403 after deployment</td></tr>
          </tbody>
        </table>
      </div>

      <h2 class="docs-h2">Data Security</h2>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Control</th><th>Implementation</th></tr></thead>
          <tbody>
            <tr><td>Database isolation</td><td>All AEGIS tables live in the <code>aegis</code> PostgreSQL schema, isolated from other applications on the same database server</td></tr>
            <tr><td>API key storage</td><td>Only SHA-256 hash stored — original key shown once at creation, never recoverable</td></tr>
            <tr><td>JWT security</td><td>HS256 with secret from environment variable. Tokens without <code>exp</code> claim are rejected. <code>changeme</code> default removed — throws if unset.</td></tr>
            <tr><td>MIME validation</td><td>File uploads checked with <code>finfo</code> against actual file content, not client-supplied Content-Type</td></tr>
            <tr><td>CSV formula injection</td><td>Values starting with <code>=</code>, <code>+</code>, <code>-</code>, <code>@</code>, tab, or CR are prefixed with <code>'</code> before CSV output</td></tr>
            <tr><td>API key permissions</td><td>Permission values intersected against allowlist <code>['read','write','admin']</code> before storage</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ════════════════════════════════════════════════════ 15. GLOSSARY -->
    <section id="glossary" class="docs-section">
      <h1 class="docs-h1">Glossary</h1>
      <div class="docs-table-wrap">
        <table class="docs-table">
          <thead><tr><th>Term</th><th>Definition</th></tr></thead>
          <tbody>
            <tr><td>GRC</td><td>Governance, Risk &amp; Compliance — the discipline of managing an organization's overall governance, enterprise risk management, and regulatory compliance</td></tr>
            <tr><td>Standard</td><td>A regulatory or industry framework (e.g., ISO 27001, CMMC 2.0) that defines a set of controls</td></tr>
            <tr><td>Compliance Package</td><td>A versioned, activatable instance of a standard containing the full control set</td></tr>
            <tr><td>Control Objective</td><td>An individual control or requirement within a package. Level 1 = grouping; Level 2 = assessable control</td></tr>
            <tr><td>Control Implementation</td><td>The record that captures the status, notes, evidence, and assignee for a specific control objective</td></tr>
            <tr><td>Inherent Risk Score</td><td>The risk score before any controls are applied: Likelihood × Impact</td></tr>
            <tr><td>Residual Risk Score</td><td>The risk score after controls are applied: Residual Likelihood × Residual Impact</td></tr>
            <tr><td>Risk Treatment</td><td>The chosen response to a risk: Mitigate, Accept, Transfer, or Avoid</td></tr>
            <tr><td>Audit Item</td><td>A single control's assessment result within an audit (status, finding, evidence)</td></tr>
            <tr><td>Policy Mapping</td><td>A link between a policy document and a compliance control objective, establishing traceability</td></tr>
            <tr><td>Role Default</td><td>The set of permissions automatically granted to all users of a given role</td></tr>
            <tr><td>Explicit Grant</td><td>A permission stored in <code>user_permissions</code> that overrides or extends a user's role defaults</td></tr>
            <tr><td>CSRF Token</td><td>A session-bound secret included in every form and AJAX POST to prevent cross-site request forgery</td></tr>
            <tr><td>JWT</td><td>JSON Web Token — a signed, stateless bearer token used for API authentication</td></tr>
            <tr><td>API Key</td><td>A long-lived credential (hash stored) used to authenticate programmatic API access</td></tr>
            <tr><td>CMMC</td><td>Cybersecurity Maturity Model Certification — DoD requirement for defense contractors</td></tr>
            <tr><td>ISO 27001</td><td>International standard for information security management systems (ISMS)</td></tr>
            <tr><td>ISO 42001</td><td>International standard for artificial intelligence management systems (AIMS)</td></tr>
          </tbody>
        </table>
      </div>
    </section>

  </article>
</div>

<script>
(function() {
  const links = document.querySelectorAll('.docs-link');
  const sections = document.querySelectorAll('.docs-section, .docs-h2[id]');

  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const id = entry.target.id;
        links.forEach(l => l.classList.remove('active'));
        const active = document.querySelector('.docs-link[href="#' + id + '"]');
        if (active) active.classList.add('active');
      }
    });
  }, { rootMargin: '-20% 0px -70% 0px' });

  document.querySelectorAll('[id]').forEach(el => {
    if (el.closest('.docs-body')) observer.observe(el);
  });

  links.forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      const target = document.querySelector(link.getAttribute('href'));
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();
</script>

<style>
.docs-layout {
  display: grid;
  grid-template-columns: 240px 1fr;
  gap: 0;
  min-height: calc(100vh - 60px);
  align-items: start;
}
@media (max-width: 900px) {
  .docs-layout { grid-template-columns: 1fr; }
  .docs-nav { display: none; }
}

/* Sidebar nav */
.docs-nav {
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
  border-right: 1px solid var(--border);
  background: var(--sidebar-bg, var(--card-bg));
  scrollbar-width: thin;
}
.docs-nav-inner { padding: 20px 0 40px; }
.docs-nav-title {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .6px;
  color: var(--text-muted);
  padding: 0 16px 10px;
}
.docs-link {
  display: block;
  padding: 6px 16px;
  font-size: 13px;
  color: var(--text-muted);
  text-decoration: none;
  border-left: 2px solid transparent;
  transition: all .15s;
  line-height: 1.35;
}
.docs-link:hover { color: var(--text); background: var(--hover-bg, #f8fafc); }
.docs-link.active { color: var(--primary, #4f46e5); border-left-color: var(--primary, #4f46e5); font-weight: 500; background: var(--primary-bg, #eef2ff); }
.docs-link.docs-sub { padding-left: 28px; font-size: 12px; }

/* Body */
.docs-body {
  padding: 32px 48px 80px;
  max-width: 900px;
}
@media (max-width: 1100px) { .docs-body { padding: 24px 24px 60px; } }

/* Headings */
.docs-h1 {
  font-size: 28px;
  font-weight: 800;
  color: var(--text);
  margin: 0 0 16px;
  padding-bottom: 12px;
  border-bottom: 2px solid var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
}
.docs-h1 i { font-size: 24px; color: var(--primary, #4f46e5); }
.docs-h2 {
  font-size: 18px;
  font-weight: 700;
  color: var(--text);
  margin: 28px 0 12px;
}
.docs-h3 {
  font-size: 14px;
  font-weight: 600;
  color: var(--text);
  margin: 20px 0 8px;
}
.docs-lead {
  font-size: 15px;
  color: var(--text-secondary, #475569);
  line-height: 1.7;
  margin-bottom: 24px;
}

/* Section spacing */
.docs-section {
  padding-top: 12px;
  padding-bottom: 24px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 8px;
}
.docs-section:last-child { border-bottom: none; }

/* Prose */
.docs-body p {
  font-size: 14px;
  line-height: 1.75;
  color: var(--text-secondary, #475569);
  margin: 0 0 14px;
}
.docs-list {
  padding-left: 20px;
  margin: 0 0 16px;
}
.docs-list li {
  font-size: 14px;
  line-height: 1.7;
  color: var(--text-secondary, #475569);
  margin-bottom: 4px;
}
ol.docs-list { list-style-type: decimal; }
.docs-caption { font-size: 12px; color: var(--text-muted); margin-top: -8px; }

/* Code blocks */
.docs-code {
  background: #1e1e2e;
  color: #cdd6f4;
  padding: 16px 20px;
  border-radius: var(--radius, 8px);
  font-family: 'Fira Code', 'Consolas', monospace;
  font-size: 12.5px;
  line-height: 1.65;
  overflow-x: auto;
  margin: 0 0 16px;
  white-space: pre;
}
code {
  background: var(--code-bg, #f1f5f9);
  color: var(--primary, #4f46e5);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12.5px;
  font-family: monospace;
}

/* Tables */
.docs-table-wrap { overflow-x: auto; margin-bottom: 20px; }
.docs-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.docs-table th {
  background: var(--table-head-bg, #f8fafc);
  padding: 9px 12px;
  text-align: left;
  font-weight: 600;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .4px;
  color: var(--text-muted);
  border-bottom: 2px solid var(--border);
  white-space: nowrap;
}
.docs-table td {
  padding: 9px 12px;
  border-bottom: 1px solid var(--border);
  color: var(--text-secondary, #475569);
  vertical-align: top;
}
.docs-table tr:last-child td { border-bottom: none; }

/* Callouts */
.docs-callout {
  display: flex;
  gap: 12px;
  padding: 14px 16px;
  border-radius: var(--radius, 8px);
  margin-bottom: 20px;
  font-size: 13.5px;
  line-height: 1.6;
  align-items: flex-start;
}
.docs-callout i { font-size: 16px; flex-shrink: 0; margin-top: 2px; }
.docs-callout-info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.docs-callout-info i { color: #2563eb; }
.docs-callout-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }
.docs-callout-warning i { color: #d97706; }

/* Feature grid */
.docs-feature-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-bottom: 24px;
}
@media (max-width: 700px) { .docs-feature-grid { grid-template-columns: 1fr; } }
.docs-feature {
  display: flex;
  gap: 12px;
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius, 8px);
  background: var(--card-bg);
}
.docs-feature > i {
  font-size: 20px;
  color: var(--primary, #4f46e5);
  flex-shrink: 0;
  margin-top: 2px;
}
.docs-feature strong { display: block; font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
.docs-feature p { font-size: 12.5px; color: var(--text-muted); margin: 0; line-height: 1.5; }

/* Role badges */
.docs-role {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 99px;
  font-size: 11px;
  font-weight: 600;
}
.docs-role.admin   { background:#fef3c7;color:#92400e; }
.docs-role.manager { background:#ede9fe;color:#5b21b6; }
.docs-role.auditor { background:#dbeafe;color:#1e40af; }
.docs-role.analyst { background:#dcfce7;color:#166534; }
.docs-role.viewer  { background:#f1f5f9;color:#475569; }

/* API method badges */
.api-badge {
  display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;font-family:monospace;
}
.api-badge.get  { background:#dcfce7;color:#166534; }
.api-badge.post { background:#dbeafe;color:#1e40af; }
.api-badge.put  { background:#fef3c7;color:#92400e; }

/* Policy flow */
.docs-flow {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.docs-flow-step {
  padding: 6px 14px;
  border: 2px solid var(--border);
  border-radius: 99px;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-secondary, #475569);
  background: var(--card-bg);
}
.docs-flow-active { border-color: #22c55e; color: #166534; background: #dcfce7; }
.docs-flow-retire { border-color: #94a3b8; color: #64748b; background: #f1f5f9; }
.docs-flow i { color: var(--text-muted); font-size: 14px; }
</style>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
