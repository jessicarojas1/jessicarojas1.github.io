<?php
declare(strict_types=1);

/**
 * OidcController — OpenID Connect Relying Party endpoints.
 *   GET /oidc/login    → redirect to the provider (Authorization Code + PKCE)
 *   GET /oidc/callback → exchange the code, verify the ID token, sign in
 */
class OidcController {

    public function login(): void {
        if (!Oidc::isEnabled()) { $_SESSION['flash_error'] = 'OIDC SSO is not configured.'; header('Location: /login'); return; }
        if (Auth::check()) { header('Location: /'); return; }

        $state    = bin2hex(random_bytes(16));
        $nonce    = bin2hex(random_bytes(16));
        $verifier = Oidc::b64urlEncode(random_bytes(48));
        $challenge = Oidc::b64urlEncode(hash('sha256', $verifier, true));
        $_SESSION['oidc_state'] = $state;
        $_SESSION['oidc_nonce'] = $nonce;
        $_SESSION['oidc_verifier'] = $verifier;
        $relay = isset($_GET['return']) && preg_match('#^/[A-Za-z0-9/_-]*$#', (string)$_GET['return']) ? $_GET['return'] : '/';
        $_SESSION['oidc_relay'] = $relay;

        try {
            header('Location: ' . Oidc::authorizeUrl($state, $nonce, $challenge));
        } catch (\Throwable $e) {
            error_log('[PALADIN OIDC] ' . $e->getMessage());
            $_SESSION['flash_error'] = 'OIDC start failed: ' . Security::h($e->getMessage());
            header('Location: /login');
        }
    }

    public function callback(): void {
        if (!Oidc::isEnabled()) { http_response_code(400); echo 'OIDC not configured'; return; }

        if (!empty($_GET['error'])) {
            $_SESSION['flash_error'] = 'Sign-in was cancelled or failed: ' . Security::h((string)$_GET['error']);
            header('Location: /login'); return;
        }
        $state = (string)($_GET['state'] ?? '');
        $code  = (string)($_GET['code'] ?? '');
        $expectedState = (string)($_SESSION['oidc_state'] ?? '');
        $nonce = (string)($_SESSION['oidc_nonce'] ?? '');
        $verifier = (string)($_SESSION['oidc_verifier'] ?? '');
        unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'], $_SESSION['oidc_verifier']);

        if ($code === '' || $state === '' || !hash_equals($expectedState, $state)) {
            $_SESSION['flash_error'] = 'OIDC state validation failed.';
            header('Location: /login'); return;
        }

        try {
            $tokens = Oidc::exchangeCode($code, $verifier);
            $idToken = (string)($tokens['id_token'] ?? '');
            if ($idToken === '') { throw new \RuntimeException('No id_token returned'); }
            $cfg = Oidc::config();
            $claims = Oidc::verifyIdToken($idToken, Oidc::jwks(), $cfg['issuer'], $cfg['client_id'], $nonce);
        } catch (\Throwable $e) {
            error_log('[PALADIN OIDC] ' . $e->getMessage());
            Auth::log('oidc_failed', 'users', null, ['reason' => $e->getMessage()]);
            $_SESSION['flash_error'] = 'OIDC sign-in failed: ' . Security::h($e->getMessage());
            header('Location: /login'); return;
        }

        $cfg = Oidc::config();
        $email = strtolower((string)($claims[$cfg['attr_email']] ?? $claims['email'] ?? ''));
        $name  = (string)($claims[$cfg['attr_name']] ?? $claims['name'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'OIDC token did not contain a valid email.';
            header('Location: /login'); return;
        }
        if ($name === '') { $name = explode('@', $email)[0]; }

        $user = Database::fetchOne("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = TRUE", [$email]);
        if (!$user) {
            if (!$cfg['auto_provision']) {
                $_SESSION['flash_error'] = 'No active account exists for ' . Security::h($email) . '.';
                header('Location: /login'); return;
            }
            // Auto-provisioned SSO users must never land as admin: 'admin' is
            // deliberately excluded from the allowable auto-assigned default roles.
            $role = in_array($cfg['default_role'], ['viewer','contributor','approver'], true) ? $cfg['default_role'] : 'viewer';
            $uid = Database::insert('users', [
                'name'          => $name,
                'email'         => $email,
                'password_hash' => Security::hashPassword(bin2hex(random_bytes(24))),
                'role'          => $role,
                'is_active'     => true,
            ]);
            Auth::log('oidc_provision', 'users', $uid, ['email' => $email]);
            $user = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$uid]);
        }

        Auth::ssoLogin($user);
        $relay = (string)($_SESSION['oidc_relay'] ?? '/');
        unset($_SESSION['oidc_relay']);
        if (!preg_match('#^/[A-Za-z0-9/_-]*$#', $relay)) { $relay = '/'; }
        header('Location: ' . $relay);
    }
}
