<?php
$pageTitle    = 'New Issue';
$activeModule = 'issue';
$breadcrumbs  = [['Issues', '/issue'], ['New Issue', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Create Issue</h1>
    <p class="page-subtitle">Track a finding, vulnerability, or remediation task</p>
  </div>
  <div class="page-actions">
    <a href="/issue" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<form method="POST" action="/issue/create">
  <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

  <div class="two-col-layout">

    <!-- Left column -->
    <div style="flex:2;display:flex;flex-direction:column;gap:1rem">

      <div class="card">
        <div class="card-header">
          <div class="card-header-left"><i class="bi bi-bug-fill" style="color:var(--primary)"></i><span class="card-title">Issue Details</span></div>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label" for="title">Title <span style="color:#dc2626">*</span></label>
            <input type="text" id="title" name="title" class="form-control" placeholder="Brief description of the issue…" required autofocus>
          </div>

          <div class="form-row">
            <div class="form-group" style="flex:1">
              <label class="form-label" for="severity">Severity</label>
              <select id="severity" name="severity" class="form-control" onchange="updateSevPreview(this.value)">
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium" selected>Medium</option>
                <option value="low">Low</option>
              </select>
            </div>
            <div class="form-group" style="flex:1">
              <label class="form-label" for="source_type">Source</label>
              <select id="source_type" name="source_type" class="form-control">
                <option value="manual">Manual</option>
                <option value="audit">Audit</option>
                <option value="risk">Risk Assessment</option>
                <option value="incident">Incident</option>
                <option value="compliance">Compliance</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="4" placeholder="Describe the issue, how it was found, and why it matters…"></textarea>
          </div>

        </div>
      </div>

    </div>

    <!-- Right column -->
    <div style="display:flex;flex-direction:column;gap:1rem">

      <div class="card" id="sevCard">
        <div class="card-header">
          <div class="card-header-left"><i class="bi bi-speedometer2" style="color:var(--primary)"></i><span class="card-title">Severity</span></div>
        </div>
        <div class="card-body" style="text-align:center;padding:1.5rem 1rem">
          <div id="sevBadge" style="display:inline-block;padding:0.4rem 1.2rem;border-radius:99px;font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;background:#0284c720;color:#0284c7;border:2px solid #0284c740;margin-bottom:0.75rem">Medium</div>
          <p id="sevDesc" style="font-size:0.85rem;color:#6b7280;margin:0">Moderate impact; address promptly.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-header-left"><i class="bi bi-person-fill" style="color:var(--primary)"></i><span class="card-title">Assignment</span></div>
        </div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label" for="assigned_to">Assign To</label>
            <select id="assigned_to" name="assigned_to" class="form-control">
              <option value="">— Unassigned —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="due_date">Due Date</label>
            <input type="date" id="due_date" name="due_date" class="form-control">
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <button type="submit" class="btn btn-primary" style="width:100%"><i class="bi bi-plus-circle"></i> Create Issue</button>
          <a href="/issue" class="btn btn-ghost" style="width:100%;margin-top:0.5rem;text-align:center;display:block">Cancel</a>
        </div>
      </div>

    </div>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
const sevMeta = {
  critical: { color:'#dc2626', label:'Critical', desc:'Severe; requires immediate remediation.' },
  high:     { color:'#d97706', label:'High',     desc:'Significant risk; address urgently.' },
  medium:   { color:'#0284c7', label:'Medium',   desc:'Moderate impact; address promptly.' },
  low:      { color:'#059669', label:'Low',      desc:'Minor; handle in normal workflow.' },
};
function updateSevPreview(val) {
  const d = sevMeta[val] || sevMeta.medium;
  const badge = document.getElementById('sevBadge');
  badge.textContent = d.label;
  badge.style.color = d.color;
  badge.style.background = d.color + '20';
  badge.style.borderColor = d.color + '40';
  document.getElementById('sevDesc').textContent = d.desc;
}
updateSevPreview(document.getElementById('severity').value);
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
