<?php $breadcrumbs = [['RACI Matrix', null]]; ?>
<div class="page-header">
  <div>
    <h1 class="page-title">RACI Matrix</h1>
    <p class="page-subtitle">Define Responsible, Accountable, Consulted, and Informed roles per compliance domain</p>
  </div>
</div>

<?php if (empty($packages)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-people-fill" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No Active Compliance Packages</h3>
  <p style="color:var(--text-muted);">Create a compliance package first to assign RACI roles.</p>
  <a href="/compliance/create" class="btn btn-primary" style="margin-top:8px;">Go to Compliance</a>
</div>
<?php else: ?>
<div class="card">
  <table class="table">
    <thead>
      <tr>
        <th>Package</th>
        <th>Standard</th>
        <th>Domains</th>
        <th>Assignments</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($packages as $p): ?>
      <tr>
        <td style="font-weight:600;"><?= Security::h($p['name']) ?></td>
        <td><span style="font-family:monospace;font-size:0.85rem;"><?= Security::h($p['standard_code']) ?></span></td>
        <td><?= (int)$p['domain_count'] ?></td>
        <td>
          <?php if ($p['assignment_count'] > 0): ?>
            <span class="badge badge-success"><?= (int)$p['assignment_count'] ?> assigned</span>
          <?php else: ?>
            <span class="badge badge-secondary">None</span>
          <?php endif; ?>
        </td>
        <td style="display:flex;gap:6px;">
          <a href="/raci/<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary">Edit RACI</a>
          <a href="/raci/<?= (int)$p['id'] ?>/responsibility" class="btn btn-sm btn-secondary">Shared Responsibility</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:20px;">
  <div class="card-header"><h3 class="card-title">RACI Role Definitions</h3></div>
  <div class="card-body">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
      <div style="padding:12px;border-radius:8px;background:var(--bg);">
        <div style="font-size:1.5rem;font-weight:700;color:var(--primary);">R</div>
        <div style="font-weight:600;margin:4px 0;">Responsible</div>
        <div style="font-size:0.8rem;color:var(--text-muted);">Does the work to complete the task</div>
      </div>
      <div style="padding:12px;border-radius:8px;background:var(--bg);">
        <div style="font-size:1.5rem;font-weight:700;color:var(--danger);">A</div>
        <div style="font-weight:600;margin:4px 0;">Accountable</div>
        <div style="font-size:0.8rem;color:var(--text-muted);">Ultimately answerable for correct completion</div>
      </div>
      <div style="padding:12px;border-radius:8px;background:var(--bg);">
        <div style="font-size:1.5rem;font-weight:700;color:var(--warning);">C</div>
        <div style="font-weight:600;margin:4px 0;">Consulted</div>
        <div style="font-size:0.8rem;color:var(--text-muted);">Provides input before or during the task</div>
      </div>
      <div style="padding:12px;border-radius:8px;background:var(--bg);">
        <div style="font-size:1.5rem;font-weight:700;color:var(--text-muted);">I</div>
        <div style="font-weight:600;margin:4px 0;">Informed</div>
        <div style="font-size:0.8rem;color:var(--text-muted);">Kept up-to-date on progress and outcomes</div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
