<?php
declare(strict_types=1);

class ProfileController {

    public function notifications(): void {
        Auth::requireAuth();
        $prefs = [];
        $rows  = Database::fetchAll(
            "SELECT notification_type, enabled, digest_mode, digest_time FROM user_notification_prefs WHERE user_id = ?",
            [Auth::id()]
        );
        foreach ($rows as $row) {
            $prefs[$row['notification_type']] = $row;
        }
        // Digest preference stored as special '__digest__' row
        $digestRow = $prefs['__digest__'] ?? null;
        $pageTitle    = 'Notification Preferences';
        $activeModule = 'profile';
        $breadcrumbs  = [['Notification Preferences', null]];
        require AEGIS_ROOT . '/views/profile/notifications.php';
    }

    public function saveNotifications(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $types = [
            'overdue_controls','policy_review_due','policy_expiring','pending_approval',
            'new_risk_assigned','open_incident_aging','risk_review_overdue',
            'treatment_due','risk_score_worsened','vendor_assessment_expiring',
            'document_expiring','assessment_pending_stale','evidence_expiring',
            'risk_acceptance_expiring','kri_breached','incident_sla_breach',
            'bcp_exercise_overdue','bcp_plan_review_due','poam_item_overdue',
            'awareness_training_overdue',
        ];
        foreach ($types as $type) {
            $enabled = isset($_POST['types'][$type]);
            Database::query(
                "INSERT INTO user_notification_prefs (user_id, notification_type, enabled)
                 VALUES (?, ?, ?)
                 ON CONFLICT (user_id, notification_type) DO UPDATE SET enabled = EXCLUDED.enabled",
                [Auth::id(), $type, $enabled]
            );
        }
        Auth::log('update_notification_prefs', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Notification preferences saved.';
        header('Location: /profile/notifications');
    }

    public function saveNotificationDigest(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }
        $digestMode = in_array($_POST['digest_mode'] ?? '', ['immediate','daily','weekly'], true)
            ? $_POST['digest_mode'] : 'immediate';
        $digestTime = Security::sanitizeInput($_POST['digest_time'] ?? '08:00');

        Database::query(
            "INSERT INTO user_notification_prefs (user_id, notification_type, enabled, digest_mode, digest_time)
             VALUES (?, '__digest__', TRUE, ?, ?)
             ON CONFLICT (user_id, notification_type) DO UPDATE SET digest_mode = EXCLUDED.digest_mode, digest_time = EXCLUDED.digest_time",
            [Auth::id(), $digestMode, $digestTime]
        );
        Auth::log('update_notification_digest', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Delivery preferences saved.';
        header('Location: /profile/notifications');
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

        // Password history: reject reuse of last 10 passwords (ISO 27001 A.9.4.3)
        $history = Database::fetchAll(
            "SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 10",
            [Auth::id()]
        );
        foreach ($history as $h) {
            if (Security::verifyPassword($new, $h['password_hash'])) {
                $_SESSION['flash_error'] = 'You cannot reuse one of your last 10 passwords.';
                header('Location: /profile/edit'); return;
            }
        }

        $newHash = Security::hashPassword($new);
        Database::query(
            "UPDATE users SET password_hash=?, force_password_change=FALSE, updated_at=NOW() WHERE id=?",
            [$newHash, Auth::id()]
        );
        // Record in history for reuse prevention
        Database::query(
            "INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)",
            [Auth::id(), $newHash]
        );
        // Keep only the last 15 entries
        Database::query(
            "DELETE FROM password_history WHERE user_id = ? AND id NOT IN (
               SELECT id FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 15
             )",
            [Auth::id(), Auth::id()]
        );

        Auth::log('change_password', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Password changed successfully.';
        header('Location: /profile/edit');
    }
}
