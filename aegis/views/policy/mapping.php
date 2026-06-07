<?php
$pageTitle    = 'Policy & Control Mapping';
$activeModule = 'policy';
$breadcrumbs  = [['Policies', '/policy'], ['Mapping', null]];
ob_start(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Policy &amp; Control Mapping</h1>
    <p class="page-subtitle">View which controls every policy is mapped to across all compliance packages</p>
  </div>
  <a href="/policy" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Policies</a>
</div>

<!-- Filter bar -->
<div class="card" style="margin-bottom:16px;padding:14px 16px;">
  <form method="GET" action="/policy/mapping" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <div>
      <label style="display:block;font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;font-weight:600;">COMPLIANCE PACKAGE</label>
      <select name="package" id="filterPackage" class="form-control" style="min-width:200px;height:32px;font-size:0.85rem;">
        <option value="">All packages</option>
        <?php foreach ($packages as $pkg): ?>
        <option value="<?= (int)$pkg['id'] ?>" <?= ($filterPackage??0)===(int)$pkg['id']?'selected':'' ?>><?= Security::h($pkg['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;font-weight:600;">POLICY</label>
      <select name="policy" id="filterPolicy" class="form-control" style="min-width:200px;height:32px;font-size:0.85rem;">
        <option value="">All policies</option>
        <?php foreach ($allPolicies as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= ($filterPolicy??0)===(int)$p['id']?'selected':'' ?>><?= Security::h($p['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!empty($filterPackage) || !empty($filterPolicy)): ?>
    <a href="/policy/mapping" class="btn btn-sm btn-secondary" style="align-self:flex-end;"><i class="bi bi-x-circle"></i> Clear</a>
    <?php endif; ?>
  </form>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
  <div class="card" style="text-align:center;padding:16px;">
    <div style="font-size:2rem;font-weight:700;color:var(--primary);"><?= count($allPolicies) ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">Total Policies</div>
  </div>
  <div class="card" style="text-align:center;padding:16px;">
    <div style="font-size:2rem;font-weight:700;color:var(--success);"><?= count($mappings) ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">Total Mappings</div>
  </div>
  <div class="card" style="text-align:center;padding:16px;">
    <div style="font-size:2rem;font-weight:700;color:var(--warning);"><?= count($unmappedPolicies) ?></div>
    <div style="font-size:0.85rem;color:var(--text-muted);">Unmapped Policies</div>
  </div>
</div>

<?php if (!empty($unmappedPolicies) && empty($filterPackage) && empty($filterPolicy)): ?>
<div class="card" style="margin-bottom:20px;border-color:var(--warning);">
  <div class="card-header" style="display:flex;align-items:center;gap:8px;color:var(--warning);">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong><?= count($unmappedPolicies) ?> Unmapped <?= count($unmappedPolicies) === 1 ? 'Policy' : 'Policies' ?></strong>
  </div>
  <div class="card-body" style="padding:8px 16px;">
    <p style="font-size:0.875rem;color:var(--text-muted);margin-bottom:8px;">These policies are not mapped to any compliance control:</p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
      <?php foreach ($unmappedPolicies as $p): ?>
      <a href="/policy/<?= (int)$p['id'] ?>" class="badge badge-secondary" style="text-decoration:none;"><?= Security::h($p['title']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Mapping table -->
<?php if (empty($mappings)): ?>
<div class="card" style="text-align:center;padding:48px;">
  <i class="bi bi-diagram-2" style="font-size:2.5rem;color:var(--text-muted);display:block;margin-bottom:12px;"></i>
  <p style="color:var(--text-muted);">No mappings found. Open a policy and use the <strong>Map to Control</strong> feature.</p>
</div>
<?php else: ?>
<div class="card">
  <table class="table" style="font-size:0.875rem;">
    <thead>
      <tr>
        <th>Policy</th>
        <th>Status</th>
        <th>Package</th>
        <th>Control Code</th>
        <th>Control Title</th>
        <th>Next Review</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php
    $sevBadge = ['draft'=>'badge-secondary','under_review'=>'badge-info','published'=>'badge-success','approved'=>'badge-success','archived'=>'badge-secondary'];
    foreach ($mappings as $m):
      $overdue = $m['next_review_date'] && strtotime($m['next_review_date']) < time() && $m['status'] === 'published';
    ?>
      <tr>
        <td><a href="/policy/<?= (int)$m['policy_id'] ?>" style="font-weight:600;"><?= Security::h($m['policy_title']) ?></a></td>
        <td><span class="badge <?= $sevBadge[$m['status']] ?? 'badge-secondary' ?>"><?= ucfirst(str_replace('_',' ',$m['status'])) ?></span></td>
        <td><?= Security::h($m['package_name'] ?? '—') ?></td>
        <td><code style="font-size:0.8rem;"><?= Security::h($m['control_code'] ?? '—') ?></code></td>
        <td><?= Security::h($m['control_title'] ?? '—') ?></td>
        <td style="<?= $overdue ? 'color:var(--danger);font-weight:600;' : '' ?>">
          <?php if ($m['next_review_date']): ?>
            <?= date('M j, Y', strtotime($m['next_review_date'])) ?>
            <?php if ($overdue): ?><span style="font-size:0.75rem;"> (overdue)</span><?php endif; ?>
          <?php else: ?>
            <span style="color:var(--text-muted);">—</span>
          <?php endif; ?>
        </td>
        <td>
          <a href="/policy/<?= (int)$m['policy_id'] ?>" class="btn btn-sm btn-secondary">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script nonce="<?= Security::nonce() ?>">
document.getElementById('filterPackage').addEventListener('change', function(){ this.form.submit(); });
document.getElementById('filterPolicy').addEventListener('change', function(){ this.form.submit(); });
</script>
<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
?>
