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
            header('Location: ' . $redirect); exit;
        }

        $_SESSION['login_error'] = 'Invalid email or password, or your account is locked.';
        header('Location: /login'); exit;
    }

    public function logout(): void {
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
        $redirect = $_SESSION['mfa_redirect'] ?? '/';
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
}
