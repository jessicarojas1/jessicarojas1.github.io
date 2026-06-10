<?php
declare(strict_types=1);

/**
 * SamlController — SP endpoints for SAML 2.0 SSO.
 *   GET  /saml/login    → redirect to the IdP with an AuthnRequest
 *   POST /saml/acs      → consume the IdP's SAMLResponse, JIT-provision, sign in
 *   GET  /saml/metadata → SP metadata XML (for IdP configuration)
 */
class SamlController {

    public function login(): void {
        if (!Saml::isEnabled()) { $_SESSION['flash_error'] = 'SSO is not configured.'; header('Location: /login'); return; }
        if (Auth::check()) { header('Location: /'); return; }
        $relay = isset($_GET['return']) && preg_match('#^/[A-Za-z0-9/_-]*$#', (string)$_GET['return']) ? $_GET['return'] : '/';
        header('Location: ' . Saml::loginUrl($relay));
    }

    public function acs(): void {
        if (!Saml::isEnabled()) { http_response_code(400); echo 'SSO not configured'; return; }
        $resp = (string)($_POST['SAMLResponse'] ?? '');
        if ($resp === '') { http_response_code(400); echo 'Missing SAMLResponse'; return; }

        try {
            $identity = Saml::consume($resp);
        } catch (\Throwable $e) {
            error_log('[PALADIN SAML] ' . $e->getMessage());
            Auth::log('sso_failed', 'users', null, ['reason' => $e->getMessage()]);
            $_SESSION['flash_error'] = 'Single sign-on failed: ' . Security::h($e->getMessage());
            header('Location: /login'); return;
        }

        $cfg = Saml::config();
        $user = Database::fetchOne("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = TRUE", [$identity['email']]);

        if (!$user) {
            if (!$cfg['auto_provision']) {
                $_SESSION['flash_error'] = 'No active account exists for ' . Security::h($identity['email']) . '.';
                header('Location: /login'); return;
            }
            $role = in_array($cfg['default_role'], ['viewer','contributor','approver','admin'], true) ? $cfg['default_role'] : 'viewer';
            $uid = Database::insert('users', [
                'name'          => $identity['name'],
                'email'         => $identity['email'],
                'password_hash' => Security::hashPassword(bin2hex(random_bytes(24))), // unusable local password
                'role'          => $role,
                'is_active'     => true,
            ]);
            Auth::log('sso_provision', 'users', $uid, ['email' => $identity['email']]);
            $user = Database::fetchOne("SELECT * FROM users WHERE id = ?", [$uid]);
        }

        Auth::ssoLogin($user);
        $relay = (string)($_POST['RelayState'] ?? '/');
        if (!preg_match('#^/[A-Za-z0-9/_-]*$#', $relay)) { $relay = '/'; }
        header('Location: ' . $relay);
    }

    public function metadata(): void {
        header('Content-Type: application/xml');
        $entity = htmlspecialchars(Saml::spEntityId(), ENT_QUOTES);
        $acs = htmlspecialchars(Saml::acsUrl(), ENT_QUOTES);
        echo '<?xml version="1.0"?>' . "\n"
            . '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="' . $entity . '">'
            . '<md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol" AuthnRequestsSigned="false" WantAssertionsSigned="true">'
            . '<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>'
            . '<md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="' . $acs . '" index="0" isDefault="true"/>'
            . '</md:SPSSODescriptor></md:EntityDescriptor>';
    }
}
