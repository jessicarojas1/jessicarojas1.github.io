<?php
// Consume one-time codes from session immediately
$newCodes = $_SESSION['new_backup_codes'] ?? [];
unset($_SESSION['new_backup_codes']);
$hasNewCodes = !empty($newCodes);
?>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert-error" style="margin-bottom:20px">
    <i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($_SESSION['flash_error']) ?>
  </div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><i class="bi bi-shield-lock" style="color:var(--primary)"></i> MFA Backup Codes</h1>
    <p class="page-subtitle">One-time recovery codes for your account</p>
  </div>
  <div class="page-actions">
    <a href="/mfa/setup" class="btn btn-ghost"><i class="bi bi-arrow-left"></i> Back to 2FA Settings</a>
  </div>
</div>

<?php if ($hasNewCodes): ?>
  <!-- ── New codes just generated ─────────────────────────────────── -->
  <div class="alert alert-success" style="margin-bottom:24px;font-size:15px;font-weight:600">
    <i class="bi bi-check-circle-fill"></i>
    Save these codes now — they won't be shown again.
  </div>

  <div class="card" style="max-width:640px">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-key-fill" style="color:#d97706"></i>
        <span class="card-title">Your Recovery Codes</span>
      </div>
      <div class="card-header-right" style="display:flex;gap:8px">
        <button type="button" class="btn btn-secondary btn-sm" id="btnDownloadCodes">
          <i class="bi bi-download"></i> Download
        </button>
        <button type="button" class="btn btn-secondary btn-sm" id="btnPrintCodes">
          <i class="bi bi-printer"></i> Print
        </button>
      </div>
    </div>
    <div class="card-body">

      <div id="codesGrid" style="
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:10px 24px;
        font-family:monospace;
        font-size:17px;
        font-weight:600;
        letter-spacing:0.08em;
        background:var(--bg-body);
        border:1px solid var(--border);
        border-radius:8px;
        padding:20px 24px;
        margin-bottom:20px;
      ">
        <?php foreach ($newCodes as $code): ?>
          <div style="padding:6px 0;border-bottom:1px solid var(--border-light);color:var(--text)">
            <?= Security::h($code) ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="alert alert-error" style="font-size:13px">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong>Important:</strong> Each code can only be used once. Store them somewhere safe — a password manager, printed paper in a secure location, or encrypted file. These codes will not be shown again.
      </div>

    </div>
  </div>

  <script nonce="<?= Security::nonce() ?>">
  var backupCodes = <?= json_encode($newCodes) ?>;

  function downloadCodes() {
    var lines = [
      'AEGIS GRC — MFA Backup Recovery Codes',
      'Generated: ' + new Date().toLocaleString(),
      '',
      'Each code may be used only once.',
      '',
    ].concat(backupCodes);
    var content = lines.join('\n');
    var blob = new Blob([content], { type: 'text/plain' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'aegis-backup-codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
  }

  document.getElementById('btnDownloadCodes').addEventListener('click', function() { downloadCodes(); });
  document.getElementById('btnPrintCodes').addEventListener('click', function() { window.print(); });
  </script>

<?php else: ?>
  <!-- ── No new codes — show status ──────────────────────────────── -->
  <div class="card" style="max-width:640px">
    <div class="card-header">
      <div class="card-header-left">
        <i class="bi bi-key-fill" style="color:#d97706"></i>
        <span class="card-title">Backup Code Status</span>
      </div>
    </div>
    <div class="card-body">

      <p style="font-size:15px;margin-bottom:16px">
        You have <strong><?= (int)$existingCount ?></strong> unused backup code<?= $existingCount !== 1 ? 's' : '' ?> remaining.
      </p>

      <?php if ((int)$existingCount < 3): ?>
        <div class="alert alert-error" style="margin-bottom:16px;font-size:13px">
          <i class="bi bi-exclamation-triangle-fill"></i>
          You are running low on backup codes. Consider regenerating a fresh set.
        </div>
      <?php endif; ?>

      <div class="alert" style="background:var(--bg-body);border:1px solid var(--border);margin-bottom:20px;font-size:13px">
        <i class="bi bi-info-circle" style="color:var(--primary)"></i>
        Regenerating codes will <strong>immediately invalidate</strong> all existing unused codes. Make sure you save the new codes before leaving the page — they are displayed only once.
      </div>

      <form method="POST" action="/mfa/backup-codes/generate"
            onsubmit="return confirm('Regenerate all backup codes? This will invalidate your existing codes.')">
        <?= Security::csrfField() ?>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-arrow-clockwise"></i> Regenerate Backup Codes
        </button>
      </form>

    </div>
  </div>
<?php endif; ?>

<div class="card" style="max-width:640px;margin-top:20px">
  <div class="card-body" style="font-size:13px;color:var(--text-muted)">
    <i class="bi bi-shield-check" style="color:var(--primary)"></i>
    Lost your authenticator app?
    <a href="/mfa/verify" style="color:var(--primary)">Use a backup code to sign in</a> — enter the code on the MFA verification page.
  </div>
</div>
