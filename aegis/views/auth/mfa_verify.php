<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Two-Factor Authentication — AEGIS GRC</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/public/css/app.css">
<style>
  body { background: var(--bg-body); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .login-box { width: 100%; max-width: 400px; padding: 16px; }
  .login-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; padding: 40px 36px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
  .login-brand { text-align: center; margin-bottom: 28px; }
  .login-brand-icon { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, #4f46e5, #7c3aed); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
  .login-brand-icon i { color: #fff; font-size: 26px; }
  .login-title { font-size: 20px; font-weight: 700; margin: 0 0 4px; }
  .login-sub { font-size: 13px; color: var(--text-muted); margin: 0; }
  .mfa-digits { display: flex; gap: 8px; justify-content: center; margin: 20px 0; }
  .mfa-digits input { width: 44px; height: 52px; text-align: center; font-size: 22px; font-weight: 700; border: 2px solid var(--border); border-radius: 8px; background: var(--bg-body); color: var(--text); transition: border-color 0.2s; }
  .mfa-digits input:focus { outline: none; border-color: var(--primary); }
</style>
</head>
<body>
<div class="login-box">
  <div class="login-card">
    <div class="login-brand">
      <div class="login-brand-icon"><i class="bi bi-shield-lock-fill"></i></div>
      <h1 class="login-title">Two-Factor Auth</h1>
      <p class="login-sub">Enter the 6-digit code from your authenticator app</p>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert alert-error" style="margin-bottom:16px"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="/mfa/verify" id="mfaForm">
      <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
      <div class="mfa-digits" id="digitBoxes">
        <?php for ($i = 0; $i < 6; $i++): ?>
          <input type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="off"
                 id="d<?= $i ?>" onkeyup="digitNext(this, <?= $i ?>)" onpaste="handlePaste(event)">
        <?php endfor; ?>
      </div>
      <input type="hidden" name="code" id="codeInput">

      <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px" onclick="assembleCode()">
        <i class="bi bi-shield-check"></i> Verify
      </button>
    </form>

    <div style="text-align:center;margin-top:16px;font-size:13px;color:var(--text-muted)">
      <a href="/logout" style="color:var(--text-muted)">Cancel &amp; Log Out</a>
    </div>

    <!-- Backup code toggle -->
    <div style="text-align:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
      <button type="button" id="toggleBackupBtn" onclick="toggleBackupForm()"
              style="background:none;border:none;cursor:pointer;font-size:13px;color:var(--primary);text-decoration:underline;padding:0">
        Use a backup code instead
      </button>
    </div>

    <!-- Backup code form (hidden by default) -->
    <div id="backupCodeSection" style="display:none;margin-top:16px">
      <form method="POST" action="/mfa/backup-verify" id="backupForm">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div style="margin-bottom:12px">
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--text)">
            Recovery Code
          </label>
          <input type="text" name="backup_code" id="backupCodeInput"
                 class="form-control"
                 placeholder="XXXX-XXXX"
                 autocomplete="off"
                 style="text-align:center;font-family:monospace;font-size:16px;letter-spacing:0.1em;text-transform:uppercase"
                 maxlength="9">
          <p style="font-size:12px;color:var(--text-muted);margin:6px 0 0">
            Enter one of the 8-character backup codes you saved when enabling MFA.
          </p>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="bi bi-key"></i> Verify Backup Code
        </button>
      </form>
      <div style="text-align:center;margin-top:10px">
        <button type="button" onclick="toggleBackupForm()"
                style="background:none;border:none;cursor:pointer;font-size:13px;color:var(--text-muted);text-decoration:underline;padding:0">
          Back to authenticator code
        </button>
      </div>
    </div>

  </div>
</div>

<script>
function digitNext(el, idx) {
  const val = el.value.replace(/\D/g,'');
  el.value = val;
  if (val && idx < 5) {
    document.getElementById('d' + (idx + 1)).focus();
  }
  if (idx === 5 && val) {
    assembleCode();
    document.getElementById('mfaForm').submit();
  }
}

function handlePaste(e) {
  e.preventDefault();
  const digits = (e.clipboardData.getData('text') || '').replace(/\D/g,'').slice(0, 6).split('');
  digits.forEach(function(d, i) {
    const box = document.getElementById('d' + i);
    if (box) box.value = d;
  });
  const last = document.getElementById('d' + (digits.length - 1));
  if (last) last.focus();
  if (digits.length === 6) {
    assembleCode();
    document.getElementById('mfaForm').submit();
  }
}

function assembleCode() {
  let code = '';
  for (let i = 0; i < 6; i++) { code += document.getElementById('d' + i).value; }
  document.getElementById('codeInput').value = code;
}

function showBackupSection() {
  var totpSection  = document.getElementById('digitBoxes');
  var totpSubmit   = document.querySelector('#mfaForm button[type="submit"]');
  var backupSec    = document.getElementById('backupCodeSection');
  var toggleBtn    = document.getElementById('toggleBackupBtn');
  backupSec.style.display   = 'block';
  totpSection.style.display = 'none';
  totpSubmit.style.display  = 'none';
  toggleBtn.textContent     = 'Use authenticator code instead';
  backupSec.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  setTimeout(function() { document.getElementById('backupCodeInput').focus(); }, 100);
}

function hideBackupSection() {
  var totpSection  = document.getElementById('digitBoxes');
  var totpSubmit   = document.querySelector('#mfaForm button[type="submit"]');
  var backupSec    = document.getElementById('backupCodeSection');
  var toggleBtn    = document.getElementById('toggleBackupBtn');
  backupSec.style.display   = 'none';
  totpSection.style.display = '';
  totpSubmit.style.display  = '';
  toggleBtn.textContent     = 'Use a backup code instead';
  if (!window.matchMedia('(hover: none)').matches) {
    document.getElementById('d0').focus();
  }
}

function toggleBackupForm() {
  var backupSec = document.getElementById('backupCodeSection');
  if (backupSec.style.display !== 'none') {
    hideBackupSection();
  } else {
    showBackupSection();
  }
}

// Only auto-focus the digit box on non-touch (desktop) devices
if (!window.matchMedia('(hover: none)').matches) {
  document.getElementById('d0').focus();
}

// Auto-open backup section if redirected back after a failed backup attempt
if (new URLSearchParams(window.location.search).get('mode') === 'backup') {
  showBackupSection();
}

// Auto-format backup code input: insert hyphen after 4 chars
document.getElementById('backupCodeInput').addEventListener('input', function() {
  var val = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  if (val.length > 4) {
    val = val.slice(0, 4) + '-' + val.slice(4, 8);
  }
  this.value = val;
});
</script>
</body>
</html>
