<?php
/**
 * views/approval/templates.php — Approval template list for admins.
 *
 * Variables provided by ApprovalController::templates():
 *   $templates  array  — rows from approval_templates
 */
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$entityTypeLabels = [
    'risk'     => 'Risk',
    'policy'   => 'Policy',
    'change'   => 'Change Request',
    'audit'    => 'Audit',
    'incident' => 'Incident',
    'vendor'   => 'Vendor',
];

// Entity type icon/color map for visual badges
$entityTypeMeta = [
    'risk'     => ['icon' => 'bi-shield-exclamation',  'color' => '#ef4444', 'bg' => '#fee2e2'],
    'policy'   => ['icon' => 'bi-file-text',           'color' => '#d97706', 'bg' => '#fef3c7'],
    'change'   => ['icon' => 'bi-arrow-repeat',        'color' => '#0284c7', 'bg' => '#dbeafe'],
    'audit'    => ['icon' => 'bi-clipboard-check',     'color' => '#6366f1', 'bg' => '#e0e7ff'],
    'incident' => ['icon' => 'bi-exclamation-triangle','color' => '#dc2626', 'bg' => '#fee2e2'],
    'vendor'   => ['icon' => 'bi-building',            'color' => '#059669', 'bg' => '#d1fae5'],
];

// KPI counts
$totalTemplates  = count($templates ?? []);
$activeTemplates = count(array_filter($templates ?? [], fn($t) => !empty($t['is_active'])));
$pendingCount    = 0; // placeholder — can be wired to controller data
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Approval Templates</h1>
    <p class="page-subtitle">Define multi-step approval chains for any entity type.</p>
  </div>
  <div class="page-actions">
    <a href="/admin/approval-templates/create" class="btn btn-primary">
      <i class="bi bi-plus-lg"></i> New Template
    </a>
    <a href="/admin" class="btn btn-ghost">
      <i class="bi bi-arrow-left"></i> Admin
    </a>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px">
    <i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?>
  </div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px">
    <i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?>
  </div>
<?php endif; ?>

<!-- KPI Stats Row -->
<div class="stats-row" style="margin-bottom:24px">
  <div class="stat-mini">
    <i class="bi bi-diagram-3" style="color:#6366f1"></i>
    <div>
      <div class="stat-mini-num"><?= $totalTemplates ?></div>
      <div style="font-size:11px;color:var(--text-muted)">Total Templates</div>
    </div>
  </div>
  <div class="stat-mini">
    <i class="bi bi-check-circle" style="color:#059669"></i>
    <div>
      <div class="stat-mini-num"><?= $activeTemplates ?></div>
      <div style="font-size:11px;color:var(--text-muted)">Active Templates</div>
    </div>
  </div>
  <div class="stat-mini">
    <i class="bi bi-hourglass-split" style="color:#d97706"></i>
    <div>
      <div class="stat-mini-num"><?= $pendingCount ?></div>
      <div style="font-size:11px;color:var(--text-muted)">Pending Requests</div>
    </div>
  </div>
</div>

<?php if (empty($templates)): ?>

  <!-- Empty state -->
  <div class="empty-state" style="margin-bottom:28px">
    <i class="bi bi-diagram-3"></i>
    <h3>No approval templates yet</h3>
    <p>Create your first template to start enforcing multi-step approval workflows.</p>
    <a href="/admin/approval-templates/create" class="btn btn-primary" style="margin-top:12px">
      <i class="bi bi-plus-lg"></i> New Template
    </a>
  </div>

  <!-- Quick-start template suggestions -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-lightning-fill"></i> Quick-Start Templates</h3>
      <span class="text-muted text-sm" style="margin-left:auto">Click to pre-fill a new template</span>
    </div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
        <?php
        $quickStart = [
          ['Risk Approval Chain',       'risk',     '2-step manager + CISO sign-off for new risks',        'bi-shield-exclamation'],
          ['Policy Sign-off Workflow',  'policy',   'Legal and compliance review before publishing',        'bi-file-text'],
          ['Change Request Approval',   'change',   'CAB-style multi-approver chain for change requests',   'bi-arrow-repeat'],
          ['Vendor Onboarding Review',  'vendor',   'Procurement and security sign-off for new vendors',    'bi-building'],
          ['Incident Escalation Path',  'incident', 'Escalate high-severity incidents for management sign-off', 'bi-exclamation-triangle'],
          ['Audit Scope Approval',      'audit',    'Scope must be approved by audit lead and management',  'bi-clipboard-check'],
        ];
        foreach ($quickStart as [$qs_name, $qs_type, $qs_desc, $qs_icon]):
          $meta = $entityTypeMeta[$qs_type] ?? ['icon' => 'bi-diagram-3', 'color' => '#6366f1', 'bg' => '#e0e7ff'];
        ?>
          <a href="/admin/approval-templates/create?type=<?= urlencode($qs_type) ?>&name=<?= urlencode($qs_name) ?>"
             class="workflow-template"
             style="text-decoration:none;color:inherit">
            <div class="wt-icon" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>">
              <i class="bi <?= $qs_icon ?>"></i>
            </div>
            <div class="wt-body">
              <div class="wt-name">
                <?= $qs_name ?>
                <span class="badge" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;margin-left:6px;font-size:10px;padding:2px 7px;border-radius:20px;font-weight:600;vertical-align:middle">
                  <?= $entityTypeLabels[$qs_type] ?? ucfirst($qs_type) ?>
                </span>
              </div>
              <div class="wt-desc"><?= $qs_desc ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

<?php else: ?>

  <div class="card">
    <table class="data-table">
      <thead>
        <tr>
          <th>Template Name</th>
          <th>Entity Type</th>
          <th>Steps</th>
          <th>Status</th>
          <th>Created</th>
          <th style="width:160px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($templates as $tmpl): ?>
          <?php
            $isActive  = !empty($tmpl['is_active']);
            $eType     = $tmpl['entity_type'] ?? '';
            $meta      = $entityTypeMeta[$eType] ?? ['icon' => 'bi-diagram-3', 'color' => '#6366f1', 'bg' => '#e0e7ff'];
            $stepCount = isset($tmpl['steps'])
              ? (is_array($tmpl['steps']) ? count($tmpl['steps']) : (int)$tmpl['steps'])
              : null;
          ?>
          <tr>
            <td>
              <span class="fw-600"><?= Security::h($tmpl['name']) ?></span>
              <?php if (!empty($tmpl['description'])): ?>
                <div class="text-muted text-sm" style="margin-top:2px"><?= Security::h(substr($tmpl['description'], 0, 70)) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;display:inline-flex;align-items:center;gap:5px">
                <i class="bi <?= $meta['icon'] ?>" style="font-size:11px"></i>
                <?= Security::h($entityTypeLabels[$eType] ?? ucfirst($eType)) ?>
              </span>
            </td>
            <td>
              <?php if ($stepCount !== null): ?>
                <span class="badge badge-gray">
                  <i class="bi bi-list-ol" style="font-size:11px"></i>
                  <?= $stepCount ?> step<?= $stepCount !== 1 ? 's' : '' ?>
                </span>
              <?php else: ?>
                <span class="text-muted text-sm">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isActive): ?>
                <span class="badge badge-green">
                  <i class="bi bi-check-circle-fill" style="font-size:10px"></i> Active
                </span>
              <?php else: ?>
                <span class="badge badge-gray">
                  <i class="bi bi-pause-circle" style="font-size:10px"></i> Inactive
                </span>
              <?php endif; ?>
            </td>
            <td class="text-muted text-sm">
              <?= !empty($tmpl['created_at'])
                    ? date('M j, Y', strtotime($tmpl['created_at']))
                    : '—' ?>
            </td>
            <td>
              <div style="display:flex;gap:6px;align-items:center">
                <a href="/admin/approval-templates/<?= (int)$tmpl['id'] ?>/edit"
                   class="btn btn-ghost btn-sm"
                   title="Edit template">
                  <i class="bi bi-pencil"></i>
                </a>
                <!-- Toggle active/inactive -->
                <form
                  method="POST"
                  action="/admin/approval-templates/<?= (int)$tmpl['id'] ?>/toggle"
                  style="display:inline"
                  data-confirm="<?= $isActive ? 'Deactivate' : 'Activate' ?> this template?"
                >
                  <?= Security::csrfField() ?>
                  <button
                    type="submit"
                    class="btn btn-sm <?= $isActive ? 'btn-ghost' : 'btn-primary' ?>"
                    title="<?= $isActive ? 'Deactivate' : 'Activate' ?>"
                  >
                    <?php if ($isActive): ?>
                      <i class="bi bi-pause-circle"></i> Deactivate
                    <?php else: ?>
                      <i class="bi bi-play-circle"></i> Activate
                    <?php endif; ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>
