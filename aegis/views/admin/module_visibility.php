<?php
ob_start();
$groups = [
  'Compliance' => [
    'compliance'      => 'Compliance Packages',
    'control_testing' => 'Control Testing',
    'compliance_gap'  => 'Gap Analysis',
    'import'          => 'Import Standard',
  ],
  'Operations' => [
    'audit'         => 'Audits',
    'policy'        => 'Policies',
    'incident'      => 'Incidents',
    'playbooks'     => 'Playbooks',
    'issue'         => 'Issues',
    'change'        => 'Change Requests',
    'bcp'           => 'BCP / DR',
    'incident_sla'  => 'Incident SLA',
    'questionnaire' => 'Questionnaires',
  ],
  'Risk' => [
    'risk'             => 'Risk Register',
    'risk_matrix'      => 'Risk Matrix',
    'risk_roadmap'     => 'Treatment Roadmap',
    'risk_exceptions'  => 'Exceptions',
    'threats'          => 'Threat Register',
    'treatment_plans'  => 'Treatment Plans',
    'kris'             => 'KRI Dashboard',
    'vendor'           => 'Vendor Risk',
    'vendor_contracts' => 'Contracts',
    'assets'           => 'Asset Inventory',
  ],
  'Analytics' => [
    'metrics'      => 'Metrics & Trends',
    'documents'    => 'Documents',
    'report'       => 'Reports',
    'report_board' => 'Board Dashboard',
    'export'       => 'Export',
    'calendar'     => 'Calendar',
  ],
  'Resources' => [
    'search' => 'Search',
    'docs'   => 'Documentation',
  ],
];
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Module Visibility</h1>
    <p class="page-subtitle">Show or hide modules in the sidebar navigation for all users</p>
  </div>
  <a href="/admin" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Admin</a>
</div>

<form method="POST" action="/admin/module-visibility/save">
  <?= Security::csrfField() ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin-bottom:24px">
    <?php foreach ($groups as $groupName => $modules): ?>
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><?= $groupName ?></h3>
      </div>
      <div class="card-body p0">
        <?php foreach ($modules as $key => $label): ?>
          <?php $isHidden = ($hidden['module_hide_' . $key] ?? '0') === '1'; ?>
          <div class="list-item" style="padding:10px 16px">
            <div class="list-item-body">
              <div class="list-item-title" style="font-size:13px"><?= Security::h($label) ?></div>
            </div>
            <label class="toggle-switch">
              <input type="checkbox" name="hide[<?= $key ?>]" value="1" <?= $isHidden ? '' : 'checked' ?>
                     onchange="this.closest('.list-item').querySelector('.vis-badge').textContent = this.checked ? 'Visible' : 'Hidden';
                               this.closest('.list-item').querySelector('.vis-badge').className = 'badge ' + (this.checked ? 'badge-green' : 'badge-red')">
              <span class="toggle-slider"></span>
            </label>
            <span class="vis-badge badge <?= $isHidden ? 'badge-red' : 'badge-green' ?>" style="margin-left:8px;min-width:52px;text-align:center">
              <?= $isHidden ? 'Hidden' : 'Visible' ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;gap:12px">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Changes</button>
    <a href="/admin" class="btn btn-ghost">Cancel</a>
  </div>
</form>

<style>
.toggle-switch { position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider {
  position:absolute; inset:0; background:#cbd5e1; border-radius:22px;
  cursor:pointer; transition:.2s;
}
.toggle-slider:before {
  content:''; position:absolute; height:16px; width:16px; left:3px; bottom:3px;
  background:white; border-radius:50%; transition:.2s;
}
.toggle-switch input:checked + .toggle-slider { background:#4f46e5; }
.toggle-switch input:checked + .toggle-slider:before { transform:translateX(18px); }
</style>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
