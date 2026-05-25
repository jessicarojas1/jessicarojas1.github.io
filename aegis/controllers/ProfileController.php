<?php
declare(strict_types=1);

class ProfileController {

    public function notifications(): void {
        require AEGIS_ROOT . '/views/profile/notifications.php';
    }

    public function editForm(): void {
        Auth::requireAuth();
        $user         = Auth::user();
        $pageTitle    = 'My Profile';
        $activeModule = 'profile';
        $breadcrumbs  = [['My Profile', null]];

        ob_start();
        require AEGIS_ROOT . '/views/profile/edit.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function update(): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $name  = Security::sanitizeInput($_POST['name'] ?? '');
        $email = strtolower(Security::sanitizeInput($_POST['email'] ?? ''));

        $errors = [];
        if (strlen($name) < 2 || strlen($name) > 100) {
            $errors[] = 'Name must be between 2 and 100 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (empty($errors)) {
            // Check email uniqueness excluding self
            $existing = Database::fetchOne(
                "SELECT id FROM users WHERE email = ? AND id != ?",
                [$email, Auth::id()]
            );
            if ($existing) {
                $errors[] = 'That email address is already in use by another account.';
            }
        }

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            header('Location: /profile/edit'); return;
        }

        Database::query(
            "UPDATE users SET name=?, email=?, updated_at=NOW() WHERE id=?",
            [$name, $email, Auth::id()]
        );

        // Update session data
        $_SESSION['user']['name']  = $name;
        $_SESSION['user']['email'] = $email;

        Auth::log('update_profile', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Profile updated successfully.';
        header('Location: /profile/edit');
    }

    public function changePassword(): void {
        Auth::requireAuth();

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        // Load full user record from DB to verify current password
        $dbUser = Database::fetchOne("SELECT * FROM users WHERE id = ?", [Auth::id()]);

        if (!$dbUser || !Security::verifyPassword($current, $dbUser['password_hash'])) {
            $_SESSION['flash_error'] = 'Current password is incorrect.';
            header('Location: /profile/edit'); return;
        }

        if ($new !== $confirm) {
            $_SESSION['flash_error'] = 'New passwords do not match.';
            header('Location: /profile/edit'); return;
        }

        $policyErrors = Security::validatePasswordPolicy($new);
        if ($policyErrors) {
            $_SESSION['flash_error'] = implode(' ', $policyErrors);
            header('Location: /profile/edit'); return;
        }

        Database::query(
            "UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?",
            [Security::hashPassword($new), Auth::id()]
        );

        Auth::log('change_password', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Password changed successfully.';
        header('Location: /profile/edit');
    }
}
