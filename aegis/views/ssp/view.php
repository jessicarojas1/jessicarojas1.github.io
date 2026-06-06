<?php
$breadcrumbs  = $breadcrumbs  ?? [['SSP', '/ssp'], ['View Plan', null]];
$csrf = Security::generateCsrfToken();
$statusLabels = ['operational'=>['Operational','badge-success'],'under_development'=>['Under Development','badge-warning'],'major_modification'=>['Major Modification','badge-info'],'other'=>['Other','badge-secondary']];
[$statusLabel,$statusClass] = $statusLabels[$plan['operational_status']] ?? ['Unknown','badge-secondary'];

// Decode JSON list fields
$teamContacts   = json_decode($plan['team_contacts']          ?? '[]', true) ?: [];
$contracts      = json_decode($plan['contracts']              ?? '[]', true) ?: [];
$dataInventory  = json_decode($plan['data_inventory']         ?? '[]', true) ?: [];
$hwInventory    = json_decode($plan['hardware_inventory']      ?? '[]', true) ?: [];
$swInventory    = json_decode($plan['software_inventory']      ?? '[]', true) ?: [];
$networkDevices = json_decode($plan['network_devices']         ?? '[]', true) ?: [];
$serverInv      = json_decode($plan['server_inventory']        ?? '[]', true) ?: [];
$userDevices    = json_decode($plan['user_device_types']       ?? '[]', true) ?: [];
$otherSystems   = json_decode($plan['other_connected_systems'] ?? '[]', true) ?: [];

// Helper to display a text field or dash
if (!function_exists('sspVal')) {
    function sspVal($v) { return $v ? Security::h($v) : '<span style="color:var(--text-light)">—</span>'; }
}
?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($plan['title']) ?></h1>
    <p class="page-subtitle" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
      <?php if ($plan['framework']): ?><span class="badge badge-secondary"><?= Security::h($plan['framework']) ?></span><?php endif; ?>
      <?php if ($plan['presentation_mode'] ?? ''): ?><span class="badge" style="background:var(--indigo-subtle,rgba(99,102,241,.1));color:var(--indigo,#6366f1)"><?= Security::h(ucfirst($plan['presentation_mode'] ?? 'standard')) ?> Mode</span><?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="/ssp/<?= (int)$plan['id'] ?>/generate" target="_blank" class="btn btn-primary"><i class="bi bi-file-earmark-text"></i> Generate Document</a>
    <a href="/ssp/<?= (int)$plan['id'] ?>/generate?format=word" class="btn btn-secondary"><i class="bi bi-file-earmark-word"></i> Word</a>
    <button class="btn btn-secondary" data-show-modal="editSspModal"><i class="bi bi-pencil"></i> Edit</button>
    <form method="POST" action="/ssp/<?= (int)$plan['id'] ?>/delete" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-danger" data-confirm-click="Delete this SSP permanently?"><i class="bi bi-trash"></i></button>
    </form>
  </div>
</div>

<!-- Tab nav -->
<div style="display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:20px;overflow-x:auto">
  <?php
  $tabs = [
    'overview'     => ['bi-info-circle-fill',    'Overview'],
    'approval'     => ['bi-patch-check-fill',     'Approval'],
    'org'          => ['bi-people-fill',          'Organization'],
    'boundary'     => ['bi-bounding-box',         'System Boundary'],
    'environment'  => ['bi-diagram-3-fill',       'Environment'],
    'inventory'    => ['bi-server',               'Inventory'],
    'compliance'   => ['bi-shield-check',         'Compliance'],
  ];
  foreach ($tabs as $tid => [$icon,$label]):
  ?>
  <button type="button" class="ssp-tab-btn<?= $tid==='overview'?' active':'' ?>" data-tab="<?= $tid ?>"
          style="background:none;border:none;padding:12px 18px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s;color:var(--text-muted)">
    <i class="bi <?= $icon ?>"></i><?= $label ?>
  </button>
  <?php endforeach; ?>
</div>

<!-- ────────────────── OVERVIEW ────────────────── -->
<div class="ssp-panel" id="tab-overview">
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start">
    <div style="display:flex;flex-direction:column;gap:16px">

      <!-- System Info -->
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-pc-display-horizontal" style="color:var(--primary)"></i> System Information</h3></div>
        <div class="card-body">
          <table class="desc-table">
            <tr><th>System Name</th><td><?= sspVal($plan['system_name']) ?></td></tr>
            <tr><th>System Owner</th><td><?= sspVal($plan['system_owner']) ?></td></tr>
            <tr><th>Owner Email</th><td><?= sspVal($plan['system_owner_email']) ?></td></tr>
            <tr><th>Information Owner</th><td><?= sspVal($plan['information_owner']) ?></td></tr>
            <tr><th>Authorizing Official</th><td><?= sspVal($plan['authorizing_official']) ?></td></tr>
            <tr><th>System Type</th><td><?= sspVal(str_replace('_',' ',ucfirst($plan['system_type']))) ?></td></tr>
          </table>
          <?php if ($plan['system_description']): ?>
          <div style="margin-top:14px"><div class="text-xs text-muted" style="margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Description</div><p style="margin:0;white-space:pre-wrap;font-size:13px"><?= Security::h($plan['system_description']) ?></p></div>
          <?php endif; ?>
          <?php if ($plan['general_system_purpose']): ?>
          <div style="margin-top:14px"><div class="text-xs text-muted" style="margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em">General Purpose</div><p style="margin:0;white-space:pre-wrap;font-size:13px"><?= Security::h($plan['general_system_purpose']) ?></p></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Company Info -->
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-building" style="color:var(--primary)"></i> Company Information</h3></div>
        <div class="card-body">
          <table class="desc-table">
            <tr><th>Company Name</th><td><?= sspVal($plan['company_name']) ?></td></tr>
            <tr><th>DUNS Number</th><td><?= sspVal($plan['duns_number']) ?></td></tr>
            <tr><th>CAGE Code</th><td><?= sspVal($plan['cage_code']) ?></td></tr>
            <tr><th>Framework</th><td><?= sspVal($plan['framework']) ?></td></tr>
            <tr><th>Assessment Scope</th><td><?= sspVal($plan['assessment_scope']) ?></td></tr>
            <tr><th>Presentation Mode</th><td><?= sspVal(ucfirst($plan['presentation_mode'] ?? 'standard')) ?></td></tr>
          </table>
        </div>
      </div>

    </div>
    <!-- Right sidebar: Security categorization + dates -->
    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Security Categorization</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <?php $ib = fn($v) => match($v){'high'=>'badge-danger','low'=>'badge-success',default=>'badge-warning'}; ?>
          <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:13px">Confidentiality</span><span class="badge <?= $ib($plan['confidentiality_impact']) ?>"><?= ucfirst($plan['confidentiality_impact']) ?></span></div>
          <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:13px">Integrity</span><span class="badge <?= $ib($plan['integrity_impact']) ?>"><?= ucfirst($plan['integrity_impact']) ?></span></div>
          <div style="display:flex;justify-content:space-between;align-items:center"><span style="font-size:13px">Availability</span><span class="badge <?= $ib($plan['availability_impact']) ?>"><?= ucfirst($plan['availability_impact']) ?></span></div>
        </div>
      </div>
      <div class="card">
        <div class="card-header"><h3 class="card-title">Key Dates</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
          <div><div class="text-xs text-muted">Authorization Date</div><div><?= $plan['authorization_date'] ? date('M j, Y', strtotime($plan['authorization_date'])) : '—' ?></div></div>
          <div><div class="text-xs text-muted">Next Review</div>
            <?php if ($plan['next_review_date']): $dl=(int)((strtotime($plan['next_review_date'])-time())/86400); ?>
              <div style="display:flex;align-items:center;gap:6px"><?= date('M j, Y', strtotime($plan['next_review_date'])) ?> <span class="badge <?= $dl<30?'badge-danger':($dl<90?'badge-warning':'badge-success') ?>"><?= $dl ?>d</span></div>
            <?php else: ?>—<?php endif; ?>
          </div>
          <div><div class="text-xs text-muted">Last Updated</div><div><?= $plan['updated_at'] ? date('M j, Y', strtotime($plan['updated_at'])) : '—' ?></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ────────────────── APPROVAL ────────────────── -->
<div class="ssp-panel" id="tab-approval" style="display:none">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-check-circle-fill" style="color:var(--primary)"></i> Approval Information</h3></div>
      <div class="card-body">
        <table class="desc-table">
          <tr><th>Approval Status</th><td><?php $as=$plan['approval_status']??''; echo $as ? '<span class="badge '.($as==='approved'?'badge-success':($as==='rejected'?'badge-danger':'badge-warning')).'">'.Security::h(ucfirst($as)).'</span>' : '—'; ?></td></tr>
          <tr><th>Approver Name</th><td><?= sspVal($plan['approver_name']) ?></td></tr>
          <tr><th>Approver Title</th><td><?= sspVal($plan['approver_title']) ?></td></tr>
          <tr><th>Approval Date</th><td><?= $plan['approval_date'] ? date('M j, Y', strtotime($plan['approval_date'])) : '—' ?></td></tr>
          <tr><th>Notes</th><td><?= sspVal($plan['approval_notes']) ?></td></tr>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-pen-fill" style="color:var(--primary)"></i> Digital Certification</h3></div>
      <div class="card-body">
        <table class="desc-table">
          <tr><th>Affirming Official</th><td><?= sspVal($plan['certifying_official_name']) ?></td></tr>
          <tr><th>Title</th><td><?= sspVal($plan['certifying_official_title']) ?></td></tr>
          <tr><th>Certification Date</th><td><?= $plan['certification_date'] ? date('M j, Y', strtotime($plan['certification_date'])) : '—' ?></td></tr>
        </table>
        <?php if ($plan['certification_statement']): ?>
        <div style="margin-top:14px;padding:14px;background:var(--bg-secondary);border-radius:8px;border-left:3px solid var(--primary)">
          <div class="text-xs text-muted" style="margin-bottom:6px;font-weight:600;text-transform:uppercase">Certification Statement</div>
          <p style="margin:0;font-size:13px;font-style:italic"><?= Security::h($plan['certification_statement']) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ────────────────── ORGANIZATION ────────────────── -->
<div class="ssp-panel" id="tab-org" style="display:none">
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
      <h3 class="card-title"><i class="bi bi-people-fill" style="color:var(--primary)"></i> Team Contacts</h3>
      <button class="btn btn-sm btn-primary" data-show-modal="addContactModal"><i class="bi bi-plus"></i> Add Contact</button>
    </div>
    <?php if (empty($teamContacts)): ?>
    <div class="card-body" style="text-align:center;padding:40px"><i class="bi bi-people" style="font-size:2rem;color:var(--text-light);display:block;margin-bottom:10px"></i><p class="text-muted">No contacts added yet.</p></div>
    <?php else: ?>
    <div class="card-body p0">
      <table class="table">
        <thead><tr><th>Name</th><th>Title</th><th>Role</th><th>Email</th><th>Phone</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($teamContacts as $idx => $c): ?>
          <tr>
            <td><strong><?= Security::h($c['name'] ?? '') ?></strong></td>
            <td><?= Security::h($c['title'] ?? '') ?></td>
            <td><span class="badge badge-secondary"><?= Security::h($c['role'] ?? '') ?></span></td>
            <td><?= Security::h($c['email'] ?? '') ?></td>
            <td><?= Security::h($c['phone'] ?? '') ?></td>
            <td><button class="btn btn-ghost btn-sm text-danger" data-click="removeContact" data-arg="<?= $idx ?>"><i class="bi bi-trash"></i></button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ────────────────── SYSTEM BOUNDARY ────────────────── -->
<div class="ssp-panel" id="tab-boundary" style="display:none">
  <div style="display:flex;flex-direction:column;gap:16px">
    <?php
    $boundaryCards = [
      ['boundary_description',   'bi-bounding-box',      'System Boundary Description'],
      ['info_systems_apps',      'bi-window-stack',      'Information Systems & Applications'],
      ['endpoints_user_devices', 'bi-laptop',            'Endpoints & User Devices'],
      ['servers_storage',        'bi-server',            'Servers & Storage'],
      ['physical_security',      'bi-lock-fill',         'Physical Security Controls'],
      ['access_control_auth',    'bi-shield-lock-fill',  'Access Control & User Authorization'],
      ['authorization_boundary', 'bi-hexagon-fill',      'Authorization Boundary'],
    ];
    foreach ($boundaryCards as [$field, $icon, $title]):
      if (!($plan[$field] ?? '')) continue;
    ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi <?= $icon ?>" style="color:var(--primary)"></i> <?= $title ?></h3></div>
      <div class="card-body"><p style="margin:0;white-space:pre-wrap;font-size:13px"><?= Security::h($plan[$field]) ?></p></div>
    </div>
    <?php endforeach; ?>
    <?php if (!array_filter(array_map(fn($c)=>$plan[$c[0]]??'', $boundaryCards))): ?>
    <div class="card"><div class="card-body" style="text-align:center;padding:40px"><i class="bi bi-bounding-box" style="font-size:2rem;color:var(--text-light);display:block;margin-bottom:10px"></i><p class="text-muted">No system boundary information added yet. Use the Edit button to fill in details.</p></div></div>
    <?php endif; ?>
  </div>
</div>

<!-- ────────────────── ENVIRONMENT ────────────────── -->
<div class="ssp-panel" id="tab-environment" style="display:none">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <div style="display:flex;flex-direction:column;gap:16px">
      <?php if ($plan['topology_description']): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-diagram-3-fill" style="color:var(--primary)"></i> Network Topology</h3></div>
        <div class="card-body"><p style="margin:0;white-space:pre-wrap;font-size:13px"><?= Security::h($plan['topology_description']) ?></p></div>
      </div>
      <?php endif; ?>
      <?php if ($plan['network_architecture'] || !empty($plan['network_arch_filename'])): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-share-fill" style="color:var(--primary)"></i> Network Architecture</h3></div>
        <div class="card-body">
          <?php if ($plan['network_architecture']): ?><p style="margin:0 0 8px;white-space:pre-wrap;font-size:13px"><?= Security::h($plan['network_architecture']) ?></p><?php endif; ?>
          <?php if (!empty($plan['network_arch_filename'])): ?><a href="/ssp/<?= (int)$plan['id'] ?>/download/network-arch" class="btn btn-sm btn-secondary"><i class="bi bi-download"></i> <?= Security::h($plan['network_arch_filename']) ?></a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($plan['data_flow'] || !empty($plan['data_flow_filename'])): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-arrow-left-right" style="color:var(--primary)"></i> Data Flow</h3></div>
        <div class="card-body">
          <?php if ($plan['data_flow']): ?><p style="margin:0 0 8px;white-space:pre-wrap;font-size:13px"><?= Security::h($plan['data_flow']) ?></p><?php endif; ?>
          <?php if (!empty($plan['data_flow_filename'])): ?><a href="/ssp/<?= (int)$plan['id'] ?>/download/data-flow" class="btn btn-sm btn-secondary"><i class="bi bi-download"></i> <?= Security::h($plan['data_flow_filename']) ?></a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:16px">
      <?php if (!empty($serverInv)): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-hdd-stack-fill" style="color:var(--primary)"></i> Servers (<?= count($serverInv) ?>)</h3></div>
        <div class="card-body p0"><table class="table" style="font-size:12px"><thead><tr><th>Name</th><th>Role</th><th>OS</th><th>IP</th></tr></thead><tbody><?php foreach($serverInv as $s): ?><tr><td><?= Security::h($s['name']??'') ?></td><td><?= Security::h($s['role']??'') ?></td><td><?= Security::h($s['os']??'') ?></td><td><?= Security::h($s['ip']??'') ?></td></tr><?php endforeach; ?></tbody></table></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($networkDevices)): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-router-fill" style="color:var(--primary)"></i> Network Devices (<?= count($networkDevices) ?>)</h3></div>
        <div class="card-body p0"><table class="table" style="font-size:12px"><thead><tr><th>Name</th><th>Type</th><th>IP/Location</th></tr></thead><tbody><?php foreach($networkDevices as $d): ?><tr><td><?= Security::h($d['name']??'') ?></td><td><?= Security::h($d['type']??'') ?></td><td><?= Security::h($d['ip']??'') ?></td></tr><?php endforeach; ?></tbody></table></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($userDevices)): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-laptop" style="color:var(--primary)"></i> User Devices (<?= count($userDevices) ?>)</h3></div>
        <div class="card-body p0"><table class="table" style="font-size:12px"><thead><tr><th>Type</th><th>Count</th><th>OS</th></tr></thead><tbody><?php foreach($userDevices as $d): ?><tr><td><?= Security::h($d['type']??'') ?></td><td><?= Security::h($d['count']??'') ?></td><td><?= Security::h($d['os']??'') ?></td></tr><?php endforeach; ?></tbody></table></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($otherSystems)): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-cloud-fill" style="color:var(--primary)"></i> Other Connected Systems</h3></div>
        <div class="card-body p0"><table class="table" style="font-size:12px"><thead><tr><th>System</th><th>Connection Type</th><th>Data Shared</th></tr></thead><tbody><?php foreach($otherSystems as $s): ?><tr><td><?= Security::h($s['name']??'') ?></td><td><?= Security::h($s['connection']??'') ?></td><td><?= Security::h($s['data']??'') ?></td></tr><?php endforeach; ?></tbody></table></div>
      </div>
      <?php endif; ?>
      <?php if ($plan['maintenance_info']): ?>
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="bi bi-tools" style="color:var(--primary)"></i> Maintenance</h3></div>
        <div class="card-body"><p style="margin:0;white-space:pre-wrap;font-size:13px"><?= Security::h($plan['maintenance_info']) ?></p></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ────────────────── INVENTORY ────────────────── -->
<div class="ssp-panel" id="tab-inventory" style="display:none">
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Hardware -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h3 class="card-title"><i class="bi bi-motherboard-fill" style="color:var(--primary)"></i> Hardware Inventory</h3>
        <button class="btn btn-sm btn-primary" data-show-modal="addHwModal"><i class="bi bi-plus"></i> Add</button>
      </div>
      <?php if (empty($hwInventory)): ?><div class="card-body" style="text-align:center;padding:30px"><p class="text-muted">No hardware items added.</p></div>
      <?php else: ?>
      <div class="card-body p0"><table class="table" style="font-size:12px"><thead><tr><th>Make/Model</th><th>Serial</th><th>Function</th><th>Location</th><th></th></tr></thead><tbody>
      <?php foreach($hwInventory as $idx=>$h): ?>
      <tr><td><?= Security::h($h['make_model']??'') ?></td><td><?= Security::h($h['serial']??'') ?></td><td><?= Security::h($h['function']??'') ?></td><td><?= Security::h($h['location']??'') ?></td><td><button class="btn btn-ghost btn-sm text-danger" data-click="removeHw" data-arg="<?= $idx ?>"><i class="bi bi-trash"></i></button></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>

    <!-- Software -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h3 class="card-title"><i class="bi bi-code-square" style="color:var(--primary)"></i> Software Inventory</h3>
        <button class="btn btn-sm btn-primary" data-show-modal="addSwModal"><i class="bi bi-plus"></i> Add</button>
      </div>
      <?php if (empty($swInventory)): ?><div class="card-body" style="text-align:center;padding:30px"><p class="text-muted">No software items added.</p></div>
      <?php else: ?>
      <div class="card-body p0"><table class="table" style="font-size:12px"><thead><tr><th>Name</th><th>Version</th><th>Vendor</th><th>Purpose</th><th>License</th><th></th></tr></thead><tbody>
      <?php foreach($swInventory as $idx=>$s): ?>
      <tr><td><?= Security::h($s['name']??'') ?></td><td><?= Security::h($s['version']??'') ?></td><td><?= Security::h($s['vendor']??'') ?></td><td><?= Security::h($s['purpose']??'') ?></td><td><?= Security::h($s['license']??'') ?></td><td><button class="btn btn-ghost btn-sm text-danger" data-click="removeSw" data-arg="<?= $idx ?>"><i class="bi bi-trash"></i></button></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>

    <!-- Data Inventory -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h3 class="card-title"><i class="bi bi-database-fill" style="color:var(--primary)"></i> Data Inventory</h3>
        <button class="btn btn-sm btn-primary" data-show-modal="addDataModal"><i class="bi bi-plus"></i> Add</button>
      </div>
      <?php if (empty($dataInventory)): ?><div class="card-body" style="text-align:center;padding:30px"><p class="text-muted">No data types recorded.</p></div>
      <?php else: ?>
      <div class="card-body p0"><table class="table" style="font-size:12px"><thead><tr><th>Data Type</th><th>Classification</th><th>Volume</th><th>Location</th><th>Retention</th><th></th></tr></thead><tbody>
      <?php foreach($dataInventory as $idx=>$d): ?>
      <tr><td><?= Security::h($d['type']??'') ?></td><td><span class="badge badge-<?= ($d['classification']??'')==='CUI'?'warning':(($d['classification']??'')==='Public'?'success':'secondary') ?>"><?= Security::h($d['classification']??'') ?></span></td><td><?= Security::h($d['volume']??'') ?></td><td><?= Security::h($d['location']??'') ?></td><td><?= Security::h($d['retention']??'') ?></td><td><button class="btn btn-ghost btn-sm text-danger" data-click="removeData" data-arg="<?= $idx ?>"><i class="bi bi-trash"></i></button></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>

    <!-- Contracts -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h3 class="card-title"><i class="bi bi-file-earmark-text-fill" style="color:var(--primary)"></i> Contracts</h3>
        <button class="btn btn-sm btn-primary" data-show-modal="addContractModal"><i class="bi bi-plus"></i> Add</button>
      </div>
      <?php if (empty($contracts)): ?><div class="card-body" style="text-align:center;padding:30px"><p class="text-muted">No contracts recorded.</p></div>
      <?php else: ?>
      <div class="card-body p0"><table class="table" style="font-size:12px"><thead><tr><th>Contract #</th><th>CAGE Code</th><th>Vendor</th><th>Description</th><th>Expiry</th><th></th></tr></thead><tbody>
      <?php foreach($contracts as $idx=>$c): ?>
      <tr><td><code><?= Security::h($c['number']??'') ?></code></td><td><?= Security::h($c['cage_code']??'') ?></td><td><?= Security::h($c['vendor']??'') ?></td><td><?= Security::h($c['description']??'') ?></td><td><?= Security::h($c['expiry']??'') ?></td><td><button class="btn btn-ghost btn-sm text-danger" data-click="removeContract" data-arg="<?= $idx ?>"><i class="bi bi-trash"></i></button></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- ────────────────── COMPLIANCE ────────────────── -->
<div class="ssp-panel" id="tab-compliance" style="display:none">
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
      <h3 class="card-title"><i class="bi bi-shield-check" style="color:var(--primary)"></i> Compliance Packages</h3>
      <?php if (!empty($allPackages)): ?><button class="btn btn-sm btn-primary" data-show-modal="addPkgModal"><i class="bi bi-plus-lg"></i> Add Package</button><?php endif; ?>
    </div>
    <?php if (empty($linkedPackages)): ?>
    <div class="card-body" style="text-align:center;padding:40px"><p class="text-muted">No packages linked.</p></div>
    <?php else: ?>
    <table class="table">
      <thead><tr><th>Package</th><th>Standard</th><th>Controls</th><th>Compliant</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($linkedPackages as $pkg): $total=(int)$pkg['control_count'];$compliant=(int)$pkg['compliant_count'];$pct=$total>0?round($compliant/$total*100):0; ?>
        <tr>
          <td><a href="/compliance/<?= (int)$pkg['id'] ?>" style="font-weight:600"><?= Security::h($pkg['name']) ?></a><?php if($pkg['version']): ?> <span class="text-xs text-muted">v<?= Security::h($pkg['version']) ?></span><?php endif; ?></td>
          <td><?= Security::h($pkg['standard_code']) ?></td>
          <td><?= $total ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;min-width:80px;background:var(--border);border-radius:4px;height:6px"><div style="width:<?= $pct ?>%;background:var(--success);border-radius:4px;height:6px"></div></div>
              <span class="text-xs"><?= $compliant ?>/<?= $total ?></span>
            </div>
          </td>
          <td>
            <form method="POST" action="/ssp/<?= (int)$plan['id'] ?>/remove-package/<?= (int)$pkg['id'] ?>" style="margin:0">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <button type="submit" class="btn btn-sm btn-danger" data-confirm-click="Remove this package?"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- ────────────────── EDIT MODAL ────────────────── -->
<div class="um-overlay" id="editSspModal" style="display:none">
  <div class="um-dialog um-dialog-lg" style="max-width:860px">
    <div class="um-header"><h3><i class="bi bi-pencil-fill"></i> Edit System Security Plan</h3><button class="um-close" data-close-modal="editSspModal"><i class="bi bi-x-lg"></i></button></div>
    <div class="um-body">
      <form method="POST" action="/ssp/<?= (int)$plan['id'] ?>/update" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <!-- Hidden fields for JSON lists (updated by JS) -->
        <input type="hidden" name="team_contacts"          id="hf_team_contacts"   value="<?= Security::h(json_encode($teamContacts)) ?>">
        <input type="hidden" name="contracts"              id="hf_contracts"        value="<?= Security::h(json_encode($contracts)) ?>">
        <input type="hidden" name="data_inventory"         id="hf_data_inventory"   value="<?= Security::h(json_encode($dataInventory)) ?>">
        <input type="hidden" name="hardware_inventory"     id="hf_hw_inventory"     value="<?= Security::h(json_encode($hwInventory)) ?>">
        <input type="hidden" name="software_inventory"     id="hf_sw_inventory"     value="<?= Security::h(json_encode($swInventory)) ?>">
        <input type="hidden" name="network_devices"        id="hf_network_devices"  value="<?= Security::h(json_encode($networkDevices)) ?>">
        <input type="hidden" name="server_inventory"       id="hf_server_inventory" value="<?= Security::h(json_encode($serverInv)) ?>">
        <input type="hidden" name="user_device_types"      id="hf_user_devices"     value="<?= Security::h(json_encode($userDevices)) ?>">
        <input type="hidden" name="other_connected_systems" id="hf_other_systems"   value="<?= Security::h(json_encode($otherSystems)) ?>">

        <!-- Inner tabs for the edit modal -->
        <div style="display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:18px;overflow-x:auto">
          <?php foreach(['basic'=>'Basic Info','company'=>'Company','approval'=>'Approval','boundary'=>'Boundary','environment'=>'Environment'] as $et=>$el): ?>
          <button type="button" class="edit-tab-btn<?= $et==='basic'?' active':'' ?>" data-edit-tab="<?= $et ?>"
                  style="background:none;border:none;padding:10px 14px;font-size:12px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;color:var(--text-muted)">
            <?= $el ?>
          </button>
          <?php endforeach; ?>
        </div>

        <div class="edit-panel" id="ep-basic">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group" style="grid-column:1/-1"><label class="form-label required">Title</label><input type="text" name="title" class="form-control" value="<?= Security::h($plan['title']) ?>" required></div>
            <div class="form-group"><label class="form-label">System Name</label><input type="text" name="system_name" class="form-control" value="<?= Security::h($plan['system_name']??'') ?>"></div>
            <div class="form-group"><label class="form-label">System Owner</label><input type="text" name="system_owner" class="form-control" value="<?= Security::h($plan['system_owner']??'') ?>"></div>
            <div class="form-group"><label class="form-label">Owner Email</label><input type="email" name="system_owner_email" class="form-control" value="<?= Security::h($plan['system_owner_email']??'') ?>"></div>
            <div class="form-group"><label class="form-label">Information Owner</label><input type="text" name="information_owner" class="form-control" value="<?= Security::h($plan['information_owner']??'') ?>"></div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">Authorizing Official</label><input type="text" name="authorizing_official" class="form-control" value="<?= Security::h($plan['authorizing_official']??'') ?>"></div>
            <div class="form-group"><label class="form-label">Operational Status</label><select name="operational_status" class="form-control"><?php foreach(['operational'=>'Operational','under_development'=>'Under Development','major_modification'=>'Major Modification','other'=>'Other'] as $v=>$l): ?><option value="<?= $v ?>"<?= ($plan['operational_status']??'')===$v?' selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">System Type</label><select name="system_type" class="form-control"><option value="major_application"<?= ($plan['system_type']??'')==='major_application'?' selected':'' ?>>Major Application</option><option value="general_support_system"<?= ($plan['system_type']??'')==='general_support_system'?' selected':'' ?>>General Support System</option><option value="minor_application"<?= ($plan['system_type']??'')==='minor_application'?' selected':'' ?>>Minor Application</option></select></div>
            <div class="form-group"><label class="form-label">Confidentiality</label><select name="confidentiality_impact" class="form-control"><?php foreach(['low','moderate','high'] as $v): ?><option value="<?= $v ?>"<?= ($plan['confidentiality_impact']??'')===$v?' selected':'' ?>><?= ucfirst($v) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Integrity</label><select name="integrity_impact" class="form-control"><?php foreach(['low','moderate','high'] as $v): ?><option value="<?= $v ?>"<?= ($plan['integrity_impact']??'')===$v?' selected':'' ?>><?= ucfirst($v) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Availability</label><select name="availability_impact" class="form-control"><?php foreach(['low','moderate','high'] as $v): ?><option value="<?= $v ?>"<?= ($plan['availability_impact']??'')===$v?' selected':'' ?>><?= ucfirst($v) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Presentation Mode</label><select name="presentation_mode" class="form-control"><?php foreach(['standard'=>'Standard','military'=>'Military','corporate'=>'Corporate','airforce'=>'Air Force','dod'=>'DoD'] as $v=>$l): ?><option value="<?= $v ?>"<?= ($plan['presentation_mode']??'standard')===$v?' selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Authorization Date</label><input type="date" name="authorization_date" class="form-control" value="<?= Security::h($plan['authorization_date']??'') ?>"></div>
            <div class="form-group"><label class="form-label">Next Review Date</label><input type="date" name="next_review_date" class="form-control" value="<?= Security::h($plan['next_review_date']??'') ?>"></div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">System Description</label><textarea name="system_description" class="form-control" rows="3"><?= Security::h($plan['system_description']??'') ?></textarea></div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">General System Purpose</label><textarea name="general_system_purpose" class="form-control" rows="2"><?= Security::h($plan['general_system_purpose']??'') ?></textarea></div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">System Details</label><textarea name="system_details" class="form-control" rows="2"><?= Security::h($plan['system_details']??'') ?></textarea></div>
          </div>
        </div>

        <div class="edit-panel" id="ep-company" style="display:none">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="<?= Security::h($plan['company_name']??'') ?>"></div>
            <div class="form-group"><label class="form-label">DUNS Number</label><input type="text" name="duns_number" class="form-control" value="<?= Security::h($plan['duns_number']??'') ?>" placeholder="e.g. 123456789"></div>
            <div class="form-group"><label class="form-label">CAGE Code</label><input type="text" name="cage_code" class="form-control" value="<?= Security::h($plan['cage_code']??'') ?>" placeholder="e.g. 1AB23"></div>
            <div class="form-group"><label class="form-label">Framework</label><input type="text" name="framework" class="form-control" value="<?= Security::h($plan['framework']??'') ?>" placeholder="e.g. NIST 800-171, CMMC 2.0"></div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">Assessment Scope</label><textarea name="assessment_scope" class="form-control" rows="3"><?= Security::h($plan['assessment_scope']??'') ?></textarea></div>
          </div>
        </div>

        <div class="edit-panel" id="ep-approval" style="display:none">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label class="form-label">Approval Status</label><select name="approval_status" class="form-control"><option value="">— None —</option><?php foreach(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','conditional'=>'Conditional'] as $v=>$l): ?><option value="<?= $v ?>"<?= ($plan['approval_status']??'')===$v?' selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label class="form-label">Approval Date</label><input type="date" name="approval_date" class="form-control" value="<?= Security::h($plan['approval_date']??'') ?>"></div>
            <div class="form-group"><label class="form-label">Approver Name</label><input type="text" name="approver_name" class="form-control" value="<?= Security::h($plan['approver_name']??'') ?>"></div>
            <div class="form-group"><label class="form-label">Approver Title</label><input type="text" name="approver_title" class="form-control" value="<?= Security::h($plan['approver_title']??'') ?>"></div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">Approval Notes</label><textarea name="approval_notes" class="form-control" rows="2"><?= Security::h($plan['approval_notes']??'') ?></textarea></div>
            <hr style="grid-column:1/-1;border-color:var(--border)">
            <div class="form-group"><label class="form-label">Certifying Official Name</label><input type="text" name="certifying_official_name" class="form-control" value="<?= Security::h($plan['certifying_official_name']??'') ?>"></div>
            <div class="form-group"><label class="form-label">Certifying Official Title</label><input type="text" name="certifying_official_title" class="form-control" value="<?= Security::h($plan['certifying_official_title']??'') ?>"></div>
            <div class="form-group"><label class="form-label">Certification Date</label><input type="date" name="certification_date" class="form-control" value="<?= Security::h($plan['certification_date']??'') ?>"></div>
            <div class="form-group" style="grid-column:1/-1"><label class="form-label">Certification Statement</label><textarea name="certification_statement" class="form-control" rows="3"><?= Security::h($plan['certification_statement']??'') ?></textarea></div>
          </div>
        </div>

        <div class="edit-panel" id="ep-boundary" style="display:none">
          <div style="display:flex;flex-direction:column;gap:12px">
            <div class="form-group"><label class="form-label">System Boundary Description</label><textarea name="boundary_description" class="form-control" rows="3"><?= Security::h($plan['boundary_description']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Authorization Boundary</label><textarea name="authorization_boundary" class="form-control" rows="2"><?= Security::h($plan['authorization_boundary']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Information Systems &amp; Applications</label><textarea name="info_systems_apps" class="form-control" rows="3"><?= Security::h($plan['info_systems_apps']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Endpoints &amp; User Devices</label><textarea name="endpoints_user_devices" class="form-control" rows="2"><?= Security::h($plan['endpoints_user_devices']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Servers &amp; Storage</label><textarea name="servers_storage" class="form-control" rows="2"><?= Security::h($plan['servers_storage']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Physical Security Controls</label><textarea name="physical_security" class="form-control" rows="2"><?= Security::h($plan['physical_security']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Access Control &amp; User Authorization</label><textarea name="access_control_auth" class="form-control" rows="2"><?= Security::h($plan['access_control_auth']??'') ?></textarea></div>
          </div>
        </div>

        <div class="edit-panel" id="ep-environment" style="display:none">
          <div style="display:flex;flex-direction:column;gap:12px">
            <div class="form-group"><label class="form-label">Network Topology Description</label><textarea name="topology_description" class="form-control" rows="3"><?= Security::h($plan['topology_description']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Network Architecture Description</label><textarea name="network_architecture" class="form-control" rows="2"><?= Security::h($plan['network_architecture']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Data Flow Description</label><textarea name="data_flow" class="form-control" rows="2"><?= Security::h($plan['data_flow']??'') ?></textarea></div>
            <div class="form-group"><label class="form-label">Maintenance Information</label><textarea name="maintenance_info" class="form-control" rows="2"><?= Security::h($plan['maintenance_info']??'') ?></textarea></div>
          </div>
        </div>

        <div class="form-actions" style="margin-top:16px">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <button type="button" class="btn btn-ghost" data-close-modal="editSspModal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Contact Modal -->
<div class="um-overlay" id="addContactModal" style="display:none">
  <div class="um-dialog"><div class="um-header"><h3>Add Team Contact</h3><button class="um-close" data-close-modal="addContactModal"><i class="bi bi-x-lg"></i></button></div>
  <div class="um-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group"><label class="form-label">Name</label><input type="text" id="ci_name" class="form-control" placeholder="Full name"></div>
      <div class="form-group"><label class="form-label">Title</label><input type="text" id="ci_title" class="form-control" placeholder="Job title"></div>
      <div class="form-group"><label class="form-label">Role</label><input type="text" id="ci_role" class="form-control" placeholder="e.g. System Owner, ISSO"></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" id="ci_email" class="form-control"></div>
      <div class="form-group"><label class="form-label">Phone</label><input type="text" id="ci_phone" class="form-control"></div>
    </div>
    <div class="form-actions"><button type="button" class="btn btn-primary" data-click="addContact">Add Contact</button><button type="button" class="btn btn-ghost" data-close-modal="addContactModal">Cancel</button></div>
  </div></div>
</div>

<!-- Add Hardware Modal -->
<div class="um-overlay" id="addHwModal" style="display:none">
  <div class="um-dialog"><div class="um-header"><h3>Add Hardware</h3><button class="um-close" data-close-modal="addHwModal"><i class="bi bi-x-lg"></i></button></div>
  <div class="um-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group"><label class="form-label">Make/Model</label><input type="text" id="hw_make_model" class="form-control"></div>
      <div class="form-group"><label class="form-label">Serial Number</label><input type="text" id="hw_serial" class="form-control"></div>
      <div class="form-group"><label class="form-label">Function</label><input type="text" id="hw_function" class="form-control" placeholder="e.g. Workstation, Firewall"></div>
      <div class="form-group"><label class="form-label">Location</label><input type="text" id="hw_location" class="form-control"></div>
    </div>
    <div class="form-actions"><button type="button" class="btn btn-primary" data-click="addHw">Add</button><button type="button" class="btn btn-ghost" data-close-modal="addHwModal">Cancel</button></div>
  </div></div>
</div>

<!-- Add Software Modal -->
<div class="um-overlay" id="addSwModal" style="display:none">
  <div class="um-dialog"><div class="um-header"><h3>Add Software</h3><button class="um-close" data-close-modal="addSwModal"><i class="bi bi-x-lg"></i></button></div>
  <div class="um-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group"><label class="form-label">Name</label><input type="text" id="sw_name" class="form-control"></div>
      <div class="form-group"><label class="form-label">Version</label><input type="text" id="sw_version" class="form-control"></div>
      <div class="form-group"><label class="form-label">Vendor</label><input type="text" id="sw_vendor" class="form-control"></div>
      <div class="form-group"><label class="form-label">Purpose</label><input type="text" id="sw_purpose" class="form-control"></div>
      <div class="form-group"><label class="form-label">License Type</label><input type="text" id="sw_license" class="form-control"></div>
    </div>
    <div class="form-actions"><button type="button" class="btn btn-primary" data-click="addSw">Add</button><button type="button" class="btn btn-ghost" data-close-modal="addSwModal">Cancel</button></div>
  </div></div>
</div>

<!-- Add Data Inventory Modal -->
<div class="um-overlay" id="addDataModal" style="display:none">
  <div class="um-dialog"><div class="um-header"><h3>Add Data Type</h3><button class="um-close" data-close-modal="addDataModal"><i class="bi bi-x-lg"></i></button></div>
  <div class="um-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group"><label class="form-label">Data Type</label><input type="text" id="di_type" class="form-control" placeholder="e.g. PII, CUI, Financial"></div>
      <div class="form-group"><label class="form-label">Classification</label><select id="di_classification" class="form-control"><option>Public</option><option>Internal</option><option>CUI</option><option>Classified</option></select></div>
      <div class="form-group"><label class="form-label">Volume</label><input type="text" id="di_volume" class="form-control" placeholder="e.g. 10,000 records"></div>
      <div class="form-group"><label class="form-label">Location</label><input type="text" id="di_location" class="form-control" placeholder="e.g. Production DB, S3 Bucket"></div>
      <div class="form-group"><label class="form-label">Retention Period</label><input type="text" id="di_retention" class="form-control" placeholder="e.g. 7 years"></div>
    </div>
    <div class="form-actions"><button type="button" class="btn btn-primary" data-click="addData">Add</button><button type="button" class="btn btn-ghost" data-close-modal="addDataModal">Cancel</button></div>
  </div></div>
</div>

<!-- Add Contract Modal -->
<div class="um-overlay" id="addContractModal" style="display:none">
  <div class="um-dialog"><div class="um-header"><h3>Add Contract</h3><button class="um-close" data-close-modal="addContractModal"><i class="bi bi-x-lg"></i></button></div>
  <div class="um-body">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <div class="form-group"><label class="form-label">Contract Number</label><input type="text" id="cn_number" class="form-control"></div>
      <div class="form-group"><label class="form-label">CAGE Code</label><input type="text" id="cn_cage" class="form-control"></div>
      <div class="form-group"><label class="form-label">Vendor</label><input type="text" id="cn_vendor" class="form-control"></div>
      <div class="form-group"><label class="form-label">Expiry Date</label><input type="date" id="cn_expiry" class="form-control"></div>
      <div class="form-group" style="grid-column:1/-1"><label class="form-label">Description</label><textarea id="cn_desc" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="form-actions"><button type="button" class="btn btn-primary" data-click="addContract">Add</button><button type="button" class="btn btn-ghost" data-close-modal="addContractModal">Cancel</button></div>
  </div></div>
</div>

<!-- Add Package Modal -->
<?php if (!empty($allPackages)): ?>
<div class="um-overlay" id="addPkgModal" style="display:none">
  <div class="um-dialog"><div class="um-header"><h3>Add Compliance Package</h3><button class="um-close" data-close-modal="addPkgModal"><i class="bi bi-x-lg"></i></button></div>
  <div class="um-body">
    <form method="POST" action="/ssp/<?= (int)$plan['id'] ?>/add-package">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-group"><label class="form-label">Package</label><select name="package_id" class="form-control"><option value="">— Select —</option><?php foreach($allPackages as $pkg): ?><option value="<?= (int)$pkg['id'] ?>"><?= Security::h($pkg['name']) ?> (<?= Security::h($pkg['standard_code']) ?>)</option><?php endforeach; ?></select></div>
      <div class="form-actions"><button type="submit" class="btn btn-primary">Add Package</button><button type="button" class="btn btn-ghost" data-close-modal="addPkgModal">Cancel</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<script nonce="<?= Security::nonce() ?>">
// In-memory list state (mirrors hidden fields in the form)
var sspLists = {
  team_contacts:          <?= json_encode($teamContacts, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  contracts:              <?= json_encode($contracts, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  data_inventory:         <?= json_encode($dataInventory, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  hardware_inventory:     <?= json_encode($hwInventory, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  software_inventory:     <?= json_encode($swInventory, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  network_devices:        <?= json_encode($networkDevices, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  server_inventory:       <?= json_encode($serverInv, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  user_device_types:      <?= json_encode($userDevices, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
  other_connected_systems:<?= json_encode($otherSystems, JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
function syncHidden() {
  var map = {
    'team_contacts':'hf_team_contacts','contracts':'hf_contracts','data_inventory':'hf_data_inventory',
    'hardware_inventory':'hf_hw_inventory','software_inventory':'hf_sw_inventory',
    'network_devices':'hf_network_devices','server_inventory':'hf_server_inventory',
    'user_device_types':'hf_user_devices','other_connected_systems':'hf_other_systems'
  };
  Object.entries(map).forEach(function([k,id]){ var el=document.getElementById(id); if(el) el.value=JSON.stringify(sspLists[k]); });
}
function val(id) { var el=document.getElementById(id); return el ? el.value.trim() : ''; }
function clearFields(ids) { ids.forEach(function(id){ var el=document.getElementById(id); if(el) el.value=''; }); }

function addContact() {
  sspLists.team_contacts.push({name:val('ci_name'),title:val('ci_title'),role:val('ci_role'),email:val('ci_email'),phone:val('ci_phone')});
  syncHidden(); closeModal('addContactModal'); location.reload();
}
function removeContact(idx) { sspLists.team_contacts.splice(idx,1); syncHidden(); location.reload(); }

function addHw() {
  sspLists.hardware_inventory.push({make_model:val('hw_make_model'),serial:val('hw_serial'),function:val('hw_function'),location:val('hw_location')});
  syncHidden(); closeModal('addHwModal'); location.reload();
}
function removeHw(idx) { sspLists.hardware_inventory.splice(idx,1); syncHidden(); location.reload(); }

function addSw() {
  sspLists.software_inventory.push({name:val('sw_name'),version:val('sw_version'),vendor:val('sw_vendor'),purpose:val('sw_purpose'),license:val('sw_license')});
  syncHidden(); closeModal('addSwModal'); location.reload();
}
function removeSw(idx) { sspLists.software_inventory.splice(idx,1); syncHidden(); location.reload(); }

function addData() {
  sspLists.data_inventory.push({type:val('di_type'),classification:val('di_classification'),volume:val('di_volume'),location:val('di_location'),retention:val('di_retention')});
  syncHidden(); closeModal('addDataModal'); location.reload();
}
function removeData(idx) { sspLists.data_inventory.splice(idx,1); syncHidden(); location.reload(); }

function addContract() {
  sspLists.contracts.push({number:val('cn_number'),cage_code:val('cn_cage'),vendor:val('cn_vendor'),expiry:val('cn_expiry'),description:val('cn_desc')});
  syncHidden(); closeModal('addContractModal'); location.reload();
}
function removeContract(idx) { sspLists.contracts.splice(idx,1); syncHidden(); location.reload(); }

// Tab switching
document.querySelectorAll('.ssp-tab-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.ssp-tab-btn').forEach(function(b){ b.classList.remove('active'); b.style.color='var(--text-muted)'; b.style.borderBottomColor='transparent'; });
    document.querySelectorAll('.ssp-panel').forEach(function(p){ p.style.display='none'; });
    btn.classList.add('active');
    btn.style.color='var(--primary)';
    btn.style.borderBottomColor='var(--primary)';
    var panel = document.getElementById('tab-'+btn.dataset.tab);
    if (panel) panel.style.display='';
  });
});
// Activate first tab style on load
(function(){
  var first = document.querySelector('.ssp-tab-btn.active');
  if (first) { first.style.color='var(--primary)'; first.style.borderBottomColor='var(--primary)'; }
})();

// Edit modal inner tabs
document.querySelectorAll('.edit-tab-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.edit-tab-btn').forEach(function(b){ b.classList.remove('active'); b.style.color='var(--text-muted)'; b.style.borderBottomColor='transparent'; });
    document.querySelectorAll('.edit-panel').forEach(function(p){ p.style.display='none'; });
    btn.classList.add('active');
    btn.style.color='var(--primary)';
    btn.style.borderBottomColor='var(--primary)';
    var panel = document.getElementById('ep-'+btn.dataset.editTab);
    if (panel) panel.style.display='';
  });
});
(function(){
  var f=document.querySelector('.edit-tab-btn.active');
  if(f){f.style.color='var(--primary)';f.style.borderBottomColor='var(--primary)';}
})();

</script>
