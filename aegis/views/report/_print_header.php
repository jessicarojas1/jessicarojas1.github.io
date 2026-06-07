<?php
/**
 * Print/PDF-only branding header for reports.
 * Hidden on screen (see .report-print-header in app.css), shown when printing.
 * Uses the configured branding logo + display name, falling back to the
 * built-in shield mark + app name when unset.
 *
 * Expects optional $reportTitle and $orgName already in scope.
 */
$__bLogo = Branding::logo();
$__bName = Branding::name();
$__rTitle = $reportTitle ?? ($pageTitle ?? 'Report');
?>
<div class="report-print-header">
  <?php if ($__bLogo): ?>
    <img src="<?= Security::h($__bLogo) ?>" alt="<?= Security::h($__bName) ?> logo">
  <?php else: ?>
    <i class="bi bi-shield-fill-check" style="font-size:32px;color:#111"></i>
  <?php endif; ?>
  <div>
    <div class="rph-name"><?= Security::h($__bName) ?></div>
    <div class="rph-sub"><?= Security::h($__rTitle) ?><?php if (!empty($orgName)): ?> &mdash; <?= Security::h($orgName) ?><?php endif; ?></div>
  </div>
</div>
