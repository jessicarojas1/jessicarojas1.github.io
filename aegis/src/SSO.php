<?php
/**
 * OIDC / OAuth 2.0 SSO client.
 * Supports any standards-compliant IdP: Azure AD, Okta, Google Workspace,
 * Keycloak, Ping Identity, Auth0, etc.
 *
 * Configuration is stored in the `settings` table under keys prefixed `sso_`.
 * Requires the Phase 1 migration (001_enterprise_phase1.sql) to have run.
 */
class SSO {

    // ── Configuration ────────────────────────────────────────────────────────

    public static function config(): array {
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        $keys = [
            'sso_enabled', 'sso_provider_name', 'sso_client_id', 'sso_client_secret',
            'sso_discovery_url', 'sso_default_role', 'sso_auto_provision',
            'sso_role_claim', 'sso_role_mapping',
        ];
        $cfg = [];
        foreach ($keys as $k) {
            $row = Database::fetchOne("SELECT value FROM settings WHERE key = ?", [$k]);
            $cfg[$k] = $row['value'] ?? '';
        }
        return $cfg;
    }

    public static function isEnabled(): bool {
        $c = self::config();
        return $c['sso_enabled'] === '1'
            && !empty($c['sso_client_id'])
            && !empty($c['sso_discovery_url']);
    }

    // ── Discovery document (cached per request) ───────────────────────────────

    private static ?array $discovery = null;

    public static function discovery(): ?array {
        if (self::$discovery !== null) return self::$discovery;
        $url = self::config()['sso_discovery_url'];
        if (!$url) return null;
        // SSRF prevention: only allow HTTPS to public hosts (not private/loopback IPs)
        if (!preg_match('#^https://#i', $url)) return null;
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        $resolved = gethostbyname($host);
        if (filter_var($resolved, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return null;
        $ctx  = stream_context_create(['http' => ['timeout' => 8, 'method' => 'GET']]);
        $body = @file_get_contents($url, false, $ctx);
        if (!$body) return null;
        self::$discovery = json_decode($body, true) ?: null;
        return self::$discovery;
    }

    // ── Step 1: Build authorization redirect URL ──────────────────────────────

    public static function authorizationUrl(string $redirectUri): ?string {
        $disc = self::discovery();
        if (!$disc || empty($disc['authorization_endpoint'])) return null;
        $c = self::config();

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['sso_state']        = $state;
        $_SESSION['sso_nonce']        = $nonce;
        $_SESSION['sso_redirect_uri'] = $redirectUri;

        return $disc['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $c['sso_client_id'],
            'redirect_uri'  => $redirectUri,
            'scope'         => 'openid email profile',
            'state'         => $state,
            'nonce'         => $nonce,
        ]);
    }

    // ── Step 2: Handle callback, exchange code for tokens ────────────────────

    public static function handleCallback(string $code, string $state): ?array {
        if (empty($_SESSION['sso_state']) || !hash_equals($_SESSION['sso_state'], $state)) {
            error_log('[SSO] State mismatch — possible CSRF');
            return null;
        }
        $nonce       = $_SESSION['sso_nonce'] ?? '';
        $redirectUri = $_SESSION['sso_redirect_uri'] ?? '';
        unset($_SESSION['sso_state'], $_SESSION['sso_nonce'], $_SESSION['sso_redirect_uri']);

        $disc = self::discovery();
        if (!$disc || empty($disc['token_endpoint'])) return null;
        $c = self::config();

        // Exchange code for tokens
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'timeout' => 10,
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'client_id'     => $c['sso_client_id'],
                'client_secret' => $c['sso_client_secret'],
            ]),
        ]]);
        $body = @file_get_contents($disc['token_endpoint'], false, $ctx);
        if (!$body) { error_log('[SSO] Token endpoint request failed'); return null; }

        $tokens = json_decode($body, true);
        if (empty($tokens['id_token'])) { error_log('[SSO] No id_token in response'); return null; }

        // Verify ID token
        $claims = JWT::verifyRS256(
            $tokens['id_token'],
            $disc['jwks_uri'],
            $c['sso_client_id'],
            $disc['issuer'],
            $nonce
        );
        if (!$claims) { error_log('[SSO] ID token verification failed'); return null; }

        return $claims;
    }

    // ── Step 3: Provision or load local user from OIDC claims ────────────────

    public static function provisionUser(array $claims): ?array {
        $email = strtolower(trim($claims['email'] ?? ''));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log('[SSO] No valid email in ID token claims');
            return null;
        }

        $c       = self::config();
        $subject = $claims['sub'] ?? '';
        $name    = trim(($claims['name'] ?? '') ?: ($claims['given_name'] ?? '') . ' ' . ($claims['family_name'] ?? ''));
        if (!$name) $name = explode('@', $email)[0];

        // Map IdP role claim to local AEGIS role
        $role = self::mapRole($claims, $c);

        // Try to find existing user by SSO subject first, then fall back to email
        $user = Database::fetchOne(
            "SELECT * FROM users WHERE sso_provider = ? AND sso_subject = ? AND is_active = TRUE",
            ['oidc', $subject]
        );
        if (!$user) {
            $user = Database::fetchOne("SELECT * FROM users WHERE email = ? AND is_active = TRUE", [$email]);
        }

        if ($user) {
            // Update SSO linking fields and name if changed
            Database::query(
                "UPDATE users SET sso_provider = 'oidc', sso_subject = ?, name = ?, updated_at = NOW() WHERE id = ?",
                [$subject, $name ?: $user['name'], $user['id']]
            );
            // If role mapping is configured and differs, update role
            if ($role && $role !== $user['role']) {
                Database::query("UPDATE users SET role = ? WHERE id = ?", [$role, $user['id']]);
                $user['role'] = $role;
            }
            return array_merge($user, ['name' => $name ?: $user['name']]);
        }

        // Auto-provision
        if ($c['sso_auto_provision'] !== '1') {
            error_log("[SSO] Auto-provision disabled; user {$email} not found");
            return null;
        }

        $newRole = $role ?: ($c['sso_default_role'] ?: 'viewer');
        Database::query(
            "INSERT INTO users (name, email, password_hash, role, sso_provider, sso_subject, sso_only, is_active)
             VALUES (?, ?, ?, ?, 'oidc', ?, TRUE, TRUE)",
            [$name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID), $newRole, $subject]
        );
        return Database::fetchOne("SELECT * FROM users WHERE email = ?", [$email]);
    }

    private static function mapRole(array $claims, array $c): string {
        if (empty($c['sso_role_claim']) || empty($c['sso_role_mapping'])) return '';
        $mapping = json_decode($c['sso_role_mapping'], true);
        if (!$mapping) return '';
        $claimValue = $claims[$c['sso_role_claim']] ?? [];
        if (is_string($claimValue)) $claimValue = [$claimValue];
        foreach ((array)$claimValue as $idpRole) {
            if (isset($mapping[$idpRole])) return $mapping[$idpRole];
        }
        return '';
    }
}
