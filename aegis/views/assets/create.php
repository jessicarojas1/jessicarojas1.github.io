<?php ob_start(); ?>

<div class="page-header">
  <div>
    <h1 class="page-title">New Asset</h1>
    <p class="page-subtitle">Register an asset in the inventory</p>
  </div>
  <div class="page-actions">
    <a href="/assets" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<?php if (!empty($_SESSION['asset_error'])): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-circle-fill"></i> <?= Security::h($_SESSION['asset_error']) ?></div>
  <?php unset($_SESSION['asset_error']); ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;">

  <!-- Main form -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title"><i class="bi bi-hdd-stack"></i> Asset Details</h3>
    </div>
    <div class="card-body">
      <form method="POST" action="/assets/create">
        <?= Security::csrfField() ?>

        <!-- Name + Type -->
        <div class="form-row" style="display:flex;gap:16px;">
          <div class="form-group" style="flex:2;">
            <label class="form-label required">Asset Name <span style="color:#ef4444;">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="e.g. Production Database Server" required
                   value="<?= Security::h($_POST['name'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Asset Type</label>
            <select name="asset_type" class="form-control">
              <?php
              $typeOpts = [
                'server'      => 'Server',
                'workstation' => 'Workstation',
                'application' => 'Application',
                'database'    => 'Database',
                'network'     => 'Network Device',
                'cloud'       => 'Cloud Resource',
                'mobile'      => 'Mobile Device',
                'iot'         => 'IoT Device',
                'saas'        => 'SaaS',
              ];
              foreach ($typeOpts as $val => $label):
                $sel = (($_POST['asset_type'] ?? 'server') === $val) ? 'selected' : '';
              ?>
                <option value="<?= $val ?>" <?= $sel ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Criticality + Classification + Status -->
        <div class="form-row" style="display:flex;gap:16px;">
          <div class="form-group" style="flex:1;">
            <label class="form-label">Criticality</label>
            <select name="criticality" class="form-control">
              <?php foreach (['critical'=>'Critical','high'=>'High','medium'=>'Medium','low'=>'Low'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= (($_POST['criticality'] ?? 'medium') === $val) ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Data Classification</label>
            <select name="classification" class="form-control">
              <option value="">None</option>
              <?php foreach (['public','internal','confidential','restricted','top_secret'] as $c): ?>
                <option value="<?= $c ?>" <?= (($_POST['classification'] ?? '') === $c) ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$c)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Status</label>
            <select name="status" class="form-control">
              <?php foreach (['active'=>'Active','maintenance'=>'Maintenance','decommissioned'=>'Decommissioned'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= (($_POST['status'] ?? 'active') === $val) ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Owner + Location -->
        <div class="form-row" style="display:flex;gap:16px;">
          <div class="form-group" style="flex:1;">
            <label class="form-label">Owner</label>
            <select name="owner_id" class="form-control">
              <option value="">Unassigned</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= ((int)($_POST['owner_id'] ?? 0) === (int)$u['id'] || (!isset($_POST['owner_id']) && Auth::id() === (int)$u['id'])) ? 'selected' : '' ?>>
                  <?= Security::h($u['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Location / Data Center</label>
            <input type="text" name="location" class="form-control" placeholder="e.g. AWS us-east-1, NYC-DC2"
                   value="<?= Security::h($_POST['location'] ?? '') ?>">
          </div>
        </div>

        <!-- IP + Hostname -->
        <div class="form-row" style="display:flex;gap:16px;">
          <div class="form-group" style="flex:1;">
            <label class="form-label">IP Address</label>
            <input type="text" name="ip_address" class="form-control" placeholder="e.g. 192.168.1.10 or 2001:db8::1"
                   value="<?= Security::h($_POST['ip_address'] ?? '') ?>">
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">IPv4 or IPv6. Invalid values will be cleared.</div>
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Hostname / FQDN</label>
            <input type="text" name="hostname" class="form-control" placeholder="e.g. db01.prod.example.com"
                   value="<?= Security::h($_POST['hostname'] ?? '') ?>">
          </div>
        </div>

        <!-- Vendor + Version -->
        <div class="form-row" style="display:flex;gap:16px;">
          <div class="form-group" style="flex:1;">
            <label class="form-label">Vendor / Manufacturer</label>
            <input type="text" name="vendor" class="form-control" placeholder="e.g. Microsoft, AWS, Cisco"
                   value="<?= Security::h($_POST['vendor'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Version / Model</label>
            <input type="text" name="version" class="form-control" placeholder="e.g. Windows Server 2022, v8.1"
                   value="<?= Security::h($_POST['version'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label">Last Scanned</label>
            <input type="date" name="last_scanned" class="form-control"
                   value="<?= Security::h($_POST['last_scanned'] ?? '') ?>">
          </div>
        </div>

        <!-- Description -->
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="3" placeholder="Brief description of the asset, its purpose, and any relevant notes..."><?= Security::h($_POST['description'] ?? '') ?></textarea>
        </div>

        <!-- Tags -->
        <div class="form-group">
          <label class="form-label">Tags <span style="font-size:12px;font-weight:400;color:var(--text-muted);">(comma-separated)</span></label>
          <input type="text" name="tags" class="form-control" placeholder="e.g. pci-scope, production, dmz, sox"
                 value="<?= Security::h($_POST['tags'] ?? '') ?>">
        </div>

        <div style="display:flex;gap:12px;margin-top:8px;">
          <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create Asset</button>
          <a href="/assets" class="btn btn-ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Sidebar help -->
  <div style="display:flex;flex-direction:column;gap:16px;">
    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-info-circle"></i> Criticality Guide</h4>
      </div>
      <div class="card-body" style="font-size:13px;">
        <div style="margin-bottom:10px;">
          <span style="background:#dc262618;color:var(--danger);padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">Critical</span>
          <p style="margin:4px 0 0;color:var(--text-muted);">Business-critical. Outage has severe financial or safety impact.</p>
        </div>
        <div style="margin-bottom:10px;">
          <span style="background:#ea580c18;color:#ea580c;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">High</span>
          <p style="margin:4px 0 0;color:var(--text-muted);">Important asset. Disruption significantly impacts operations.</p>
        </div>
        <div style="margin-bottom:10px;">
          <span style="background:#d9770618;color:var(--warning);padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">Medium</span>
          <p style="margin:4px 0 0;color:var(--text-muted);">Standard asset. Disruption has limited operational impact.</p>
        </div>
        <div>
          <span style="background:#16a34a18;color:var(--primary);padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;">Low</span>
          <p style="margin:4px 0 0;color:var(--text-muted);">Non-critical. Minimal impact if unavailable.</p>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4 class="card-title"><i class="bi bi-shield-lock"></i> Classification Guide</h4>
      </div>
      <div class="card-body" style="font-size:13px;color:var(--text-muted);">
        <p><strong>Public</strong> — No restrictions. Available to anyone.</p>
        <p><strong>Internal</strong> — For internal use only.</p>
        <p><strong>Confidential</strong> — Restricted to authorized staff.</p>
        <p><strong>Restricted</strong> — Highly sensitive. Need-to-know basis.</p>
        <p style="margin:0;"><strong>Top Secret</strong> — Highest sensitivity. Executive approval required.</p>
      </div>
    </div>
  </div>
</div>

<?php
$content      = ob_get_clean();
$pageTitle    = 'New Asset';
$activeModule = 'assets';
$breadcrumbs  = [['Asset Inventory', '/assets'], ['New Asset', null]];
require AEGIS_ROOT . '/views/layout.php';
?>
