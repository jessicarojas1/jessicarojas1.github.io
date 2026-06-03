<?php
$pageTitle    = 'Edit Email Template — ' . ($template['name'] ?? 'Template');
$activeModule = 'admin_email_templates';
$breadcrumbs  = [
    ['Admin', '/admin'],
    ['Email Templates', '/admin/email-templates'],
    [Security::h($template['name'] ?? 'Edit'), null],
];

$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$templateId = (int)($template['id'] ?? 0);
$variables  = [];
if (!empty($template['variables'])) {
    $decoded = is_array($template['variables'])
        ? $template['variables']
        : (json_decode($template['variables'], true) ?? []);
    $variables = $decoded;
}

ob_start();
?>

<?php if ($flash_success): ?>
  <div class="alert alert-success" style="margin-bottom:20px"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert alert-error" style="margin-bottom:20px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Edit Email Template</h1>
    <p class="page-subtitle"><?= Security::h($template['name'] ?? '') ?></p>
  </div>
  <div class="page-actions">
    <a href="/admin/email-templates" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to Templates</a>
  </div>
</div>

<div style="display:flex;gap:24px;align-items:flex-start;max-width:1200px">

  <!-- Main form -->
  <div style="flex:2;min-width:0">
    <form method="post" action="/admin/email-templates/<?= $templateId ?>/update" id="templateForm">
      <?= Security::csrfField() ?>

      <div class="card" style="margin-bottom:20px">
        <div class="card-header">
          <div class="card-header-left">
            <i class="bi bi-envelope-fill" style="color:var(--primary)"></i>
            <span class="card-title">Template Details</span>
          </div>
        </div>
        <div class="card-body">

          <div class="form-group">
            <label class="form-label">Type</label>
            <div style="padding:8px 12px;background:var(--bg-secondary,#f8fafc);border:1px solid var(--border,#e5e7eb);border-radius:6px;font-family:monospace;font-size:13px;color:var(--text-muted)">
              <?= Security::h($template['type'] ?? '') ?>
            </div>
            <span class="form-text">Template type is fixed and cannot be changed.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="tmpl_name">Name <span style="color:#ef4444">*</span></label>
            <input type="text" id="tmpl_name" name="name" class="form-control"
                   value="<?= Security::h($template['name'] ?? '') ?>" required maxlength="255"
                   placeholder="Template display name">
          </div>

          <div class="form-group">
            <label class="form-label" for="tmpl_subject">Subject <span style="color:#ef4444">*</span></label>
            <input type="text" id="tmpl_subject" name="subject" class="form-control"
                   value="<?= Security::h($template['subject'] ?? '') ?>" required maxlength="500"
                   placeholder="e.g. [AEGIS] {{entity_name}} — action required">
            <span class="form-text">You may use <code>{{variable_name}}</code> placeholders. See the sidebar for available variables.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="tmpl_body_html">HTML Body <span style="color:#ef4444">*</span></label>
            <textarea id="tmpl_body_html" name="body_html" class="form-control"
                      rows="18" required
                      style="font-family:monospace;font-size:13px;resize:vertical"><?= Security::h($template['body_html'] ?? '') ?></textarea>
            <span class="form-text">Full HTML content of the email. Use inline styles for best compatibility.</span>
          </div>

          <div class="form-group">
            <label class="form-label" for="tmpl_body_text">Plain-Text Body</label>
            <textarea id="tmpl_body_text" name="body_text" class="form-control"
                      rows="8"
                      style="font-family:monospace;font-size:13px;resize:vertical"><?= Security::h($template['body_text'] ?? '') ?></textarea>
            <span class="form-text">Optional plain-text fallback for email clients that do not render HTML.</span>
          </div>

          <div style="display:flex;align-items:center;gap:10px;padding:14px 0;border-top:1px solid var(--border,#e5e7eb)">
            <label class="pill-toggle" title="Toggle active status">
              <input type="checkbox" id="tmpl_is_active" name="is_active" value="1"
                     <?= !empty($template['is_active']) ? 'checked' : '' ?>>
              <span class="pill-track"></span>
            </label>
            <label for="tmpl_is_active" style="font-size:14px;font-weight:500;cursor:pointer;margin:0">
              Template Active
            </label>
            <span style="font-size:13px;color:var(--text-muted)">— inactive templates will not be sent</span>
          </div>

        </div>
        <div class="card-footer" style="padding:16px 20px;border-top:1px solid var(--border,#e5e7eb);display:flex;gap:10px;align-items:center">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Template</button>
          <a href="/admin/email-templates" class="btn btn-ghost">Cancel</a>
          <button type="button" class="btn btn-secondary" style="margin-left:auto" data-click="openPreviewModal">
            <i class="bi bi-eye"></i> Preview Email
          </button>
        </div>
      </div>

    </form>
  </div>

  <!-- Sidebar: Available Variables -->
  <div style="flex:1;min-width:240px;max-width:320px">
    <div class="card" style="position:sticky;top:20px">
      <div class="card-header">
        <div class="card-header-left">
          <i class="bi bi-braces" style="color:#7c3aed"></i>
          <span class="card-title">Available Variables</span>
        </div>
      </div>
      <div class="card-body" style="padding:12px">
        <?php if (!empty($variables)): ?>
          <p style="font-size:12px;color:var(--text-muted);margin:0 0 10px">Click to copy a variable placeholder.</p>
          <div style="display:flex;flex-direction:column;gap:6px">
            <?php foreach ($variables as $varName => $varDesc):
              $placeholder = '{{' . $varName . '}}';
            ?>
              <div style="border:1px solid var(--border,#e5e7eb);border-radius:6px;padding:8px 10px;background:var(--bg-secondary,#f8fafc)">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:6px">
                  <code style="font-size:12px;color:#6d28d9;word-break:break-all"><?= Security::h($placeholder) ?></code>
                  <button type="button"
                          data-click="copyVar" data-arg="<?= Security::h($placeholder) ?>"
                          class="btn btn-ghost btn-sm"
                          title="Copy placeholder"
                          style="padding:2px 6px;flex-shrink:0">
                    <i class="bi bi-clipboard"></i>
                  </button>
                </div>
                <?php if ($varDesc): ?>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:4px"><?= Security::h($varDesc) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p style="font-size:13px;color:var(--text-muted);margin:0">No variables defined for this template type.</p>
        <?php endif; ?>

        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border,#e5e7eb)">
          <p style="font-size:12px;font-weight:600;color:var(--text-muted);margin:0 0 6px;text-transform:uppercase;letter-spacing:.04em">Syntax</p>
          <div style="background:#1e1b4b;border-radius:6px;padding:10px 12px;font-family:monospace;font-size:12px;color:#a5b4fc;overflow-x:auto">
            <span style="color:#6ee7b7">{{variable_name}}</span>
          </div>
          <p style="font-size:11px;color:var(--text-muted);margin:6px 0 0">Variable names are case-sensitive and must match exactly.</p>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Preview Modal -->
<div id="previewModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.5);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:12px;width:90vw;max-width:820px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e5e7eb">
      <h3 style="margin:0;font-size:16px;font-weight:600">Email Preview</h3>
      <button type="button" data-click="closePreviewModal" style="background:none;border:none;cursor:pointer;font-size:20px;color:#6b7280;line-height:1">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div id="previewLoading" style="padding:40px;text-align:center;color:#6b7280;display:none">
      <i class="bi bi-arrow-repeat" style="font-size:1.5rem;animation:spin 1s linear infinite"></i>
      <p style="margin:12px 0 0">Rendering preview…</p>
    </div>
    <div id="previewError" style="padding:20px;display:none">
      <div class="alert alert-error"></div>
    </div>
    <iframe id="previewFrame" style="flex:1;border:none;border-radius:0 0 12px 12px;min-height:500px" sandbox="allow-same-origin"></iframe>
  </div>
</div>

<style nonce="<?= Security::nonce() ?>">
@keyframes spin { to { transform: rotate(360deg); } }
.pill-toggle { position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0 }
.pill-toggle input[type="checkbox"] { opacity:0;width:0;height:0;position:absolute }
.pill-track { position:absolute;inset:0;border-radius:24px;background:#d1d5db;cursor:pointer;transition:background .2s }
.pill-toggle input:checked + .pill-track { background:#6366f1 }
.pill-track::before { content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .2s }
.pill-toggle input:checked + .pill-track::before { transform:translateX(20px) }
</style>

<script nonce="<?= Security::nonce() ?>">
function copyVar(text) {
    navigator.clipboard.writeText(text).then(function() {
        // brief visual feedback handled by browser
    }).catch(function() {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    });
}

function openPreviewModal() {
    var modal = document.getElementById('previewModal');
    modal.style.display = 'flex';
    loadPreview();
}

function closePreviewModal() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewFrame').src = 'about:blank';
}

function loadPreview() {
    var loading = document.getElementById('previewLoading');
    var errBox  = document.getElementById('previewError');
    var frame   = document.getElementById('previewFrame');
    loading.style.display = 'block';
    errBox.style.display  = 'none';
    frame.style.display   = 'none';

    fetch('/admin/email-templates/<?= $templateId ?>/preview', {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) {
        if (!r.ok) throw new Error('Server returned ' + r.status);
        return r.text();
    })
    .then(function(html) {
        loading.style.display = 'none';
        frame.style.display   = 'block';
        var doc = frame.contentDocument || frame.contentWindow.document;
        doc.open();
        doc.write(html);
        doc.close();
    })
    .catch(function(err) {
        loading.style.display  = 'none';
        errBox.style.display   = 'block';
        errBox.querySelector('.alert').textContent = 'Preview failed: ' + err.message;
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePreviewModal();
});
</script>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
