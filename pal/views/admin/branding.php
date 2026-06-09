<?php
$pageTitle    = 'Branding';
$activeModule = 'admin_branding';
$breadcrumbs  = [['Administration', '/admin'], ['Branding', null]];
ob_start();
$__name   = Branding::name();
$__accent = Branding::accent();
$__logo   = Branding::logo();
?>
<div class="page-header">
  <div><h1 class="page-title">Branding</h1><p class="page-subtitle">Customize the logo, display name and accent colour</p></div>
</div>

<div class="iam" style="grid-template-columns:1fr 320px">
  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-palette-fill"></i> Identity</span></div></div>
    <div class="card-body">
      <form method="POST" action="/admin/branding" enctype="multipart/form-data">
        <?= Security::csrfField() ?>

        <div class="form-group">
          <label class="form-label" for="org_name">Organization / Product Name</label>
          <input type="text" id="org_name" name="org_name" class="form-control" value="<?= Security::h($__name) ?>" maxlength="120">
          <div class="form-hint">Replaces the app name in the header and document title.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="brand_accent">Accent Colour</label>
          <div class="form-row" style="align-items:center;gap:10px">
            <input type="color" id="brand_accent" name="brand_accent" class="form-control" style="max-width:64px;padding:4px" value="<?= Security::h($__accent) ?>">
            <input type="text" id="brand_accent_text" class="form-control" style="max-width:140px" value="<?= Security::h($__accent) ?>" maxlength="7">
            <span id="brandAccentSwatch" style="display:inline-block;width:28px;height:28px;border-radius:6px;border:1px solid var(--border,#cbd5e1);background:<?= Security::h($__accent) ?>"></span>
          </div>
          <div class="form-hint">Applied across the app via a CSS custom property.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="logo_url">Logo URL</label>
          <input type="url" id="logo_url" name="logo_url" class="form-control" placeholder="https://example.com/logo.png" value="<?= Security::h(str_starts_with($__logo, 'http') ? $__logo : '') ?>">
          <div class="form-hint">Paste an image URL (http/https). Leave blank to use an upload below or the default mark.</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="logo_file">Upload Logo</label>
          <input type="file" id="logo_file" name="logo_file" class="form-control" accept="image/*">
          <div class="form-hint">
            Stored inline as a data: URI so it works offline. Overrides the URL above.
            <br><strong>Field reference:</strong> <code>logo_file</code> — image only (PNG, JPG, GIF, WebP, SVG).
          </div>
        </div>

        <?php if ($__logo !== ''): ?>
        <div class="form-group">
          <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="remove_logo" value="1"> Remove current logo (revert to default mark)
          </label>
        </div>
        <?php endif; ?>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Branding</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-header-left"><span class="card-title"><i class="bi bi-eye-fill"></i> Live Preview</span></div></div>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:12px;padding:12px;border:1px solid var(--border,#e2e8f0);border-radius:10px">
        <img id="brandPreviewLogo" alt="Logo preview"
             src="<?= Security::h($__logo) ?>"
             style="width:40px;height:40px;object-fit:contain;border-radius:8px;<?= $__logo === '' ? 'display:none' : '' ?>">
        <span id="brandPreviewLogoIcon" class="brand-icon" style="<?= $__logo === '' ? '' : 'display:none' ?>"><i class="bi bi-journal-richtext"></i></span>
        <span id="brandPreviewName" style="font-weight:700;font-size:16px"><?= Security::h($__name) ?></span>
      </div>
      <div class="form-hint" style="margin-top:10px">A broken logo URL automatically falls back to the default mark.</div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require PAL_ROOT . '/views/layout.php';
