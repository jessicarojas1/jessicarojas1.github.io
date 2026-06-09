<?php
declare(strict_types=1);

class AuthController {

    public function loginForm(): void {
        if (Auth::check()) { header('Location: /'); return; }
        $error = null;
        $notice = match ($_GET['reason'] ?? '') {
            'timeout'          => 'Your session timed out. Please sign in again.',
            'revoked'          => 'Your session was ended by an administrator.',
            'account_disabled' => 'This account is no longer active.',
            default            => null,
        };
        require PALADIN_ROOT . '/views/auth/login.php';
    }

    public function login(): void {
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            $error = 'Your session expired. Please try again.';
            $notice = null;
            require PALADIN_ROOT . '/views/auth/login.php';
            return;
        }

        $email    = Security::sanitizeInput($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $result = Auth::login($email, $password);
        if ($result === 'mfa') { header('Location: /mfa/verify'); return; }
        if ($result === 'ok') {
            $redirect = $_SESSION['redirect_after_login'] ?? '/';
            unset($_SESSION['redirect_after_login']);
            // Open-redirect guard — only allow local paths
            if (!preg_match('#^/[A-Za-z0-9_\-/?=&.]*$#', $redirect)) {
                $redirect = '/';
            }
            header('Location: ' . $redirect);
            return;
        }

        $error  = 'Invalid email or password, or too many attempts. Please try again.';
        $notice = null;
        require PALADIN_ROOT . '/views/auth/login.php';
    }

    public function mfaVerifyForm(): void {
        if (Auth::check()) { header('Location: /'); return; }
        if (!Auth::mfaPending()) { header('Location: /login'); return; }
        $error = null;
        require PALADIN_ROOT . '/views/auth/mfa_verify.php';
    }

    public function mfaVerify(): void {
        if (!Auth::mfaPending()) { header('Location: /login'); return; }
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            $error = 'Your session expired. Please sign in again.';
            require PALADIN_ROOT . '/views/auth/mfa_verify.php';
            return;
        }
        if (Auth::completeMfa(Security::sanitizeInput($_POST['code'] ?? ''))) {
            $redirect = $_SESSION['redirect_after_login'] ?? '/';
            unset($_SESSION['redirect_after_login']);
            if (!preg_match('#^/[A-Za-z0-9_\-/?=&.]*$#', $redirect)) $redirect = '/';
            header('Location: ' . $redirect);
            return;
        }
        $error = 'Invalid or expired code. Please try again.';
        require PALADIN_ROOT . '/views/auth/mfa_verify.php';
    }

    public function logout(): void {
        if (Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            Auth::log('logout', 'users', Auth::id());
            try { Database::query("DELETE FROM active_sessions WHERE id = ?", [session_id()]); } catch (Throwable) {}
            Auth::logout();
        }
        header('Location: /login');
    }
}
