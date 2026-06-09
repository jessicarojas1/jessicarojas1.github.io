<?php
declare(strict_types=1);

class ProfileController {

    public function editForm(): void {
        Auth::requireAuth();
        $user = Database::fetchOne(
            "SELECT id, name, email, role, department, title, last_login, password_changed_at FROM users WHERE id = ?",
            [Auth::id()]
        );
        require PALADIN_ROOT . '/views/profile/edit.php';
    }

    public function update(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }

        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['flash_error'] = 'Name is required.';
            header('Location: /profile/edit'); return;
        }

        Database::update('users', [
            'name'       => $name,
            'department' => Security::sanitizeInput($_POST['department'] ?? '') ?: null,
            'title'      => Security::sanitizeInput($_POST['title'] ?? '') ?: null,
        ], 'id = ?', [Auth::id()]);
        $_SESSION['user']['name'] = $name;
        Auth::log('update_profile', 'users', Auth::id());

        $newPassword = (string)($_POST['new_password'] ?? '');
        if ($newPassword !== '') {
            $current = (string)($_POST['current_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            $dbUser = Database::fetchOne("SELECT password_hash FROM users WHERE id = ?", [Auth::id()]);
            if (!$dbUser || !Security::verifyPassword($current, $dbUser['password_hash'])) {
                $_SESSION['flash_error'] = 'Your current password is incorrect.';
                header('Location: /profile/edit'); return;
            }
            if ($newPassword !== $confirm) {
                $_SESSION['flash_error'] = 'The new password and confirmation do not match.';
                header('Location: /profile/edit'); return;
            }
            $errors = Security::validatePasswordPolicy($newPassword);
            if ($errors) {
                $_SESSION['flash_error'] = $errors[0];
                header('Location: /profile/edit'); return;
            }

            Database::update('users', [
                'password_hash'         => Security::hashPassword($newPassword),
                'force_password_change' => 'f',
                'password_changed_at'   => date('Y-m-d H:i:s'),
            ], 'id = ?', [Auth::id()]);
            Auth::log('change_password', 'users', Auth::id());

            $_SESSION['flash_success'] = 'Profile and password updated.';
            header('Location: /profile/edit'); return;
        }

        $_SESSION['flash_success'] = 'Profile updated.';
        header('Location: /profile/edit');
    }

    public function notifications(): void {
        Auth::requireAuth();
        $alerts = Database::fetchAll(
            "SELECT * FROM alerts WHERE user_id = ? ORDER BY created_at DESC LIMIT 100",
            [Auth::id()]
        );
        require PALADIN_ROOT . '/views/profile/notifications.php';
    }

    public function markAllRead(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("UPDATE alerts SET is_read = TRUE WHERE user_id = ?", [Auth::id()]);
        header('Location: /profile/notifications');
    }

    // ── Two-factor authentication (TOTP) ─────────────────────────────────────
    public function mfaSetupForm(): void {
        Auth::requireAuth();
        $user = Database::fetchOne("SELECT id, email, mfa_enabled FROM users WHERE id = ?", [Auth::id()]);
        $enabled = !empty($user['mfa_enabled']);
        $secret = $otpauthUri = null;
        if (!$enabled) {
            // Keep a candidate secret in the session until the user verifies a code.
            if (empty($_SESSION['mfa_setup_secret'])) $_SESSION['mfa_setup_secret'] = TOTP::generateSecret();
            $secret = $_SESSION['mfa_setup_secret'];
            $otpauthUri = TOTP::getUri($secret, (string)$user['email'], Branding::name());
        }
        require PALADIN_ROOT . '/views/profile/mfa.php';
    }

    public function mfaEnable(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $secret = $_SESSION['mfa_setup_secret'] ?? '';
        $code = Security::sanitizeInput($_POST['code'] ?? '');
        if ($secret === '' || !TOTP::verify($secret, $code)) {
            $_SESSION['flash_error'] = 'That code didn’t match. Make sure your authenticator is set up, then try again.';
            header('Location: /mfa/setup'); return;
        }
        Database::query("UPDATE users SET mfa_secret = ?, mfa_enabled = TRUE WHERE id = ?", [$secret, Auth::id()]);
        unset($_SESSION['mfa_setup_secret']);
        Auth::log('mfa_enabled', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Two-factor authentication is now enabled.';
        header('Location: /mfa/setup');
    }

    public function mfaDisable(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        // Require the current password to turn off 2FA.
        $me = Database::fetchOne("SELECT password_hash FROM users WHERE id = ?", [Auth::id()]);
        if (!$me || !Security::verifyPassword((string)($_POST['password'] ?? ''), $me['password_hash'])) {
            $_SESSION['flash_error'] = 'Incorrect password — 2FA was not disabled.';
            header('Location: /mfa/setup'); return;
        }
        Database::query("UPDATE users SET mfa_enabled = FALSE, mfa_secret = NULL WHERE id = ?", [Auth::id()]);
        Auth::log('mfa_disabled', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Two-factor authentication disabled.';
        header('Location: /mfa/setup');
    }
}
