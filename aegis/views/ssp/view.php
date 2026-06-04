<?php
$csrf = Security::generateCsrfToken();
$statusLabels = [
    'operational'        => ['Operational',        'badge-success'],
    'under_development'  => ['Under Development',  'badge-warning'],
    'major_modification' => ['Major Modification',  'badge-info'],
    'other'              => ['Other',               'badge-secondary'],
];
$typeLabels = [
    'major_application'     => 'Major Application',
    'general_support_system'=> 'General Support System',
    'minor_application'     => 'Minor Application',
];
$impactBadge = fn($v) => match($v) { 'high' => 'badge-danger', 'low' => 'badge-success', default => 'badge-warning' };
[$statusLabel, $statusClass] = $statusLabels[$plan['operational_status']] ?? ['Unknown','badge-secondary'];
?>
<div class="page-header">
  <div>
    <h1 class="page-title"><?= Security::h($plan['title']) ?></h1>
    <p class="page-subtitle">System Security Plan · <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></p>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="/ssp/<?= (int)$plan['id'] ?>/generate" target="_blank" class="btn btn-primary"><i class="bi bi-file-earmark-text"></i> Generate Document</a>
    <button id="btnOpenEditSsp" class="btn btn-secondary"><i class="bi bi-pencil"></i> Edit</button>
    <form method="POST" action="/ssp/<?= (int)$plan['id'] ?>/delete" data-confirm="Delete this SSP permanently?" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i></button>
    </form>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

  <!-- Main info -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- System Info -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">System Information</h3></div>
      <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div><div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">System Name</div><div><?= Security::h($plan['system_name'] ?: '—') ?></div></div>
          <div><div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">System Owner</div><div><?= Security::h($plan['system_owner'] ?: '—') ?></div></div>
          <div><div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Owner Email</div><div><?= Security::h($plan['system_owner_email'] ?: '—') ?></div></div>
          <div><div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Information Owner</div><div><?= Security::h($plan['information_owner'] ?: '—') ?></div></div>
          <div><div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Authorizing Official</div><div><?= Security::h($plan['authorizing_official'] ?: '—') ?></div></div>
          <div><div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">System Type</div><div><?= Security::h($typeLabels[$plan['system_type']] ?? $plan['system_type']) ?></div></div>
        </div>
        <?php if ($plan['system_description']): ?>
        <div style="margin-top:16px;"><div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:6px;">Description</div><p style="margin:0;"><?= nl2br(Security::h($plan['system_description'])) ?></p></div>
        <?php endif; ?>
        <?php if ($plan['authorization_boundary']): ?>
        <div style="margin-top:16px;"><div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:6px;">Authorization Boundary</div><p style="margin:0;"><?= nl2br(Security::h($plan['authorization_boundary'])) ?></p></div>
        <?php endif; ?>
        <?php if ($plan['network_architecture'] || !empty($plan['network_arch_filename'])): ?>
        <div style="margin-top:16px;">
          <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:6px;">Network Architecture</div>
          <?php if ($plan['network_architecture']): ?><p style="margin:0 0 8px;"><?= nl2br(Security::h($plan['network_architecture'])) ?></p><?php endif; ?>
          <?php if (!empty($plan['network_arch_filename'])): ?>
          <a href="/ssp/<?= (int)$plan['id'] ?>/download/network-arch" class="btn btn-sm btn-secondary"><i class="bi bi-download"></i> <?= Security::h($plan['network_arch_filename']) ?></a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($plan['data_flow'] || !empty($plan['data_flow_filename'])): ?>
        <div style="margin-top:16px;">
          <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:6px;">Data Flow</div>
          <?php if ($plan['data_flow']): ?><p style="margin:0 0 8px;"><?= nl2br(Security::h($plan['data_flow'])) ?></p><?php endif; ?>
          <?php if (!empty($plan['data_flow_filename'])): ?>
          <a href="/ssp/<?= (int)$plan['id'] ?>/download/data-flow" class="btn btn-sm btn-secondary"><i class="bi bi-download"></i> <?= Security::h($plan['data_flow_filename']) ?></a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Linked Packages -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title">Compliance Packages</h3>
        <?php if (!empty($allPackages)): ?>
        <button class="btn btn-sm btn-primary open-add-pkg-btn"><i class="bi bi-plus-lg"></i> Add Package</button>
        <?php endif; ?>
      </div>
      <?php if (empty($linkedPackages)): ?>
      <div class="card-body" style="text-align:center;padding:30px;">
        <p style="color:var(--text-muted);">No packages linked yet.</p>
        <?php if (!empty($allPackages)): ?>
        <button class="btn btn-primary btn-sm open-add-pkg-btn">Add Package</button>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <table class="table">
        <thead><tr><th>Package</th><th>Standard</th><th>Controls</th><th>Compliant</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($linkedPackages as $pkg):
          $total    = (int)$pkg['control_count'];
          $compliant= (int)$pkg['compliant_count'];
          $pct      = $total > 0 ? round($compliant / $total * 100) : 0;
        ?>
          <tr>
            <td>
              <a href="/compliance/<?= (int)$pkg['id'] ?>" style="font-weight:600;"><?= Security::h($pkg['name']) ?></a>
              <?php if ($pkg['version']): ?><span style="font-size:0.78rem;color:var(--text-muted);">v<?= Security::h($pkg['version']) ?></span><?php endif; ?>
            </td>
            <td><?= Security::h($pkg['standard_code']) ?></td>
            <td><?= $total ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="flex:1;background:var(--border);border-radius:4px;height:6px;"><div style="width:<?= $pct ?>%;background:var(--success);border-radius:4px;height:6px;"></div></div>
                <span style="font-size:0.78rem;white-space:nowrap;"><?= $compliant ?>/<?= $total ?></span>
              </div>
            </td>
            <td>
              <form method="POST" action="/ssp/<?= (int)$plan['id'] ?>/remove-package/<?= (int)$pkg['id'] ?>" data-confirm="Remove this package from the SSP?" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  </div>

  <!-- Right sidebar -->
  <div style="display:flex;flex-direction:column;gap:20px;">

    <!-- Impact Levels -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Security Categorization</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:0.875rem;">Confidentiality</span>
          <span class="badge <?= $impactBadge($plan['confidentiality_impact']) ?>"><?= ucfirst($plan['confidentiality_impact']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:0.875rem;">Integrity</span>
          <span class="badge <?= $impactBadge($plan['integrity_impact']) ?>"><?= ucfirst($plan['integrity_impact']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:0.875rem;">Availability</span>
          <span class="badge <?= $impactBadge($plan['availability_impact']) ?>"><?= ucfirst($plan['availability_impact']) ?></span>
        </div>
      </div>
    </div>

    <!-- Dates -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Key Dates</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Authorization Date</div><div><?= $plan['authorization_date'] ? date('M j, Y', strtotime($plan['authorization_date'])) : '—' ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Next Review</div>
          <?php if ($plan['next_review_date']): ?>
            <?php $daysLeft = (int)((strtotime($plan['next_review_date']) - time()) / 86400); ?>
            <div style="display:flex;align-items:center;gap:6px;">
              <?= date('M j, Y', strtotime($plan['next_review_date'])) ?>
              <span class="badge <?= $daysLeft < 30 ? 'badge-danger' : ($daysLeft < 90 ? 'badge-warning' : 'badge-success') ?>"><?= $daysLeft ?>d</span>
            </div>
          <?php else: ?>—<?php endif; ?>
        </div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Created By</div><div><?= Security::h($plan['created_by_name'] ?? '—') ?></div></div>
        <div><div style="font-size:0.75rem;color:var(--text-muted);">Last Updated</div><div><?= $plan['updated_at'] ? date('M j, Y', strtotime($plan['updated_at'])) : '—' ?></div></div>
      </div>
    </div>

    <!-- Generate -->
    <div class="card" style="background:var(--primary);color:#fff;border-color:var(--primary);">
      <div class="card-body" style="text-align:center;padding:24px;">
        <i class="bi bi-file-earmark-text-fill" style="font-size:2.5rem;margin-bottom:12px;display:block;"></i>
        <h4 style="margin:0 0 8px;color:#fff;">Generate SSP Document</h4>
        <p style="margin:0 0 16px;font-size:0.875rem;opacity:0.85;">Produces a printable document with all control statements and implementation details.</p>
        <a href="/ssp/<?= (int)$plan['id'] ?>/generate" target="_blank" class="btn" style="background:#fff;color:var(--primary);font-weight:600;">
          <i class="bi bi-printer"></i> Open Document
        </a>
      </div>
    </div>

  </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
  <div style="background:var(--card-bg);border-radius:12px;padding:28px;width:780px;max-height:90vh;overflow-y:auto;max-width:95vw;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">Edit System Security Plan</h3>
      <button id="btnCloseEditSsp" style="background:none;border:none;cursor:pointer;font-size:1.25rem;color:var(--text-muted);"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/ssp/<?= (int)$plan['id'] ?>/update" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Title</label>
          <input type="text" name="title" class="form-control" value="<?= Security::h($plan['title']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">System Name</label>
          <input type="text" name="system_name" class="form-control" value="<?= Security::h($plan['system_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">System Owner</label>
          <input type="text" name="system_owner" class="form-control" value="<?= Security::h($plan['system_owner'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">System Owner Email</label>
          <input type="email" name="system_owner_email" class="form-control" value="<?= Security::h($plan['system_owner_email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Information Owner</label>
          <input type="text" name="information_owner" class="form-control" value="<?= Security::h($plan['information_owner'] ?? '') ?>">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Authorizing Official</label>
          <input type="text" name="authorizing_official" class="form-control" value="<?= Security::h($plan['authorizing_official'] ?? '') ?>">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">System Description</label>
          <textarea name="system_description" class="form-control" rows="3"><?= Security::h($plan['system_description'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Authorization Boundary</label>
          <textarea name="authorization_boundary" class="form-control" rows="2"><?= Security::h($plan['authorization_boundary'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Network Architecture</label>
          <textarea name="network_architecture" class="form-control" rows="2"><?= Security::h($plan['network_architecture'] ?? '') ?></textarea>
          <div style="margin-top:8px;">
            <?php if (!empty($plan['network_arch_filename'])): ?>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:6px;">Current: <a href="/ssp/<?= (int)$plan['id'] ?>/download/network-arch"><?= Security::h($plan['network_arch_filename']) ?></a></div>
            <?php endif; ?>
            <label style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;display:block;">Replace diagram file <span style="font-weight:400;">(optional)</span></label>
            <label class="file-drop" id="fileDropEditNetArch" for="editNetArchFile" style="padding:16px;">
              <i class="bi bi-diagram-3" style="font-size:1.5rem;color:var(--primary)"></i>
              <p style="margin:4px 0 0;font-size:0.875rem;">Drag &amp; drop or <strong>click to upload</strong></p>
              <p class="text-muted" style="margin:3px 0 0;font-size:0.78rem;">PDF, PNG, JPG, SVG, VSDX · max 10MB</p>
            </label>
            <input type="file" id="editNetArchFile" name="network_arch_file" accept=".pdf,.png,.jpg,.jpeg,.gif,.svg,.vsdx,.docx,.pptx" style="display:none"
                   data-change="showFileChange" data-drop-id="fileDropEditNetArch" data-name-id="editNetArchName" data-color="var(--primary)">
            <div id="editNetArchName" style="margin-top:6px;color:var(--primary);display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          </div>
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label class="form-label">Data Flow</label>
          <textarea name="data_flow" class="form-control" rows="2"><?= Security::h($plan['data_flow'] ?? '') ?></textarea>
          <div style="margin-top:8px;">
            <?php if (!empty($plan['data_flow_filename'])): ?>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:6px;">Current: <a href="/ssp/<?= (int)$plan['id'] ?>/download/data-flow"><?= Security::h($plan['data_flow_filename']) ?></a></div>
            <?php endif; ?>
            <label style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;display:block;">Replace diagram file <span style="font-weight:400;">(optional)</span></label>
            <label class="file-drop" id="fileDropEditDataFlow" for="editDataFlowFile" style="padding:16px;">
              <i class="bi bi-diagram-2" style="font-size:1.5rem;color:var(--primary)"></i>
              <p style="margin:4px 0 0;font-size:0.875rem;">Drag &amp; drop or <strong>click to upload</strong></p>
              <p class="text-muted" style="margin:3px 0 0;font-size:0.78rem;">PDF, PNG, JPG, SVG, VSDX · max 10MB</p>
            </label>
            <input type="file" id="editDataFlowFile" name="data_flow_file" accept=".pdf,.png,.jpg,.jpeg,.gif,.svg,.vsdx,.docx,.pptx" style="display:none"
                   data-change="showFileChange" data-drop-id="fileDropEditDataFlow" data-name-id="editDataFlowName" data-color="var(--primary)">
            <div id="editDataFlowName" style="margin-top:6px;color:var(--primary);display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Operational Status</label>
          <select name="operational_status" class="form-control">
            <?php foreach (['operational'=>'Operational','under_development'=>'Under Development','major_modification'=>'Major Modification','other'=>'Other'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= $plan['operational_status']===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">System Type</label>
          <select name="system_type" class="form-control">
            <option value="major_application" <?= $plan['system_type']==='major_application'?'selected':'' ?>>Major Application</option>
            <option value="general_support_system" <?= $plan['system_type']==='general_support_system'?'selected':'' ?>>General Support System</option>
            <option value="minor_application" <?= $plan['system_type']==='minor_application'?'selected':'' ?>>Minor Application</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Confidentiality Impact</label>
          <select name="confidentiality_impact" class="form-control">
            <option value="low" <?= $plan['confidentiality_impact']==='low'?'selected':'' ?>>Low</option>
            <option value="moderate" <?= $plan['confidentiality_impact']==='moderate'?'selected':'' ?>>Moderate</option>
            <option value="high" <?= $plan['confidentiality_impact']==='high'?'selected':'' ?>>High</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Integrity Impact</label>
          <select name="integrity_impact" class="form-control">
            <option value="low" <?= $plan['integrity_impact']==='low'?'selected':'' ?>>Low</option>
            <option value="moderate" <?= $plan['integrity_impact']==='moderate'?'selected':'' ?>>Moderate</option>
            <option value="high" <?= $plan['integrity_impact']==='high'?'selected':'' ?>>High</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Availability Impact</label>
          <select name="availability_impact" class="form-control">
            <option value="low" <?= $plan['availability_impact']==='low'?'selected':'' ?>>Low</option>
            <option value="moderate" <?= $plan['availability_impact']==='moderate'?'selected':'' ?>>Moderate</option>
            <option value="high" <?= $plan['availability_impact']==='high'?'selected':'' ?>>High</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Authorization Date</label>
          <input type="date" name="authorization_date" class="form-control" value="<?= Security::h($plan['authorization_date'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Next Review Date</label>
          <input type="date" name="next_review_date" class="form-control" value="<?= Security::h($plan['next_review_date'] ?? '') ?>">
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <button type="button" id="btnCancelEditSsp" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Package Modal -->
<?php if (!empty($allPackages)): ?>
<div id="addPkgModal" style="display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
  <div style="background:var(--card-bg);border-radius:12px;padding:28px;width:480px;max-width:95vw;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">Add Compliance Package</h3>
      <button id="btnCloseAddPkg" style="background:none;border:none;cursor:pointer;font-size:1.25rem;color:var(--text-muted);"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/ssp/<?= (int)$plan['id'] ?>/add-package">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-group">
        <label class="form-label">Package</label>
        <select name="package_id" class="form-control">
          <option value="">— Select —</option>
          <?php foreach ($allPackages as $pkg): ?>
          <option value="<?= (int)$pkg['id'] ?>"><?= Security::h($pkg['name']) ?> (<?= Security::h($pkg['standard_code']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">Add Package</button>
        <button type="button" id="btnCancelAddPkg" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<script nonce="<?= Security::nonce() ?>">
(function() {
  var editModal = document.getElementById('editModal');
  var addPkgModal = document.getElementById('addPkgModal');
  function open(m)  { if (m) m.style.display = 'flex'; }
  function close(m) { if (m) m.style.display = 'none'; }
  document.getElementById('btnOpenEditSsp').addEventListener('click', function() { open(editModal); });
  document.getElementById('btnCloseEditSsp').addEventListener('click', function() { close(editModal); });
  document.getElementById('btnCancelEditSsp').addEventListener('click', function() { close(editModal); });
  if (addPkgModal) {
    document.querySelectorAll('.open-add-pkg-btn').forEach(function(btn) {
      btn.addEventListener('click', function() { open(addPkgModal); });
    });
    document.getElementById('btnCloseAddPkg').addEventListener('click', function() { close(addPkgModal); });
    document.getElementById('btnCancelAddPkg').addEventListener('click', function() { close(addPkgModal); });
  }
  document.querySelectorAll('form[data-confirm]').forEach(function(f) {
    f.addEventListener('submit', function(e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
  });
})();
</script>
