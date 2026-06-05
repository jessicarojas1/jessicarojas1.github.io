<?php
class SSOController {

    public function login(): void {
        if (!Security::checkRateLimit('sso_login_' . Security::clientIp())) {
            http_response_code(429);
            $_SESSION['flash_error'] = 'Too many requests. Please try again later.';
            header('Location: /login'); exit;
        }
        if (!SSO::isEnabled()) {
            $_SESSION['sso_error'] = 'SSO is not configured on this instance. Contact your administrator.';
            header('Location: /login'); exit;
        }
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $redirectUri = $appUrl . '/sso/callback';
        $url = SSO::authorizationUrl($redirectUri);
        if (!$url) {
            $error = 'SSO is misconfigured. Please contact your administrator.';
            require AEGIS_ROOT . '/views/auth/sso_error.php'; exit;
        }
        header('Location: ' . $url); exit;
    }

    public function callback(): void {
        if (!Security::checkRateLimit('sso_callback_' . Security::clientIp())) {
            http_response_code(429);
            $_SESSION['flash_error'] = 'Too many requests. Please try again later.';
            header('Location: /login'); exit;
        }
        if (!SSO::isEnabled()) {
            header('Location: /login'); exit;
        }

        $code  = $_GET['code']  ?? '';
        $state = $_GET['state'] ?? '';
        $err   = $_GET['error'] ?? '';

        if ($err) {
            $errDesc = Security::h($_GET['error_description'] ?? $err);
            $error   = "Identity provider returned an error: {$errDesc}";
            require AEGIS_ROOT . '/views/auth/sso_error.php'; exit;
        }

        if (!$code || !$state) {
            $error = 'Invalid callback — missing code or state.';
            require AEGIS_ROOT . '/views/auth/sso_error.php'; exit;
        }

        $claims = SSO::handleCallback($code, $state);
        if (!$claims) {
            $error = 'SSO authentication failed. The token could not be verified.';
            require AEGIS_ROOT . '/views/auth/sso_error.php'; exit;
        }

        $user = SSO::provisionUser($claims);
        if (!$user) {
            $error = 'Your account is not provisioned for AEGIS access. Contact your administrator.';
            require AEGIS_ROOT . '/views/auth/sso_error.php'; exit;
        }

        // If user has MFA enabled, route through the MFA step before establishing session
        $dbUser = Database::fetchOne("SELECT mfa_enabled, mfa_secret FROM users WHERE id = ?", [$user['id']]);
        if (!empty($dbUser['mfa_enabled']) && !empty($dbUser['mfa_secret'])) {
            session_regenerate_id(true);
            $_SESSION['mfa_pending']  = true;
            $_SESSION['mfa_user_id']  = $user['id'];
            $_SESSION['mfa_redirect'] = $_SESSION['redirect_after_login'] ?? '/';
            unset($_SESSION['redirect_after_login']);
            header('Location: /mfa/verify'); exit;
        }

        // Establish session (same structure as password login)
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'         => $user['id'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'role'       => $user['role'],
            'sso'        => true,
            'login_time' => time(),
        ];
        $_SESSION['last_activity'] = time();

        Database::query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        Auth::logSystem('sso_login', 'users', $user['id']);

        $redirect = $_SESSION['redirect_after_login'] ?? '/';
        unset($_SESSION['redirect_after_login']);
        // Validate redirect: same-origin paths only (prevent open redirect)
        if (!preg_match('#^/[a-zA-Z0-9/_?=&%.@-]*$#', $redirect)
            || str_starts_with($redirect, '/admin')
            || str_starts_with($redirect, '/login')
            || str_starts_with($redirect, '/mfa')) {
            $redirect = '/';
        }
        header('Location: ' . $redirect); exit;
    }

    public function settingsForm(): void {
        Auth::requireAdmin();
        $cfg = SSO::config();
        $pageTitle   = 'SSO Settings';
        $activeModule = 'admin_sso';
        $breadcrumbs = [['Administration', '/admin'], ['SSO / OIDC', null]];
        ob_start();
        require AEGIS_ROOT . '/views/admin/sso.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function saveSettings(): void {
        Auth::requireAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403); return;
        }

        $fields = [
            'sso_enabled'         => $_POST['sso_enabled'] ?? '0',
            'sso_provider_name'   => Security::sanitizeInput($_POST['sso_provider_name'] ?? ''),
            'sso_client_id'       => Security::sanitizeInput($_POST['sso_client_id'] ?? ''),
            'sso_client_secret'   => Security::encryptSetting(Security::sanitizeInput($_POST['sso_client_secret'] ?? '')),
            'sso_discovery_url'   => Security::sanitizeInput($_POST['sso_discovery_url'] ?? ''),
            'sso_default_role'    => Security::sanitizeInput($_POST['sso_default_role'] ?? 'viewer'),
            'sso_auto_provision'  => $_POST['sso_auto_provision'] ?? '0',
            'sso_role_claim'      => Security::sanitizeInput($_POST['sso_role_claim'] ?? ''),
            'sso_role_mapping'    => $_POST['sso_role_mapping'] ?? '{}',
        ];

        // Validate JSON role mapping
        if ($fields['sso_role_mapping'] && !json_decode($fields['sso_role_mapping'])) {
            $_SESSION['flash_error'] = 'Role mapping must be valid JSON.';
            header('Location: /admin/settings/sso'); exit;
        }

        // Validate discovery URL
        if ($fields['sso_discovery_url']) {
            $scheme = strtolower(parse_url($fields['sso_discovery_url'], PHP_URL_SCHEME) ?? '');
            if (!in_array($scheme, ['https'])) {
                $_SESSION['flash_error'] = 'Discovery URL must use HTTPS.';
                header('Location: /admin/settings/sso'); exit;
            }
        }

        foreach ($fields as $key => $value) {
            Database::query(
                "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, NOW())
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()",
                [$key, $value]
            );
        }

        Auth::log('update_sso_settings', 'settings', null);
        $_SESSION['flash_success'] = 'SSO settings saved.';
        header('Location: /admin/settings/sso'); exit;
    }
}
