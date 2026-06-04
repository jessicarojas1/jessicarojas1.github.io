<?php $csrf = Security::generateCsrfToken(); ?>
<div class="page-header">
  <div>
    <h1 class="page-title">New System Security Plan</h1>
    <p class="page-subtitle">Define system boundaries and link compliance packages to document security controls</p>
  </div>
</div>

<form method="POST" action="/ssp/create" enctype="multipart/form-data">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:start;">
    <!-- Left column -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <!-- Basic Info -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">Plan Information</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:16px;">
          <div class="form-group">
            <label class="form-label">Plan Title <span style="color:var(--danger)">*</span></label>
            <input type="text" name="title" class="form-control" required placeholder="e.g. NIST 800-171 SSP — HR System">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label">System Name</label>
              <input type="text" name="system_name" class="form-control" placeholder="e.g. HR Portal">
            </div>
            <div class="form-group">
              <label class="form-label">System Owner</label>
              <input type="text" name="system_owner" class="form-control" placeholder="Full name">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label class="form-label">System Owner Email</label>
              <input type="email" name="system_owner_email" class="form-control" placeholder="owner@org.com">
            </div>
            <div class="form-group">
              <label class="form-label">Information Owner</label>
              <input type="text" name="information_owner" class="form-control">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Authorizing Official</label>
            <input type="text" name="authorizing_official" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">System Description</label>
            <textarea name="system_description" class="form-control" rows="3" placeholder="Describe the system's purpose, functionality, and users"></textarea>
          </div>
        </div>
      </div>

      <!-- Boundary & Architecture -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">System Boundaries</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:16px;">
          <div class="form-group">
            <label class="form-label">Authorization Boundary</label>
            <textarea name="authorization_boundary" class="form-control" rows="3" placeholder="Describe what is included/excluded from this system boundary"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Network Architecture</label>
            <textarea name="network_architecture" class="form-control" rows="3" placeholder="Describe network topology, connections, and data flows"></textarea>
            <div style="margin-top:10px;">
              <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);">Upload Diagram <span style="font-weight:400;">(optional)</span></label>
              <label class="file-drop" id="fileDropNetArch" for="netArchFile" style="padding:20px;">
                <i class="bi bi-diagram-3" style="font-size:1.75rem;color:var(--primary)"></i>
                <p style="margin:6px 0 0;">Drag &amp; drop or <strong>click to upload</strong></p>
                <p class="text-muted" style="margin:4px 0 0;font-size:0.8rem;">PDF, PNG, JPG, SVG, VSDX · max 10MB</p>
              </label>
              <input type="file" id="netArchFile" name="network_arch_file" accept=".pdf,.png,.jpg,.jpeg,.gif,.svg,.vsdx,.docx,.pptx" style="display:none"
                     data-change="showFileChange" data-drop-id="fileDropNetArch" data-name-id="netArchName" data-color="var(--primary)">
              <div id="netArchName" style="margin-top:6px;color:var(--primary);display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Data Flow</label>
            <textarea name="data_flow" class="form-control" rows="3" placeholder="Describe how data enters, is processed, and exits the system"></textarea>
            <div style="margin-top:10px;">
              <label class="form-label" style="font-size:0.8rem;color:var(--text-muted);">Upload Diagram <span style="font-weight:400;">(optional)</span></label>
              <label class="file-drop" id="fileDropDataFlow" for="dataFlowFile" style="padding:20px;">
                <i class="bi bi-diagram-2" style="font-size:1.75rem;color:var(--primary)"></i>
                <p style="margin:6px 0 0;">Drag &amp; drop or <strong>click to upload</strong></p>
                <p class="text-muted" style="margin:4px 0 0;font-size:0.8rem;">PDF, PNG, JPG, SVG, VSDX · max 10MB</p>
              </label>
              <input type="file" id="dataFlowFile" name="data_flow_file" accept=".pdf,.png,.jpg,.jpeg,.gif,.svg,.vsdx,.docx,.pptx" style="display:none"
                     data-change="showFileChange" data-drop-id="fileDropDataFlow" data-name-id="dataFlowName" data-color="var(--primary)">
              <div id="dataFlowName" style="margin-top:6px;color:var(--primary);display:none"><i class="bi bi-file-earmark-check"></i> <span></span></div>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Right column -->
    <div style="display:flex;flex-direction:column;gap:20px;">

      <!-- Categorization -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">System Categorization</h3></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">
          <div class="form-group">
            <label class="form-label">Operational Status</label>
            <select name="operational_status" class="form-control">
              <option value="operational">Operational</option>
              <option value="under_development">Under Development</option>
              <option value="major_modification">Major Modification</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">System Type</label>
            <select name="system_type" class="form-control">
              <option value="major_application">Major Application</option>
              <option value="general_support_system">General Support System</option>
              <option value="minor_application">Minor Application</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Confidentiality Impact</label>
            <select name="confidentiality_impact" class="form-control">
              <option value="low">Low</option>
              <option value="moderate" selected>Moderate</option>
              <option value="high">High</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Integrity Impact</label>
            <select name="integrity_impact" class="form-control">
              <option value="low">Low</option>
              <option value="moderate" selected>Moderate</option>
              <option value="high">High</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Availability Impact</label>
            <select name="availability_impact" class="form-control">
              <option value="low">Low</option>
              <option value="moderate" selected>Moderate</option>
              <option value="high">High</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Authorization Date</label>
            <input type="date" name="authorization_date" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Next Review Date</label>
            <input type="date" name="next_review_date" class="form-control">
          </div>
        </div>
      </div>

      <!-- Package Selection -->
      <div class="card">
        <div class="card-header"><h3 class="card-title">Compliance Packages <span style="color:var(--danger)">*</span></h3></div>
        <div class="card-body">
          <?php if (empty($packages)): ?>
            <p style="color:var(--text-muted);font-size:0.875rem;">No active compliance packages. <a href="/compliance/import">Import one first.</a></p>
          <?php else: ?>
            <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;">Select one or more packages to include in this SSP.</p>
            <div style="display:flex;flex-direction:column;gap:10px;max-height:320px;overflow-y:auto;">
              <?php foreach ($packages as $pkg): ?>
              <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px;border:1px solid var(--border);border-radius:8px;">
                <input type="checkbox" name="package_ids[]" value="<?= (int)$pkg['id'] ?>" style="margin-top:2px;">
                <div>
                  <div style="font-weight:600;font-size:0.875rem;"><?= Security::h($pkg['name']) ?></div>
                  <div style="font-size:0.78rem;color:var(--text-muted);">
                    <?= Security::h($pkg['standard_code']) ?>
                    <?= $pkg['version'] ? '· v' . Security::h($pkg['version']) : '' ?>
                    · <?= (int)$pkg['control_count'] ?> controls
                  </div>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>

  <div style="margin-top:20px;display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary">Create System Security Plan</button>
    <a href="/ssp" class="btn btn-secondary">Cancel</a>
  </div>
</form>
