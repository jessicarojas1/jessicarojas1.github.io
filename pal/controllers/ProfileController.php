<?php
declare(strict_types=1);

class ProfileController {

    public function editForm(): void {
        Auth::requireAuth();
        $user = Database::fetchOne(
            "SELECT id, name, email, role, department, title, last_login, password_changed_at FROM users WHERE id = ?",
            [Auth::id()]
        );
        require PAL_ROOT . '/views/profile/edit.php';
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
        require PAL_ROOT . '/views/profile/notifications.php';
    }

    public function markAllRead(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("UPDATE alerts SET is_read = TRUE WHERE user_id = ?", [Auth::id()]);
        header('Location: /profile/notifications');
    }
}
