<?php $csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">Custom Dashboards</h1>
    <p class="page-subtitle">Build personalized views with configurable widgets</p>
  </div>
  <button class="btn btn-primary" id="btnOpenCreate"><i class="bi bi-plus-lg"></i> New Dashboard</button>
</div>

<?php if (empty($dashboards)): ?>
<div class="card" style="text-align:center;padding:60px 20px;">
  <i class="bi bi-layout-wtf" style="font-size:3rem;color:var(--text-muted);"></i>
  <h3 style="margin:16px 0 8px;">No Dashboards Yet</h3>
  <p style="color:var(--text-muted);margin-bottom:20px;">Create a custom dashboard and add widgets to monitor your GRC metrics.</p>
  <button class="btn btn-primary" id="btnOpenCreate2">Create First Dashboard</button>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
  <?php foreach ($dashboards as $d): ?>
  <div class="card" style="display:flex;flex-direction:column;gap:0;">
    <div class="card-body" style="flex:1;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
        <h3 style="margin:0;font-size:1rem;font-weight:600;"><?= Security::h($d['name']) ?></h3>
        <?php if ($d['is_shared']): ?><span class="badge badge-info" style="font-size:0.7rem;">Shared</span><?php endif; ?>
      </div>
      <?php if ($d['description']): ?>
      <p style="color:var(--text-muted);font-size:0.85rem;margin:0 0 12px;"><?= Security::h($d['description']) ?></p>
      <?php endif; ?>
      <div style="font-size:0.8rem;color:var(--text-muted);">
        <span><i class="bi bi-grid-3x3-gap"></i> <?= (int)$d['widget_count'] ?> widget<?= $d['widget_count']!=1?'s':'' ?></span>
        <?php if ($d['owner_name']): ?> &middot; <span>By <?= Security::h($d['owner_name']) ?></span><?php endif; ?>
      </div>
    </div>
    <div style="padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:8px;">
      <a href="/dashboards/<?= (int)$d['id'] ?>" class="btn btn-sm btn-primary" style="flex:1;text-align:center;">Open</a>
      <form method="POST" action="/dashboards/<?= (int)$d['id'] ?>/delete" data-confirm="Delete this dashboard?" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Create Modal -->
<div id="createModal" style="display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;background:rgba(0,0,0,0.5);">
  <div style="background:var(--card-bg);border-radius:12px;padding:28px;width:480px;max-width:95vw;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <h3 style="margin:0;">New Dashboard</h3>
      <button id="btnCloseCreate" style="background:none;border:none;cursor:pointer;font-size:1.25rem;"><i class="bi bi-x-lg"></i></button>
    </div>
    <form method="POST" action="/dashboards/create">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div class="form-group"><label class="form-label">Dashboard Name <span style="color:var(--danger)">*</span></label><input type="text" name="name" class="form-control" required placeholder="e.g. Executive Overview"></div>
      <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2" placeholder="Optional description"></textarea></div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="is_shared">
          <span class="form-label" style="margin:0;">Share with all users</span>
        </label>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px;">
        <button type="submit" class="btn btn-primary">Create Dashboard</button>
        <button type="button" id="btnCancelCreate" class="btn btn-secondary">Cancel</button>
      </div>
    </form>
  </div>
</div>
<script nonce="<?= Security::nonce() ?>">
var createModal = document.getElementById('createModal');
function openCreate() { createModal.style.display = 'flex'; }
document.getElementById('btnOpenCreate').addEventListener('click', openCreate);
var btn2 = document.getElementById('btnOpenCreate2');
if (btn2) btn2.addEventListener('click', openCreate);
document.getElementById('btnCloseCreate').addEventListener('click', function(){ createModal.style.display = 'none'; });
document.getElementById('btnCancelCreate').addEventListener('click', function(){ createModal.style.display = 'none'; });
document.querySelectorAll('[data-confirm]').forEach(function(el) {
  el.addEventListener('submit', function(e) { if (!confirm(el.getAttribute('data-confirm'))) e.preventDefault(); });
});
</script>
