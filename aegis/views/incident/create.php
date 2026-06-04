<?php
$pageTitle    = 'New Incident';
$activeModule = 'incident';
$breadcrumbs  = [['Incident Management', '/incident'], ['New Incident', null]];
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Report New Incident</h1>
    <p class="page-subtitle">Document a security incident for tracking and investigation</p>
  </div>
  <div class="page-actions">
    <a href="/incident" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<form method="POST" action="/incident/create">
  <?= Security::csrfField() ?>
  <input type="hidden" name="status" value="open">

  <div class="two-col-layout">

    <!-- Left column -->
    <div style="flex:2;display:flex;flex-direction:column;gap:1rem">

      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-exclamation-triangle-fill"></i> Incident Details</h3>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label" for="title">Title <span style="color:#dc2626">*</span></label>
            <input type="text" id="title" name="title" class="form-control" placeholder="Brief description of the incident…" required autofocus>
            <span class="form-text">Summarize the incident in one clear sentence.</span>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="category">Category</label>
              <select id="category" name="category" class="form-control">
                <option value="">— Select category —</option>
                <?php foreach (['Data Breach','System Outage','Unauthorized Access','Malware','Physical','Policy Violation','Other'] as $cat): ?>
                  <option value="<?= Security::h($cat) ?>"><?= Security::h($cat) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="severity">Severity</label>
              <select id="severity" name="severity" class="form-control" data-change="updateSeverityPreview" data-input-val="1">
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium" selected>Medium</option>
                <option value="low">Low</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="description">Description</label>
            <textarea id="description" name="description" class="form-control" rows="4" placeholder="Provide a detailed description of the incident, including how it was discovered and initial observations…"></textarea>
          </div>

          <div class="form-group">
            <label class="form-label" for="affected_systems">Affected Systems</label>
            <textarea id="affected_systems" name="affected_systems" class="form-control" rows="3" placeholder="List all systems, services, or assets affected by this incident…"></textarea>
          </div>

          <div class="form-group">
            <label class="form-label" for="impact_description">Impact Description</label>
            <textarea id="impact_description" name="impact_description" class="form-control" rows="3" placeholder="Describe the business impact, data exposure, or operational disruption…"></textarea>
          </div>

        </div>
      </div>

    </div>

    <!-- Right column -->
    <div style="display:flex;flex-direction:column;gap:1rem">

      <!-- Severity preview -->
      <div class="card" id="severityCard">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-speedometer2"></i> Severity</h3>
        </div>
        <div class="card-body" style="text-align:center;padding:1.5rem 1rem">
          <div id="severityBadge" style="display:inline-block;padding:0.4rem 1.2rem;border-radius:99px;font-size:1rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;background:#0284c720;color:#0284c7;border:2px solid #0284c740;margin-bottom:0.75rem">
            Medium
          </div>
          <p id="severityDesc" style="font-size:0.85rem;color:var(--text-muted);margin:0">Moderate impact; should be addressed promptly.</p>
        </div>
      </div>

      <!-- Assignment & timing -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title"><i class="bi bi-person-fill"></i> Assignment &amp; Timing</h3>
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
            <span class="form-text">Who is responsible for investigating this incident?</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="detected_at">Detected At</label>
            <input type="datetime-local" id="detected_at" name="detected_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
            <span class="form-text">Leave as-is to use the current time.</span>
          </div>

        </div>
      </div>

      <!-- Submit -->
      <div class="card">
        <div class="card-body">
          <button type="submit" class="btn btn-danger" style="width:100%"><i class="bi bi-exclamation-triangle-fill"></i> Report Incident</button>
          <a href="/incident" class="btn btn-ghost" style="width:100%;margin-top:0.5rem;text-align:center;display:block">Cancel</a>
        </div>
      </div>

    </div>
  </div>
</form>

<script nonce="<?= Security::nonce() ?>">
const sevData = {
  critical: { color: '#dc2626', label: 'Critical', desc: 'Severe impact; requires immediate response and escalation.' },
  high:     { color: '#d97706', label: 'High',     desc: 'Significant impact; must be addressed urgently.' },
  medium:   { color: '#0284c7', label: 'Medium',   desc: 'Moderate impact; should be addressed promptly.' },
  low:      { color: '#059669', label: 'Low',       desc: 'Minor impact; can be handled in normal workflow.' },
};

function updateSeverityPreview(val) {
  const d = sevData[val] || sevData.medium;
  const badge = document.getElementById('severityBadge');
  const desc  = document.getElementById('severityDesc');
  badge.textContent = d.label;
  badge.style.color      = d.color;
  badge.style.background = d.color + '20';
  badge.style.borderColor = d.color + '40';
  desc.textContent = d.desc;
}
updateSeverityPreview(document.getElementById('severity').value);
</script>

<?php
$content = ob_get_clean();
require AEGIS_ROOT . '/views/layout.php';
