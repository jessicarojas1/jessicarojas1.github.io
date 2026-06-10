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
        // Remember SAML session details for Single Logout.
        $_SESSION['saml_name_id'] = $identity['nameid'];
        $_SESSION['saml_session_index'] = $identity['session_index'] ?? '';
        $relay = (string)($_POST['RelayState'] ?? '/');
        if (!preg_match('#^/[A-Za-z0-9/_-]*$#', $relay)) { $relay = '/'; }
        header('Location: ' . $relay);
    }

    /** SP-initiated Single Logout: clear the local session, then redirect to the IdP SLO. */
    public function logout(): void {
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $nameId = (string)($_SESSION['saml_name_id'] ?? '');
        $sessionIndex = (string)($_SESSION['saml_session_index'] ?? '');
        Auth::logout();
        if (Saml::sloEnabled() && $nameId !== '') {
            header('Location: ' . Saml::logoutRequestUrl($nameId, $sessionIndex ?: null));
            return;
        }
        header('Location: /login');
    }

    /**
     * SLO endpoint. Handles either the IdP's LogoutResponse (to our SP-initiated
     * logout) or an IdP-initiated LogoutRequest (we end the session and reply).
     */
    public function slo(): void {
        $rawQuery = (string)($_SERVER['QUERY_STRING'] ?? '');

        // IdP-initiated LogoutRequest.
        if (isset($_GET['SAMLRequest'])) {
            if (!Saml::verifyRedirectSignature($rawQuery)) {
                error_log('[PALADIN SAML] SLO request signature invalid');
                http_response_code(400); echo 'Invalid logout signature'; return;
            }
            $inResponseTo = '';
            try {
                $xml = Saml::inflateMessage((string)$_GET['SAMLRequest']);
                if (preg_match('/\bID="([^"]+)"/', $xml, $m)) { $inResponseTo = $m[1]; }
            } catch (\Throwable $e) {
                http_response_code(400); echo 'Bad logout request'; return;
            }
            Auth::logout();
            if (Saml::sloEnabled()) {
                $relay = isset($_GET['RelayState']) ? (string)$_GET['RelayState'] : null;
                header('Location: ' . Saml::logoutResponseUrl($inResponseTo, $relay));
                return;
            }
            header('Location: /login'); return;
        }

        // LogoutResponse to our SP-initiated request → just finish.
        if (isset($_GET['SAMLResponse'])) {
            Saml::verifyRedirectSignature($rawQuery); // best-effort; session already ended
            $_SESSION['flash_success'] = 'You have been signed out.';
            header('Location: /login'); return;
        }

        header('Location: /login');
    }

    public function metadata(): void {
        header('Content-Type: application/xml');
        $cfg = Saml::config();
        $entity = htmlspecialchars(Saml::spEntityId(), ENT_QUOTES);
        $acs = htmlspecialchars(Saml::acsUrl(), ENT_QUOTES);
        $slo = htmlspecialchars(Saml::sloEndpoint(), ENT_QUOTES);
        // Advertise the SP certificate so IdPs can verify signed requests and
        // encrypt assertions to us. Same cert serves signing + encryption here.
        $certBody = preg_replace('/-----[^-]+-----|\s+/', '', (string)$cfg['sp_cert']);
        $keyDescriptors = '';
        if ($certBody !== '') {
            $ki = '<ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>'
                . htmlspecialchars($certBody, ENT_QUOTES) . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo>';
            $keyDescriptors = '<md:KeyDescriptor use="signing">' . $ki . '</md:KeyDescriptor>'
                . '<md:KeyDescriptor use="encryption">' . $ki . '</md:KeyDescriptor>';
        }
        $signed = $cfg['sign_requests'] ? 'true' : 'false';
        echo '<?xml version="1.0"?>' . "\n"
            . '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="' . $entity . '">'
            . '<md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol" AuthnRequestsSigned="' . $signed . '" WantAssertionsSigned="true">'
            . $keyDescriptors
            . '<md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="' . $slo . '"/>'
            . '<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>'
            . '<md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="' . $acs . '" index="0" isDefault="true"/>'
            . '</md:SPSSODescriptor></md:EntityDescriptor>';
    }
}
