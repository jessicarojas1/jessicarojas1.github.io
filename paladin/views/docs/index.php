<?php
$pageTitle    = 'Help & Docs';
$activeModule = 'docs';
$breadcrumbs  = [['Help & Docs', null]];
ob_start();

$roleDescriptions = [
    'admin'            => 'Full, unrestricted access to every module plus system settings, users, API keys and audit logs.',
    'pal_admin'        => 'Manages the whole content library — spaces, documents, processes and workflows — short of system settings.',
    'compliance_admin' => 'Governance, approvals and audit oversight across the library; cannot delete spaces or change system settings.',
    'space_owner'      => 'Full authority within the spaces they own, including membership and the content inside them.',
    'contributor'      => 'Authors pages and documents and submits them for review and approval.',
    'reviewer'         => 'Reviews and comments on content but cannot grant final approval.',
    'approver'         => 'Holds approval authority within workflows to advance or reject submissions.',
    'auditor'          => 'Read-only access across everything, with reports and export for evidence traceability.',
    'viewer'           => 'Consumes published content and records read receipts / acknowledgements.',
];

$modules = [
    ['bi-collection-fill',       'Spaces',                'Organize content into department, team, program, project, compliance and process areas with their own membership and access.'],
    ['bi-file-richtext-fill',    'Pages',                 'Rich, versioned wiki pages for living knowledge, nested in a hierarchy within each space.'],
    ['bi-file-earmark-text-fill','Documents',             'Controlled documents with revisions, classifications, check-in/out, acknowledgements and a full lifecycle.'],
    ['bi-diagram-3-fill',        'Processes',             'Documented business processes with owners, versions and diagrams.'],
    ['bi-diagram-2-fill',        'Workflows & Approvals', 'Configurable approval workflows (single, sequential, parallel, consensus) routing content through reviewers and approvers.'],
    ['bi-list-task',             'Tasks',                 'Action items, reviews and corrective actions assigned to people with priorities and due dates.'],
    ['bi-files',                 'Templates',             'A reusable template library for documents, pages and processes to standardize new content.'],
    ['bi-search',                'Search',                'Unified search across the entire library, with saved searches.'],
    ['bi-bar-chart-line-fill',   'Reports',               'Insights and exportable reports across documents, approvals and activity.'],
];

$lifecycle = [
    ['Draft',      'badge-gray',    'Work in progress, editable by contributors.'],
    ['In Review',  'badge-warning', 'Submitted and under review by reviewers.'],
    ['Approved',   'badge-info',    'Approved through the workflow, awaiting publication.'],
    ['Published',  'badge-green',   'Live and effective; the controlled, current revision.'],
    ['Archived',   'badge-gray',    'Withdrawn from active use but retained for the record.'],
    ['Obsolete',   'badge-gray',    'Superseded by a newer revision and no longer in force.'],
];
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Help &amp; Docs</h1>
    <p class="page-subtitle">Everything you need to know about the PALADIN.</p>
  </div>
</div>

<!-- About -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-info-circle"></i> About PALADIN</span></div></div>
  <div class="card-body">
    <div class="prose">
      <p><strong>PALADIN</strong> is the <strong>Process, Approval &amp; Library</strong> platform — a single, governed home for
      your organization's controlled documents, knowledge pages, business processes and the approval workflows that keep
      them trustworthy. PALADIN combines a structured content library with a configurable workflow engine, granular
      role-based access control, read receipts and an immutable audit trail, so you always know what the current,
      approved version of any document is and who signed off on it.</p>
    </div>
  </div>
</div>

<!-- Modules -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-grid-1x2"></i> Modules</span></div></div>
  <div class="card-body">
    <div class="meta-grid" style="grid-template-columns:1fr 1fr">
      <?php foreach ($modules as [$icon, $name, $desc]): ?>
      <div class="meta-item">
        <div class="meta-label"><i class="bi <?= $icon ?>"></i> <?= Security::h($name) ?></div>
        <div class="meta-value" style="font-weight:400;color:var(--text-muted)"><?= Security::h($desc) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Document lifecycle -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-arrow-left-right"></i> Document Lifecycle</span></div></div>
  <div class="card-body">
    <div class="prose">
      <p>Every controlled document moves through a defined lifecycle. Transitions are governed by permissions and,
      where configured, by approval workflows:</p>
    </div>
    <p style="margin:14px 0">
      <span class="badge badge-gray">Draft</span> <i class="bi bi-arrow-right"></i>
      <span class="badge badge-warning">In Review</span> <i class="bi bi-arrow-right"></i>
      <span class="badge badge-info">Approved</span> <i class="bi bi-arrow-right"></i>
      <span class="badge badge-green">Published</span> <i class="bi bi-arrow-right"></i>
      <span class="badge badge-gray">Archived</span> / <span class="badge badge-gray">Obsolete</span>
    </p>
    <div class="meta-grid" style="grid-template-columns:1fr 1fr">
      <?php foreach ($lifecycle as [$label, $cls, $desc]): ?>
      <div class="meta-item">
        <div class="meta-label"><span class="badge <?= $cls ?>"><?= Security::h($label) ?></span></div>
        <div class="meta-value" style="font-weight:400;color:var(--text-muted)"><?= Security::h($desc) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Roles -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-shield-lock"></i> Roles &amp; Access</span></div></div>
  <div class="card-body">
    <div class="prose">
      <p>PALADIN uses granular, role-based access control. Each role grants a sensible set of module &times; action defaults;
      administrators can additionally grant explicit per-user permissions that extend those defaults.</p>
    </div>
    <div class="meta-grid" style="grid-template-columns:1fr 1fr;margin-top:8px">
      <?php foreach (Auth::roleKeys() as $role): ?>
      <div class="meta-item">
        <div class="meta-label"><?= Security::h(Auth::roleLabel($role)) ?></div>
        <div class="meta-value" style="font-weight:400;color:var(--text-muted)"><?= Security::h($roleDescriptions[$role] ?? '') ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Acknowledgements & audit -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-patch-check"></i> Acknowledgements &amp; Audit Trail</span></div></div>
  <div class="card-body">
    <div class="prose">
      <p><strong>Read receipts &amp; acknowledgements.</strong> Documents can be flagged as requiring acknowledgement. When a
      document is published, assigned readers are prompted to confirm they have read and understood it. Each
      acknowledgement is recorded against the specific revision, giving you defensible evidence of who has read which
      version and when.</p>
      <p><strong>Immutable audit log.</strong> Every significant action — logins, edits, lifecycle transitions, approvals
      and acknowledgements — is written to a hash-chained activity log. Each entry includes the hash of the previous
      entry, so any tampering with historical records is detectable. The log is append-only and cannot be edited from
      within the application.</p>
    </div>
  </div>
</div>

<!-- REST API -->
<div class="card">
  <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-code-slash"></i> REST API</span></div></div>
  <div class="card-body">
    <div class="prose">
      <p>PALADIN exposes a read-oriented REST API under <code>GET /api/v1/*</code> for integrating the library with other
      systems. Requests authenticate with an API key passed as a <code>Bearer</code> token in the
      <code>Authorization</code> header. API keys are issued and revoked by administrators under
      <strong>Administration &rarr; API Keys</strong>.</p>
      <p>Interactive API documentation is available at <a href="/api/docs">/api/docs</a>.</p>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PALADIN_ROOT . '/views/layout.php';
