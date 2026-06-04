<?php $csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div><h1 class="page-title">New GRC Project</h1></div>
  <a href="/projects" class="btn btn-secondary">Cancel</a>
</div>

<form method="POST" action="/projects/create">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">
    <div style="display:flex;flex-direction:column;gap:20px;">

      <div class="card">
        <div class="card-header"><h3 class="card-title">Project Details</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
          <div class="form-group">
            <label class="form-label">Project Title <span style="color:var(--danger)">*</span></label>
            <input type="text" name="title" class="form-control" required placeholder="e.g. SOC 2 Remediation Initiative">
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3" placeholder="Describe the project scope and objectives..."></textarea>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group">
              <label class="form-label">Priority</label>
              <select name="priority" class="form-control">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Status</label>
              <select name="status" class="form-control">
                <option value="planning" selected>Planning</option>
                <option value="active">Active</option>
                <option value="on_hold">On Hold</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Schedule</h3></div>
        <div class="card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div class="form-group">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control">
          </div>
        </div>
      </div>

    </div>
    <div style="display:flex;flex-direction:column;gap:20px;">

      <div class="card">
        <div class="card-header"><h3 class="card-title">Team</h3></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Project Lead</label>
            <select name="project_lead" class="form-control">
              <option value="">— Unassigned —</option>
              <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= Security::h($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Budget</h3></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Planned Budget ($)</label>
            <input type="number" name="budget_planned" class="form-control" min="0" step="0.01" placeholder="0.00">
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;">Create Project</button>
    </div>
  </div>
</form>
