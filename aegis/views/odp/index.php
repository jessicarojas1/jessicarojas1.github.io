<div class="page-header">
  <div>
    <h1 class="page-title">ODP Center</h1>
    <p class="page-subtitle">Organizationally Defined Parameters — document your organization's chosen values for NIST controls</p>
  </div>
</div>

<?php if (empty($packages)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-sliders2" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No Active Compliance Packages</h3>
  <p style="color:var(--text-muted);">Import a compliance package to define ODPs for its controls.</p>
</div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead><tr><th>Package</th><th>Standard</th><th>ODP Entries</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($packages as $pkg): ?>
      <tr>
        <td style="font-weight:600;"><?= Security::h($pkg['name']) ?></td>
        <td><?= Security::h($pkg['standard_code']) ?></td>
        <td>
          <?php if ($pkg['odp_count'] > 0): ?>
            <span class="badge badge-success"><?= (int)$pkg['odp_count'] ?> defined</span>
          <?php else: ?>
            <span class="badge badge-secondary">None yet</span>
          <?php endif; ?>
        </td>
        <td><a href="/odp/package/<?= (int)$pkg['id'] ?>" class="btn btn-sm btn-primary">Manage ODPs</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:20px;background:var(--bg);">
  <div class="card-body">
    <h4 style="margin:0 0 8px;"><i class="bi bi-info-circle"></i> What are ODPs?</h4>
    <p style="margin:0;font-size:0.875rem;color:var(--text-muted);">Organizationally Defined Parameters (ODPs) are values your organization selects to complete NIST control requirements. For example, NIST 800-171 control 3.1.10 requires locking sessions after "an organizationally defined time period of inactivity" — your ODP might be "15 minutes". Documenting these values is required for SSP completion and C3PAO assessments.</p>
  </div>
</div>
<?php endif; ?>
