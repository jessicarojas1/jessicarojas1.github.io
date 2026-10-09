<?php
/** @var array $branding @var string $csrf */
use Verity\Support\Security;

require __DIR__ . '/partials/app_header.php';
?>
<div class="page-header">
  <div><h1 class="page-title">Platform Settings</h1><p>Branding is applied live across the application — header, document title, and generated reports.</p></div>
</div>

<style id="livePreviewStyle" nonce="<?= Security::h($NONCE) ?>"></style>
<div class="card max-w-520">
  <h2>Branding</h2>
  <form method="post" action="/app/admin/settings" id="brandingForm">
    <?= $csrf ?>
    <div class="field">
      <label for="displayName">Organization / Product Display Name</label>
      <input type="text" id="displayName" name="displayName" value="<?= Security::h($branding['displayName'] ?? '') ?>" placeholder="Verity">
    </div>
    <div class="field">
      <label for="logoUrl">Logo URL</label>
      <input type="text" id="logoUrl" name="logoUrl" value="<?= Security::h($branding['logoUrl'] ?? '') ?>" placeholder="https://… or leave blank">
      <p class="hint">Only http(s):// URLs or data:image/... are accepted; anything else is dropped at save time.</p>
    </div>
    <div class="field">
      <label for="logoFile">Or upload a logo file (stored as a data: URL)</label>
      <input type="file" id="logoFile" accept="image/*">
    </div>
    <div class="field">
      <label for="accent">Accent Color</label>
      <input type="color" id="accent" name="accent" value="<?= Security::h($branding['accent'] ?? '#2b5fd9') ?>" class="color-swatch">
    </div>
    <div id="brandPreview" class="card mb-12">
      <strong>Preview:</strong>
      <span id="previewName"><?= Security::h($branding['displayName'] ?: 'Verity') ?></span>
    </div>
    <button type="submit" class="btn primary">Save Branding</button>
  </form>
</div>

<?php require __DIR__ . '/partials/app_footer.php'; ?>
