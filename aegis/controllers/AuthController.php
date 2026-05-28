<?php
class AuthController {
    public function loginForm(): void {
        if (Auth::check()) { header('Location: /'); exit; }
        $error   = $_SESSION['login_error'] ?? null;
        $success = $_SESSION['login_success'] ?? null;
        unset($_SESSION['login_error'], $_SESSION['login_success']);
        require AEGIS_ROOT . '/views/auth/login.php';
    }

    public function login(): void {
        if (Auth::check()) { header('Location: /'); exit; }

        $token = $_POST['csrf_token'] ?? '';
        if (!Security::validateCsrf($token)) {
            $_SESSION['login_error'] = 'Invalid request. Please try again.';
            header('Location: /login'); exit;
        }

        $email    = Security::sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $_SESSION['login_error'] = 'Email and password are required.';
            header('Location: /login'); exit;
        }

        if (Auth::login($email, $password)) {
            // Check if MFA is enabled for this user
            $user = Database::fetchOne("SELECT mfa_enabled, mfa_secret FROM users WHERE id = ?", [Auth::id()]);
            if (!empty($user['mfa_enabled']) && !empty($user['mfa_secret'])) {
                // Store that password was verified, require MFA step
                $_SESSION['mfa_pending']  = true;
                $_SESSION['mfa_user_id']  = Auth::id();
                // Temporarily un-auth until MFA passes
                $saved = $_SESSION['user'];
                session_unset();
                session_destroy();
                session_start();
                $_SESSION['mfa_pending']      = true;
                $_SESSION['mfa_user_id']      = $saved['id'];
                $_SESSION['mfa_redirect']     = $_SESSION['redirect_after_login'] ?? '/';
                header('Location: /mfa/verify'); exit;
            }

            $redirect = $_SESSION['redirect_after_login'] ?? '/';
            unset($_SESSION['redirect_after_login']);
            // Only honour the stored redirect if it's a non-admin, non-auth page
            if (!preg_match('#^/[a-zA-Z0-9/_?=&%.@-]*$#', $redirect)
                || str_starts_with($redirect, '/admin')
                || str_starts_with($redirect, '/login')
                || str_starts_with($redirect, '/mfa')) {
                $redirect = '/';
            }
            header('Location: ' . $redirect); exit;
        }

        $_SESSION['login_error'] = 'Invalid email or password, or your account is locked.';
        header('Location: /login'); exit;
    }

    public function logout(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        Auth::log('logout', null, null);
        Auth::logout();
        header('Location: /login'); exit;
    }

    public function mfaVerifyForm(): void {
        if (Auth::check()) { header('Location: /'); exit; }
        if (empty($_SESSION['mfa_pending'])) { header('Location: /login'); exit; }
        $error = $_SESSION['mfa_error'] ?? null;
        unset($_SESSION['mfa_error']);
        require AEGIS_ROOT . '/views/auth/mfa_verify.php';
    }

    public function mfaVerify(): void {
        if (Auth::check()) { header('Location: /'); exit; }
        if (empty($_SESSION['mfa_pending'])) { header('Location: /login'); exit; }

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            $_SESSION['mfa_error'] = 'Invalid request.';
            header('Location: /mfa/verify'); exit;
        }

        $code   = preg_replace('/\s/', '', $_POST['code'] ?? '');
        $userId = (int)($_SESSION['mfa_user_id'] ?? 0);

        $user = Database::fetchOne("SELECT * FROM users WHERE id = ? AND is_active = TRUE", [$userId]);
        if (!$user || empty($user['mfa_secret'])) {
            header('Location: /login'); exit;
        }

        require_once AEGIS_ROOT . '/src/TOTP.php';
        if (!TOTP::verify($user['mfa_secret'], $code)) {
            $_SESSION['mfa_error'] = 'Invalid code. Please try again.';
            header('Location: /mfa/verify'); exit;
        }

        // MFA passed — establish full session
        $mfaRedir = $_SESSION['mfa_redirect'] ?? '/';
        $redirect = (preg_match('#^/[a-zA-Z0-9/_?=&%.@-]*$#', $mfaRedir)
                     && !str_starts_with($mfaRedir, '/admin')
                     && !str_starts_with($mfaRedir, '/login')
                     && !str_starts_with($mfaRedir, '/mfa'))
                    ? $mfaRedir : '/';
        session_unset();
        session_destroy();
        session_start();

        $_SESSION['user'] = [
            'id'         => $user['id'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'department' => $user['department'] ?? '',
        ];
        $_SESSION['last_activity'] = time();

        Database::query("UPDATE users SET last_login = NOW() WHERE id = ?", [$userId]);
        Auth::log('mfa_login', 'users', $userId);

        if (!preg_match('#^/[a-zA-Z0-9/_?=&%.@-]*$#', $redirect)) $redirect = '/';
        header('Location: ' . $redirect); exit;
    }

    public function mfaSetupForm(): void {
        Auth::requireAuth();
        require_once AEGIS_ROOT . '/src/TOTP.php';
        $user        = Auth::user();
        $dbUser      = Database::fetchOne("SELECT mfa_enabled, mfa_secret FROM users WHERE id = ?", [Auth::id()]);
        $isMfaEnabled = !empty($dbUser['mfa_enabled']);

        if ($isMfaEnabled && !empty($dbUser['mfa_secret'])) {
            $secret = $dbUser['mfa_secret'];
        } else {
            $secret = TOTP::generateSecret();
            Database::query("UPDATE users SET mfa_secret = ? WHERE id = ?", [$secret, Auth::id()]);
        }

        $qrUri       = TOTP::getUri($secret, $user['email']);
        $pageTitle    = 'Two-Factor Authentication';
        $activeModule = 'profile';
        $breadcrumbs  = [['Two-Factor Authentication', null]];
        require AEGIS_ROOT . '/views/auth/mfa_setup.php';
    }

    public function mfaSetupVerify(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        require_once AEGIS_ROOT . '/src/TOTP.php';
        $dbUser = Database::fetchOne("SELECT mfa_secret FROM users WHERE id = ?", [Auth::id()]);
        $code   = preg_replace('/\s/', '', $_POST['code'] ?? '');

        if (!$dbUser['mfa_secret'] || !TOTP::verify($dbUser['mfa_secret'], $code)) {
            $_SESSION['flash_error'] = 'Invalid code. Please try again.';
            header('Location: /mfa/setup'); exit;
        }

        Database::query("UPDATE users SET mfa_enabled = TRUE WHERE id = ?", [Auth::id()]);
        Auth::log('enable_mfa', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Two-factor authentication enabled successfully.';
        header('Location: /mfa/setup'); exit;
    }

    public function mfaDisable(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        Database::query("UPDATE users SET mfa_enabled = FALSE, mfa_secret = NULL WHERE id = ?", [Auth::id()]);
        Auth::log('disable_mfa', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Two-factor authentication disabled.';
        header('Location: /mfa/setup'); exit;
    }

    public function forgotPasswordForm(): void {
        if (Auth::check()) { header('Location: /'); exit; }
        $error   = $_SESSION['flash_error']   ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
        require AEGIS_ROOT . '/views/auth/forgot_password.php';
    }

    public function forgotPassword(): void {
        if (Auth::check()) { header('Location: /'); exit; }

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid request. Please try again.';
            header('Location: /forgot-password'); exit;
        }

        require_once AEGIS_ROOT . '/src/Mailer.php';

        $email = strtolower(Security::sanitizeInput($_POST['email'] ?? ''));

        $user = Database::fetchOne(
            "SELECT id, name, email FROM users WHERE email = ? AND is_active = TRUE",
            [$email]
        );

        if ($user) {
            $token  = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600);

            Database::query(
                "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (?,?,?)
                 ON CONFLICT (user_id) DO UPDATE SET token_hash=?, expires_at=?, used=FALSE",
                [$user['id'], hash('sha256', $token), $expiry, hash('sha256', $token), $expiry]
            );

            $appUrl  = rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/');
            $url     = $appUrl . '/reset-password/' . $token;
            $htmlBody = '
                <div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto">
                  <h2 style="color:#1e293b">Password Reset Request</h2>
                  <p>Hello ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . ',</p>
                  <p>We received a request to reset your AEGIS GRC password. Click the button below to choose a new password:</p>
                  <p style="text-align:center;margin:32px 0">
                    <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"
                       style="display:inline-block;padding:12px 28px;background:#6366f1;color:#fff;text-decoration:none;border-radius:8px;font-weight:600">
                      Reset Password
                    </a>
                  </p>
                  <p>Or copy and paste this link into your browser:<br>
                     <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>
                  </p>
                  <p style="color:#6b7280;font-size:13px">This link expires in 1 hour. If you did not request a password reset, you can safely ignore this email.</p>
                  <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">
                  <p style="color:#9ca3af;font-size:12px">AEGIS GRC &mdash; Enterprise Governance &amp; Compliance Platform</p>
                </div>
            ';

            Mailer::sendFromSettings(
                $user['email'],
                $user['name'],
                'Password Reset Request',
                $htmlBody
            );

            Auth::log('password_reset_request', 'users', $user['id']);
        }

        // Always show the same message — do not leak whether the email exists
        $_SESSION['flash_success'] = 'If that email is registered, a reset link has been sent.';
        header('Location: /forgot-password'); exit;
    }

    public function resetPasswordForm(string $token): void {
        if (Auth::check()) { header('Location: /'); exit; }

        $row = Database::fetchOne(
            "SELECT * FROM password_reset_tokens WHERE token_hash = ? AND used = FALSE AND expires_at > NOW()",
            [hash('sha256', $token)]
        );

        if (!$row) {
            $_SESSION['flash_error'] = 'This password reset link is invalid or has expired. Please request a new one.';
            header('Location: /forgot-password'); exit;
        }

        $error   = $_SESSION['flash_error']   ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
        require AEGIS_ROOT . '/views/auth/reset_password.php';
    }

    public function resetPassword(string $token): void {
        if (Auth::check()) { header('Location: /'); exit; }

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid request. Please try again.';
            header('Location: /reset-password/' . rawurlencode($token)); exit;
        }

        $row = Database::fetchOne(
            "SELECT * FROM password_reset_tokens WHERE token_hash = ? AND used = FALSE AND expires_at > NOW()",
            [hash('sha256', $token)]
        );

        if (!$row) {
            $_SESSION['flash_error'] = 'This password reset link is invalid or has expired. Please request a new one.';
            header('Location: /forgot-password'); exit;
        }

        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($new !== $confirm) {
            $_SESSION['flash_error'] = 'Passwords do not match.';
            header('Location: /reset-password/' . rawurlencode($token)); exit;
        }

        $policyErrors = Security::validatePasswordPolicy($new);
        if ($policyErrors) {
            $_SESSION['flash_error'] = implode(' ', $policyErrors);
            header('Location: /reset-password/' . rawurlencode($token)); exit;
        }

        Database::query(
            "UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?",
            [Security::hashPassword($new), (int)$row['user_id']]
        );

        Database::query(
            "UPDATE password_reset_tokens SET used=TRUE WHERE token_hash=?",
            [hash('sha256', $token)]
        );

        // Log the reset (no active session — use logSystem)
        Auth::logSystem('password_reset', 'users', (int)$row['user_id']);

        $_SESSION['flash_success'] = 'Your password has been reset. You can now sign in with your new password.';
        header('Location: /login'); exit;
    }

    public function backupCodesForm(): void {
        Auth::requireAuth();
        $u = Auth::user();
        if (!($u['mfa_enabled'] ?? false)) {
            $_SESSION['flash_error'] = 'Enable MFA first before generating backup codes.';
            header('Location: /mfa/setup'); return;
        }
        $existingCount = Database::fetchOne(
            "SELECT COUNT(*) as c FROM mfa_backup_codes WHERE user_id=? AND used_at IS NULL", [Auth::id()]
        )['c'] ?? 0;
        $pageTitle    = 'MFA Backup Codes';
        $activeModule = 'profile';
        $breadcrumbs  = [['Two-Factor Auth', '/mfa/setup'], ['Backup Codes', null]];
        $codes        = [];
        ob_start();
        require AEGIS_ROOT . '/views/auth/backup_codes.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function generateBackupCodes(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $u = Auth::user();
        if (!($u['mfa_enabled'] ?? false)) {
            $_SESSION['flash_error'] = 'MFA must be enabled first.';
            header('Location: /mfa/setup'); return;
        }
        // Invalidate existing codes
        Database::query("DELETE FROM mfa_backup_codes WHERE user_id=?", [Auth::id()]);
        // Generate 8 codes: format XXXX-XXXX
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw       = strtoupper(bin2hex(random_bytes(4)));
            $formatted = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $codes[]   = $formatted;
            Database::insert('mfa_backup_codes', [
                'user_id'   => Auth::id(),
                'code_hash' => password_hash($formatted, PASSWORD_ARGON2ID),
            ]);
        }
        Auth::log('backup_codes_generated', 'mfa_backup_codes', Auth::id(), []);
        // Pass codes through session for one-time display
        $_SESSION['new_backup_codes'] = $codes;
        header('Location: /mfa/backup-codes');
    }

    public function mfaBackupVerify(): void {
        // Called from the MFA verify page when user uses a backup code
        if (empty($_SESSION['mfa_pending_user_id'])) {
            // Also support the key used in mfaVerify flow
            if (empty($_SESSION['mfa_pending']) || empty($_SESSION['mfa_user_id'])) {
                header('Location: /login'); return;
            }
            $_SESSION['mfa_pending_user_id'] = (int)$_SESSION['mfa_user_id'];
        }
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $code   = strtoupper(trim(Security::sanitizeInput($_POST['backup_code'] ?? '')));
        $userId = (int)$_SESSION['mfa_pending_user_id'];
        // Remove hyphens for flexible entry
        $codeNorm = str_replace('-', '', $code);
        $stored   = Database::fetchAll(
            "SELECT id, code_hash FROM mfa_backup_codes WHERE user_id=? AND used_at IS NULL", [$userId]
        );
        $matched = null;
        foreach ($stored as $row) {
            // Normalize stored: try matching with and without hyphens
            if (password_verify($code, $row['code_hash']) || password_verify($codeNorm, $row['code_hash'])) {
                $matched = $row['id'];
                break;
            }
            // Try formatted version XXXX-XXXX
            $formatted = substr($codeNorm, 0, 4) . '-' . substr($codeNorm, 4, 4);
            if (password_verify($formatted, $row['code_hash'])) {
                $matched = $row['id'];
                break;
            }
        }
        if (!$matched) {
            $_SESSION['mfa_error'] = 'Invalid or already used backup code. Please try again.';
            header('Location: /mfa/verify?mode=backup'); return;
        }
        // Mark code as used
        Database::query("UPDATE mfa_backup_codes SET used_at=NOW() WHERE id=?", [$matched]);
        // Complete login — regenerate session to prevent session fixation
        $user     = Database::fetchOne("SELECT * FROM users WHERE id=?", [$userId]);
        $redirect = $_SESSION['mfa_redirect'] ?? '/';
        // Validate redirect to same-origin path only
        if (!preg_match('#^/[a-zA-Z0-9/_?=&%.@-]*$#', $redirect)) {
            $redirect = '/';
        }
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'          => $user['id'],
            'name'        => $user['name'],
            'email'       => $user['email'],
            'role'        => $user['role'],
            'mfa_enabled' => (bool)$user['mfa_enabled'],
        ];
        $_SESSION['last_activity'] = time();
        Auth::log('mfa_backup_code_used', 'users', $user['id'], []);
        Database::query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        header('Location: ' . $redirect);
    }
}
