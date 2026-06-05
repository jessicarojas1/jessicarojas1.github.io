<?php
ob_start();
$flash_success = $_SESSION['flash_success'] ?? null;
$flash_error   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Two-Factor Authentication</h1>
    <p class="page-subtitle">Add an extra layer of security to your account with a TOTP authenticator app</p>
  </div>
</div>

<?php if ($flash_success): ?>
  <div class="alert-box success"><i class="bi bi-check-circle-fill"></i> <?= Security::h($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
  <div class="alert-box error"><i class="bi bi-exclamation-triangle-fill"></i> <?= Security::h($flash_error) ?></div>
<?php endif; ?>

<?php if ($isMfaEnabled): ?>
<!-- ── MFA Enabled State ──────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:860px">

  <div class="card">
    <div class="card-body" style="padding:28px">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
        <div style="width:56px;height:56px;border-radius:50%;background:#05966920;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="bi bi-shield-check-fill" style="color:#059669;font-size:26px"></i>
        </div>
        <div>
          <div style="font-size:17px;font-weight:700;color:#059669">2FA is Active</div>
          <div style="font-size:13px;color:var(--text-muted);margin-top:2px">Your account is protected</div>
        </div>
      </div>
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;line-height:1.6">
        Two-factor authentication is enabled. Each login requires a 6-digit code from your authenticator app.
      </p>
      <div style="background:var(--success-subtle,#f0fdf4);border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:20px">
        <div style="font-size:12px;font-weight:600;color:#059669;margin-bottom:4px"><i class="bi bi-check-circle-fill"></i> PROTECTION ACTIVE</div>
        <div style="font-size:12px;color:var(--text-muted)">Unauthorized login attempts are blocked without your authenticator code.</div>
      </div>
      <form method="post" action="/mfa/disable" data-confirm="Are you sure you want to disable two-factor authentication? This will reduce your account security.">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-shield-x"></i> Disable 2FA</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title"><i class="bi bi-key-fill"></i> Backup Codes</h3></div>
    <div class="card-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.6">
        Backup codes let you access your account if you lose your authenticator device. Store them somewhere safe.
      </p>
      <a href="/mfa/backup-codes" class="btn btn-secondary btn-sm"><i class="bi bi-download"></i> View Backup Codes</a>
    </div>
  </div>

</div>

<?php else: ?>
<!-- ── MFA Setup State ────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:24px;align-items:flex-start">

  <!-- Left: setup form -->
  <div>
    <!-- Status warning -->
    <div class="card" style="margin-bottom:20px;border-left:4px solid #d97706">
      <div class="card-body" style="display:flex;align-items:center;gap:14px;padding:18px 20px">
        <div style="width:44px;height:44px;border-radius:50%;background:#d9770618;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="bi bi-shield-exclamation" style="color:#d97706;font-size:20px"></i>
        </div>
        <div>
          <div style="font-size:15px;font-weight:600">2FA is Not Enabled</div>
          <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Your account is only protected by a password. Enable 2FA for better security.</div>
        </div>
      </div>
    </div>

    <!-- Setup card -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title"><i class="bi bi-qr-code" style="color:var(--primary)"></i> Scan QR Code to Setup</h3>
      </div>
      <div class="card-body">

        <!-- Steps -->
        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px">
          <?php foreach ([
            ['1','Install an authenticator app','Google Authenticator, Authy, 1Password, or Microsoft Authenticator'],
            ['2','Scan the QR code','Use your app to scan the code below, or enter the key manually'],
            ['3','Enter the 6-digit code','Type the code shown in your app to verify and activate'],
          ] as [$n, $title, $sub]): ?>
          <div style="display:flex;align-items:flex-start;gap:12px">
            <div style="width:24px;height:24px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;margin-top:2px"><?= $n ?></div>
            <div>
              <div style="font-size:13px;font-weight:600"><?= $title ?></div>
              <div style="font-size:12px;color:var(--text-muted)"><?= $sub ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- QR Code -->
        <div style="text-align:center;margin-bottom:24px">
          <div style="display:inline-block;padding:16px;background:#fff;border-radius:12px;border:2px solid var(--border);box-shadow:0 2px 12px rgba(0,0,0,.08)">
            <canvas id="qrCanvas" width="180" height="180"></canvas>
          </div>
          <div style="margin-top:12px">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:6px">Can't scan? Enter this key manually in your authenticator:</p>
            <div style="display:inline-block;background:var(--bg-secondary);border:1px solid var(--border);border-radius:8px;padding:8px 16px">
              <code style="font-size:15px;letter-spacing:4px;font-weight:700;color:var(--primary);word-break:break-all"><?= chunk_split(Security::h($secret), 4, ' ') ?></code>
            </div>
          </div>
        </div>

        <!-- Verify form -->
        <form method="post" action="/mfa/setup/verify">
          <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
          <div class="form-group">
            <label class="form-label" for="code">Verification Code</label>
            <input type="text" id="code" name="code" class="form-control"
                   style="max-width:180px;font-size:22px;letter-spacing:6px;text-align:center;font-weight:700"
                   inputmode="numeric" pattern="[0-9 ]{6,7}"
                   placeholder="000000" autocomplete="one-time-code" maxlength="7" autofocus>
            <span class="form-text">Enter the 6-digit code from your authenticator app.</span>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-shield-check"></i> Enable 2FA</button>
        </form>

      </div>
    </div>
  </div>

  <!-- Right: info panel -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-phone-fill" style="color:var(--primary)"></i> Supported Apps</h3></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:10px;padding:16px">
        <?php foreach ([
          ['Google Authenticator','Free · iOS &amp; Android','bi-google','#4285f4'],
          ['Authy','Free · iOS, Android, Desktop','bi-phone','#ec1c24'],
          ['1Password','Paid · All platforms','bi-lock-fill','#1a8cff'],
          ['Microsoft Authenticator','Free · iOS &amp; Android','bi-microsoft','#00a4ef'],
          ['Bitwarden','Free/Paid · All platforms','bi-shield-fill','#175ddc'],
        ] as [$name, $desc, $icon, $color]): ?>
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:32px;height:32px;border-radius:8px;background:<?= $color ?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:15px"></i>
          </div>
          <div>
            <div style="font-size:13px;font-weight:600"><?= $name ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?= $desc ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-info-circle-fill" style="color:var(--primary)"></i> Why Enable 2FA?</h3></div>
      <div class="card-body" style="padding:16px">
        <div style="display:flex;flex-direction:column;gap:8px">
          <?php foreach ([
            ['bi-shield-fill-check','#059669','Blocks unauthorized access even if your password is stolen'],
            ['bi-eye-slash-fill','#0284c7','Protects sensitive GRC data from account takeovers'],
            ['bi-patch-check-fill','#8b5cf6','Required by many compliance frameworks (NIST, ISO 27001)'],
            ['bi-clock-fill','#d97706','Takes just 30 seconds to set up'],
          ] as [$icon, $color, $text]): ?>
          <div style="display:flex;align-items:flex-start;gap:10px">
            <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:14px;margin-top:2px;flex-shrink:0"></i>
            <span style="font-size:12px;color:var(--text-muted);line-height:1.5"><?= $text ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title"><i class="bi bi-life-preserver" style="color:var(--warning)"></i> Lost Access?</h3></div>
      <div class="card-body" style="padding:16px">
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.5">After enabling 2FA, you'll receive backup codes. Store them securely — they can be used to access your account if you lose your device.</p>
        <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:6px;padding:8px 10px;font-size:11px;color:var(--text-muted)">
          <i class="bi bi-exclamation-triangle-fill" style="color:var(--warning)"></i>
          Contact your system administrator if you're locked out and don't have backup codes.
        </div>
      </div>
    </div>

  </div>
</div>
<?php endif; ?>

<script nonce="<?= Security::nonce() ?>">
(function () {
  var canvas = document.getElementById('qrCanvas');
  if (!canvas) return;
  var uri = <?= json_encode($qrUri ?? '') ?>;
  if (!uri) return;

  // Simple QR code via Google Charts API with fallback to text
  var img = new Image();
  img.onload = function () {
    var ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0, 180, 180);
  };
  img.onerror = function () {
    // Fallback: show the URI text
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#f8fafc';
    ctx.fillRect(0, 0, 180, 180);
    ctx.fillStyle = '#94a3b8';
    ctx.font = '11px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('QR unavailable —', 90, 80);
    ctx.fillText('use the key below', 90, 96);
  };
  img.src = 'https://chart.googleapis.com/chart?cht=qr&chs=180x180&chld=M|1&chl=' + encodeURIComponent(uri);
})();

// Auto-format verification code input: accept only digits, add space after 3
(function () {
  var codeInput = document.getElementById('code');
  if (!codeInput) return;
  codeInput.addEventListener('input', function () {
    var raw = codeInput.value.replace(/\D/g, '').slice(0, 6);
    codeInput.value = raw;
  });
})();
</script>

<?php $content = ob_get_clean(); require AEGIS_ROOT . '/views/layout.php'; ?>
