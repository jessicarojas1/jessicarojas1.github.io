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

    /** JSON user suggestions for @mention autocomplete (?q=). */
    public function suggestUsers(): void {
        Auth::requireAuth();
        header('Content-Type: application/json');
        $q = trim(Security::sanitizeInput($_GET['q'] ?? ''));
        if ($q === '') { echo json_encode([]); return; }
        $rows = Database::fetchAll(
            "SELECT id, name FROM users WHERE is_active = TRUE AND name ILIKE ? ORDER BY name LIMIT 8",
            ['%' . $q . '%']
        );
        $out = array_map(static fn($u) => [
            'id'     => (int)$u['id'],
            'name'   => $u['name'],
            // Handle that Mentions::process resolves unambiguously (name without spaces).
            'handle' => preg_replace('/[^A-Za-z0-9._-]/', '', (string)$u['name']),
        ], $rows);
        echo json_encode($out);
    }

    public function notifications(): void {
        Auth::requireAuth();
        // Save the digest preference on POST.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
            $freq = in_array($_POST['digest_frequency'] ?? 'off', ['off','daily','weekly'], true) ? $_POST['digest_frequency'] : 'off';
            Database::update('users', ['digest_frequency' => $freq], 'id = ?', [Auth::id()]);
            $_SESSION['flash_success'] = 'Digest preference saved.';
            header('Location: /profile/notifications'); return;
        }
        $alerts = Database::fetchAll(
            "SELECT * FROM alerts WHERE user_id = ? ORDER BY created_at DESC LIMIT 100",
            [Auth::id()]
        );
        $digestFrequency = (string)(Database::fetchOne("SELECT digest_frequency FROM users WHERE id = ?", [Auth::id()])['digest_frequency'] ?? 'off');
        require PALADIN_ROOT . '/views/profile/notifications.php';
    }

    public function markAllRead(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("UPDATE alerts SET is_read = TRUE WHERE user_id = ?", [Auth::id()]);
        header('Location: /profile/notifications');
    }

    /** The current user's active sessions across devices. */
    public function sessions(): void {
        Auth::requireAuth();
        $current = session_id();
        $sessions = Database::fetchAll(
            "SELECT id, ip_address, user_agent, last_seen_at FROM active_sessions
             WHERE user_id = ? ORDER BY last_seen_at DESC", [Auth::id()]
        );
        require PALADIN_ROOT . '/views/profile/sessions.php';
    }

    /** Sign out every other session, keeping the current one alive. */
    public function revokeOtherSessions(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $now = date('Y-m-d H:i:s');
        // Time-based revocation invalidates sessions whose login predates it…
        Database::update('users', ['sessions_revoked_at' => $now], 'id = ?', [Auth::id()]);
        // …so lift this session's login_time past the cutoff to keep it signed in.
        $_SESSION['user']['login_time'] = strtotime($now) + 5;
        // Tidy the tracking table (the timestamp above is the real enforcement).
        Database::query("DELETE FROM active_sessions WHERE user_id = ? AND id <> ?", [Auth::id(), session_id()]);
        Auth::log('revoke_other_sessions', 'users', Auth::id());
        $_SESSION['flash_success'] = 'Signed out of all other sessions.';
        header('Location: /profile/sessions');
    }

    /** My Favorites & Watches — the user's starred spaces/pages and watched items. */
    public function favorites(): void {
        Auth::requireAuth();
        $uid = Auth::id();
        $favSpaces = Database::fetchAll(
            "SELECT s.id, s.name, s.space_key, f.created_at FROM favorites f
             JOIN spaces s ON s.id = f.entity_id
             WHERE f.user_id = ? AND f.entity_type = 'space' ORDER BY f.created_at DESC",
            [$uid]
        );
        $favPages = Database::fetchAll(
            "SELECT p.id, p.title, s.name AS space_name, f.created_at FROM favorites f
             JOIN pages p ON p.id = f.entity_id LEFT JOIN spaces s ON s.id = p.space_id
             WHERE f.user_id = ? AND f.entity_type = 'page' ORDER BY f.created_at DESC",
            [$uid]
        );
        $watchedPages = Database::fetchAll(
            "SELECT p.id, p.title, s.name AS space_name, p.updated_at FROM watches w
             JOIN pages p ON p.id = w.entity_id LEFT JOIN spaces s ON s.id = p.space_id
             WHERE w.user_id = ? AND w.entity_type = 'page' ORDER BY p.updated_at DESC",
            [$uid]
        );
        $watchedSpaces = Database::fetchAll(
            "SELECT s.id, s.name, s.space_key FROM watches w
             JOIN spaces s ON s.id = w.entity_id
             WHERE w.user_id = ? AND w.entity_type = 'space' ORDER BY s.name",
            [$uid]
        );
        require PALADIN_ROOT . '/views/profile/favorites.php';
    }

    // ── Personal Access Tokens (per-user API credentials) ───────────────────
    public function tokens(): void {
        Auth::requireAuth();
        $tokens = Database::fetchAll(
            "SELECT id, name, token_prefix, scopes, last_used, expires_at, is_active, created_at
             FROM personal_access_tokens WHERE user_id = ? ORDER BY created_at DESC",
            [Auth::id()]
        );
        require PALADIN_ROOT . '/views/profile/tokens.php';
    }

    public function createToken(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $name = Security::sanitizeInput($_POST['name'] ?? '');
        if ($name === '') {
            $_SESSION['flash_error'] = 'Give your token a name.';
            header('Location: /profile/tokens'); return;
        }
        $scope   = ($_POST['scopes'] ?? 'read') === 'write' ? 'read,write' : 'read';
        $expires = Security::sanitizeInput($_POST['expires_at'] ?? '');
        $t = Security::generatePersonalToken();
        Database::insert('personal_access_tokens', [
            'user_id'      => Auth::id(),
            'name'         => $name,
            'token_prefix' => $t['prefix'],
            'token_hash'   => $t['hash'],
            'scopes'       => $scope,
            'is_active'    => 't',
            'expires_at'   => $expires !== '' ? $expires : null,
        ]);
        Auth::log('create_token', 'personal_access_tokens', Auth::id());
        $_SESSION['flash_success'] = 'Token created — copy it now, it will not be shown again: ' . $t['key'];
        header('Location: /profile/tokens');
    }

    public function revokeToken(int $id): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        // Scope the delete to the current user — never let one user revoke another's token.
        Database::query("DELETE FROM personal_access_tokens WHERE id = ? AND user_id = ?", [$id, Auth::id()]);
        Auth::log('revoke_token', 'personal_access_tokens', $id);
        $_SESSION['flash_success'] = 'Token revoked.';
        header('Location: /profile/tokens');
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
        // Recovery codes are shown once, immediately after generation.
        $recoveryCodes = $_SESSION['mfa_recovery_codes'] ?? null;
        unset($_SESSION['mfa_recovery_codes']);
        $recoveryRemaining = $enabled ? Auth::recoveryCodesRemaining((int)Auth::id()) : 0;
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
        // Issue one-time recovery codes and surface them once.
        $_SESSION['mfa_recovery_codes'] = Auth::generateRecoveryCodes((int)Auth::id());
        $_SESSION['flash_success'] = 'Two-factor authentication is now enabled. Save your recovery codes below.';
        header('Location: /mfa/setup');
    }

    /** Regenerate recovery codes (invalidates the previous set). */
    public function regenerateRecoveryCodes(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $user = Database::fetchOne("SELECT mfa_enabled FROM users WHERE id = ?", [Auth::id()]);
        $enabled = $user && ($user['mfa_enabled'] === true || $user['mfa_enabled'] === 't' || $user['mfa_enabled'] === '1');
        if (!$enabled) { $_SESSION['flash_error'] = 'Enable two-factor authentication first.'; header('Location: /mfa/setup'); return; }
        $_SESSION['mfa_recovery_codes'] = Auth::generateRecoveryCodes((int)Auth::id());
        Auth::log('mfa_recovery_regenerated', 'users', Auth::id());
        $_SESSION['flash_success'] = 'New recovery codes generated. Your previous codes no longer work.';
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
