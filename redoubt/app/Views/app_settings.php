<?php
/**
 * Program Settings view. Provided by SettingsController::index().
 * In scope: $user, $NONCE, $programs, $programId, $branding, $jobsDefaultProgramWide, $csrf.
 */
use Redoubt\Support\Security;

$title = 'Settings';
$navActive = 'settings';
$breadcrumbs = ['Home' => '/app', 'Settings' => null];
$appScript = '/assets/settings.js';
require __DIR__ . '/partials/app_header.php';

$b = $branding ?? ['logoUrl' => null, 'displayName' => null, 'accent' => null];
$saved = !empty($_GET['saved']);
?>
<div class="page-header">
  <h1 class="page-title">Settings</h1>
  <form method="get" action="/app/admin/settings" style="display:flex;gap:8px;align-items:center">
    <select name="program_id" style="width:auto">
      <?php foreach ($programs as $pid => $label): ?>
        <option value="<?= (int) $pid ?>"<?= $pid === $programId ? ' selected' : '' ?>><?= Security::h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Switch</button>
  </form>
</div>

<?php if ($saved): ?><div class="card" style="border-color:var(--ok)"><span class="badge b-ok">Saved</span> Settings updated for this program.</div><?php endif; ?>

<form method="post" action="/app/admin/settings">
  <?= Security::csrfField() ?>
  <input type="hidden" name="program_id" value="<?= (int) $programId ?>">

  <div class="card">
    <h3 style="margin:0 0 4px;font-size:16px">Branding</h3>
    <p class="perm-key" style="margin:0 0 12px">Applied live across this program's portal — header brand mark, name, and accent color.</p>

    <label>Organization / product display name</label>
    <input type="text" id="displayName" name="displayName" maxlength="80" value="<?= Security::h($b['displayName'] ?? '') ?>" placeholder="REDOUBT">

    <label>Primary accent color</label>
    <div style="display:flex;gap:10px;align-items:center">
      <input type="color" id="accent" name="accent" value="<?= Security::h($b['accent'] ?: '#1e6fb8') ?>" style="width:56px;height:38px;padding:2px">
      <span class="perm-key">Applied as the portal's primary color.</span>
    </div>

    <label>Logo URL (paste an image URL, or upload below)</label>
    <input type="text" id="logoUrl" name="logoUrl" value="<?= Security::h($b['logoUrl'] ?? '') ?>" placeholder="https://…/logo.png  or  data:image/png;base64,…">
    <label>…or upload a logo (stored inline as a data: URL, works offline)</label>
    <input type="file" id="logoFile" accept="image/*" style="width:auto">
    <div style="margin-top:10px">
      <span class="perm-key">Preview:</span><br>
      <img id="logoPreview" alt="logo preview" src="<?= Security::h($b['logoUrl'] ?? '') ?>" style="max-height:48px;margin-top:6px;<?= $b['logoUrl'] ? '' : 'display:none' ?>">
    </div>
  </div>

  <div class="card">
    <h3 style="margin:0 0 4px;font-size:16px">Jobs</h3>
    <label style="display:flex;gap:8px;align-items:center;margin:6px 0 0;font-weight:500">
      <input type="checkbox" name="jobs_default_program_wide" value="1" style="width:auto"<?= !empty($jobsDefaultProgramWide) ? ' checked' : '' ?>>
      New job requisitions default to <strong>&nbsp;program-wide&nbsp;</strong> targeting
    </label>
    <p class="perm-key" style="margin:8px 0 0">When off, new requisitions start untargeted and the recruiter must choose companies/contracts.</p>
  </div>

  <div style="margin-bottom:40px"><button class="btn btn-primary" type="submit">Save settings</button></div>
</form>
<?php require __DIR__ . '/partials/app_footer.php'; ?>
