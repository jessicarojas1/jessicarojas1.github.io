<?php $csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">RACI Matrix</h1>
    <p class="page-subtitle"><?= Security::h($package['name']) ?> (<?= Security::h($package['standard_code']) ?>)</p>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="/raci/<?= (int)$package['id'] ?>/responsibility" class="btn btn-secondary">Shared Responsibility</a>
    <a href="/raci" class="btn btn-secondary">Back</a>
  </div>
</div>

<?php if (empty($domains)): ?>
<div class="card" style="text-align:center;padding:40px;">
  <p style="color:var(--text-muted);">No domains found in this package. Add level-1 objectives first.</p>
</div>
<?php elseif (empty($users)): ?>
<div class="card" style="text-align:center;padding:40px;">
  <p style="color:var(--text-muted);">No active users found.</p>
</div>
<?php else: ?>
<div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;">
  Check boxes for each user's RACI role per domain. R=Responsible, A=Accountable, C=Consulted, I=Informed.
</div>

<form method="POST" action="/raci/<?= (int)$package['id'] ?>/save">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div style="overflow-x:auto;">
    <table class="table" style="min-width:600px;">
      <thead>
        <tr>
          <th style="min-width:200px;">Domain</th>
          <?php foreach ($users as $u): ?>
          <th style="text-align:center;min-width:80px;font-size:0.78rem;"><?= Security::h($u['name']) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($domains as $d): ?>
        <tr>
          <td style="font-weight:600;font-size:0.875rem;">
            <div style="font-family:monospace;font-size:0.78rem;color:var(--text-muted);"><?= Security::h($d['code']) ?></div>
            <?= Security::h($d['title']) ?>
          </td>
          <?php foreach ($users as $u): ?>
          <td style="text-align:center;vertical-align:middle;">
            <div style="display:flex;flex-direction:column;gap:2px;align-items:center;">
              <?php foreach (['R','A','C','I'] as $role):
                $roleKey = strtolower($role === 'R' ? 'responsible' : ($role === 'A' ? 'accountable' : ($role === 'C' ? 'consulted' : 'informed')));
                $checked = !empty($assignMap[$d['id']][$u['id']][$roleKey]);
              ?>
              <label style="display:flex;align-items:center;gap:3px;cursor:pointer;font-size:0.75rem;">
                <input type="checkbox" name="raci[<?= (int)$d['id'] ?>][<?= (int)$u['id'] ?>][]" value="<?= $roleKey ?>"<?= $checked?' checked':'' ?>>
                <?= $role ?>
              </label>
              <?php endforeach; ?>
            </div>
          </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save RACI Matrix</button>
    <a href="/raci" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<div class="card" style="margin-top:20px;">
  <div class="card-header"><h3 class="card-title">Legend</h3></div>
  <div class="card-body">
    <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:0.85rem;">
      <span><strong>R</strong> — Responsible: does the work</span>
      <span><strong>A</strong> — Accountable: ultimate owner, signs off</span>
      <span><strong>C</strong> — Consulted: provides input</span>
      <span><strong>I</strong> — Informed: kept in the loop</span>
    </div>
  </div>
</div>
<?php endif; ?>
