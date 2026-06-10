<?php
declare(strict_types=1);

/**
 * Saml — a minimal, dependency-free SAML 2.0 Service Provider.
 *
 * Supports the SP-initiated Web Browser SSO profile:
 *   - HTTP-Redirect AuthnRequest to the IdP,
 *   - HTTP-POST SAMLResponse to the ACS, with full XML-Signature verification
 *     against the admin-configured IdP certificate (native DOM C14N + openssl).
 *
 * Security posture:
 *   - The trust anchor is the configured IdP certificate, NOT any cert embedded
 *     in the response's KeyInfo.
 *   - The element we read claims from must be exactly the element the signature
 *     references (defends against signature-wrapping).
 *   - DOCTYPE/DTD is rejected (XXE / entity-expansion defence); no network/
 *     external-entity resolution.
 *   - Conditions (NotBefore/NotOnOrAfter, AudienceRestriction) are enforced.
 */
final class Saml {

    private const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';
    private const NS_SAML  = 'urn:oasis:names:tc:SAML:2.0:assertion';
    private const NS_DS    = 'http://www.w3.org/2000/09/xmldsig#';
    private const SKEW     = 120; // seconds of allowed clock skew

    /** Read SAML configuration from the settings table. */
    public static function config(): array {
        $rows = [];
        try {
            foreach (Database::fetchAll("SELECT key, value FROM settings WHERE key LIKE 'saml_%'") as $r) {
                $rows[$r['key']] = $r['value'];
            }
        } catch (\Throwable) {}
        return [
            'enabled'        => ($rows['saml_enabled'] ?? '0') === '1',
            'idp_entity_id'  => $rows['saml_idp_entity_id'] ?? '',
            'idp_sso_url'    => $rows['saml_idp_sso_url'] ?? '',
            'idp_cert'       => $rows['saml_idp_cert'] ?? '',
            'sp_entity_id'   => $rows['saml_sp_entity_id'] ?? '',
            'attr_email'     => $rows['saml_attr_email'] ?? '',
            'attr_name'      => $rows['saml_attr_name'] ?? '',
            'auto_provision' => ($rows['saml_auto_provision'] ?? '0') === '1',
            'default_role'   => $rows['saml_default_role'] ?? 'viewer',
            'idp_slo_url'    => $rows['saml_idp_slo_url'] ?? '',
            'sp_cert'        => $rows['saml_sp_cert'] ?? '',
            'sp_key'         => $rows['saml_sp_key'] ?? '',
            'sign_requests'  => ($rows['saml_sign_requests'] ?? '0') === '1',
        ];
    }

    public static function isEnabled(): bool {
        $c = self::config();
        return $c['enabled'] && $c['idp_sso_url'] !== '' && $c['idp_cert'] !== '';
    }

    public static function acsUrl(): string {
        return self::baseUrl() . '/saml/acs';
    }

    private static function baseUrl(): string {
        $app = rtrim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
        if ($app !== '') return $app;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public static function spEntityId(): string {
        $c = self::config();
        return $c['sp_entity_id'] !== '' ? $c['sp_entity_id'] : self::baseUrl() . '/saml/metadata';
    }

    /** Build the redirect URL (HTTP-Redirect binding) carrying a deflated AuthnRequest. */
    public static function loginUrl(?string $relayState = null): string {
        $c = self::config();
        $id = '_' . bin2hex(random_bytes(16));
        $issue = gmdate('Y-m-d\TH:i:s\Z');
        $acs = htmlspecialchars(self::acsUrl(), ENT_QUOTES);
        $sp  = htmlspecialchars(self::spEntityId(), ENT_QUOTES);
        $idp = htmlspecialchars($c['idp_sso_url'], ENT_QUOTES);
        $req = '<samlp:AuthnRequest xmlns:samlp="' . self::NS_SAMLP . '" xmlns:saml="' . self::NS_SAML . '" '
            . 'ID="' . $id . '" Version="2.0" IssueInstant="' . $issue . '" '
            . 'Destination="' . $idp . '" ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" '
            . 'AssertionConsumerServiceURL="' . $acs . '">'
            . '<saml:Issuer>' . $sp . '</saml:Issuer>'
            . '<samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>'
            . '</samlp:AuthnRequest>';
        return self::redirectUrl($c['idp_sso_url'], 'SAMLRequest', $req, $relayState);
    }

    public static function sloEndpoint(): string { return self::baseUrl() . '/saml/slo'; }

    public static function sloEnabled(): bool {
        $c = self::config();
        return self::isEnabled() && $c['idp_slo_url'] !== '';
    }

    /** SP-initiated Single Logout: build a (optionally signed) LogoutRequest redirect. */
    public static function logoutRequestUrl(string $nameId, ?string $sessionIndex = null): string {
        $c = self::config();
        $id = '_' . bin2hex(random_bytes(16));
        $issue = gmdate('Y-m-d\TH:i:s\Z');
        $slo = htmlspecialchars($c['idp_slo_url'], ENT_QUOTES);
        $sp  = htmlspecialchars(self::spEntityId(), ENT_QUOTES);
        $nid = htmlspecialchars($nameId, ENT_QUOTES);
        $si = ($sessionIndex !== null && $sessionIndex !== '')
            ? '<samlp:SessionIndex>' . htmlspecialchars($sessionIndex, ENT_QUOTES) . '</samlp:SessionIndex>' : '';
        $req = '<samlp:LogoutRequest xmlns:samlp="' . self::NS_SAMLP . '" xmlns:saml="' . self::NS_SAML . '" '
            . 'ID="' . $id . '" Version="2.0" IssueInstant="' . $issue . '" Destination="' . $slo . '">'
            . '<saml:Issuer>' . $sp . '</saml:Issuer>'
            . '<saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">' . $nid . '</saml:NameID>'
            . $si . '</samlp:LogoutRequest>';
        return self::redirectUrl($c['idp_slo_url'], 'SAMLRequest', $req, null);
    }

    /** Reply to an IdP-initiated LogoutRequest with a LogoutResponse redirect. */
    public static function logoutResponseUrl(string $inResponseTo, ?string $relayState): string {
        $c = self::config();
        $id = '_' . bin2hex(random_bytes(16));
        $issue = gmdate('Y-m-d\TH:i:s\Z');
        $slo = htmlspecialchars($c['idp_slo_url'], ENT_QUOTES);
        $sp  = htmlspecialchars(self::spEntityId(), ENT_QUOTES);
        $irt = htmlspecialchars($inResponseTo, ENT_QUOTES);
        $resp = '<samlp:LogoutResponse xmlns:samlp="' . self::NS_SAMLP . '" xmlns:saml="' . self::NS_SAML . '" '
            . 'ID="' . $id . '" Version="2.0" IssueInstant="' . $issue . '" Destination="' . $slo . '" InResponseTo="' . $irt . '">'
            . '<saml:Issuer>' . $sp . '</saml:Issuer>'
            . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            . '</samlp:LogoutResponse>';
        return self::redirectUrl($c['idp_slo_url'], 'SAMLResponse', $resp, $relayState);
    }

    /**
     * HTTP-Redirect binding builder: raw-deflate + base64 + urlencode, with an
     * optional XML-DSig (SHA-256) over the canonical query string when SP signing
     * is enabled. $type is 'SAMLRequest' or 'SAMLResponse'.
     */
    private static function redirectUrl(string $endpoint, string $type, string $xml, ?string $relayState): string {
        $c = self::config();
        $encoded = base64_encode((string)gzdeflate($xml));
        $query = $type . '=' . rawurlencode($encoded);
        if ($relayState !== null && $relayState !== '') {
            $query .= '&RelayState=' . rawurlencode($relayState);
        }
        if ($c['sign_requests'] && $c['sp_key'] !== '') {
            $sigAlg = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
            $query .= '&SigAlg=' . rawurlencode($sigAlg);
            $key = openssl_pkey_get_private($c['sp_key']);
            if ($key !== false && openssl_sign($query, $signature, $key, OPENSSL_ALGO_SHA256)) {
                $query .= '&Signature=' . rawurlencode(base64_encode($signature));
            }
        }
        $sep = str_contains($endpoint, '?') ? '&' : '?';
        return $endpoint . $sep . $query;
    }

    /**
     * Verify the redirect-binding signature on an inbound message (IdP-initiated
     * SLO). $rawQuery is the verbatim request query string. Returns true when the
     * IdP signed it correctly, or when no signature was supplied (the caller
     * decides whether unsigned is acceptable).
     */
    public static function verifyRedirectSignature(string $rawQuery): bool {
        $parts = [];
        foreach (explode('&', $rawQuery) as $kv) {
            $eq = strpos($kv, '=');
            if ($eq === false) continue;
            $parts[substr($kv, 0, $eq)] = substr($kv, $eq + 1); // keep raw (encoded) values
        }
        if (!isset($parts['Signature'])) { return true; } // unsigned — caller's policy
        if (!isset($parts['SigAlg'])) { return false; }
        $type = isset($parts['SAMLRequest']) ? 'SAMLRequest' : (isset($parts['SAMLResponse']) ? 'SAMLResponse' : null);
        if ($type === null) { return false; }

        // Reconstruct the exact signed string: <type>=..&[RelayState=..&]SigAlg=..
        $signed = $type . '=' . $parts[$type];
        if (isset($parts['RelayState'])) { $signed .= '&RelayState=' . $parts['RelayState']; }
        $signed .= '&SigAlg=' . $parts['SigAlg'];

        $sig = base64_decode(rawurldecode($parts['Signature']), true);
        if ($sig === false) { return false; }
        $alg = rawurldecode($parts['SigAlg']);
        $opensslAlg = self::opensslAlgFor($alg) ?? OPENSSL_ALGO_SHA256;
        $pub = self::publicKeyFromCert(self::config()['idp_cert']);
        return openssl_verify($signed, $sig, $pub, $opensslAlg) === 1;
    }

    /** Inflate a redirect-binding message (SAMLRequest/SAMLResponse param value). */
    public static function inflateMessage(string $b64): string {
        $raw = base64_decode($b64, true);
        if ($raw === false) { throw new \RuntimeException('Bad SAML message encoding'); }
        $xml = @gzinflate($raw);
        if ($xml === false) { throw new \RuntimeException('Cannot inflate SAML message'); }
        if (preg_match('/<!DOCTYPE/i', $xml)) { throw new \RuntimeException('DOCTYPE not allowed'); }
        return $xml;
    }

    /**
     * Verify a base64 SAMLResponse and return the asserted identity.
     * @return array{nameid:string,email:string,name:string,attributes:array}
     * @throws RuntimeException on any validation failure.
     */
    public static function consume(string $samlResponseB64): array {
        $c = self::config();
        $xml = base64_decode(trim($samlResponseB64), true);
        if ($xml === false || $xml === '') { throw new \RuntimeException('Malformed SAMLResponse'); }
        if (preg_match('/<!DOCTYPE/i', $xml)) { throw new \RuntimeException('DOCTYPE not allowed'); }

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->resolveExternals = false;
        $doc->substituteEntities = false;
        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException('Invalid SAML XML');
        }

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('samlp', self::NS_SAMLP);
        $xp->registerNamespace('saml', self::NS_SAML);
        $xp->registerNamespace('ds', self::NS_DS);

        // Status must be Success.
        $status = $xp->evaluate('string(/samlp:Response/samlp:Status/samlp:StatusCode/@Value)');
        if ($status !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            throw new \RuntimeException('SAML status not Success: ' . $status);
        }

        // The assertion we will read claims from.
        $assertion = $xp->query('/samlp:Response/saml:Assertion')->item(0);
        if (!$assertion instanceof \DOMElement) { throw new \RuntimeException('No assertion'); }

        // A signature must cover either the whole Response or this Assertion.
        $pubKey = self::publicKeyFromCert($c['idp_cert']);
        $verified = false;
        // Prefer an assertion-level signature.
        $sig = self::firstChildSignature($assertion, $xp);
        if ($sig && self::verifySignature($sig, $assertion, $pubKey, $xp)) {
            $verified = true;
        } else {
            $root = $doc->documentElement;
            $rsig = self::firstChildSignature($root, $xp);
            if ($rsig && self::verifySignature($rsig, $root, $pubKey, $xp)
                && self::isDescendantOrSelf($assertion, $root)) {
                $verified = true;
            }
        }
        if (!$verified) { throw new \RuntimeException('SAML signature verification failed'); }

        // Issuer check (best effort — must match configured IdP entity id if set).
        if ($c['idp_entity_id'] !== '') {
            $issuer = trim($xp->evaluate('string(saml:Issuer)', $assertion));
            if ($issuer !== '' && $issuer !== $c['idp_entity_id']) {
                throw new \RuntimeException('Unexpected SAML issuer');
            }
        }

        // Conditions: time window + audience.
        self::validateConditions($assertion, $xp, $c['sp_entity_id'] !== '' ? $c['sp_entity_id'] : self::spEntityId());

        // Extract identity from the (verified) assertion.
        $nameId = trim($xp->evaluate('string(saml:Subject/saml:NameID)', $assertion));
        $attrs = [];
        foreach ($xp->query('saml:AttributeStatement/saml:Attribute', $assertion) as $attr) {
            /** @var \DOMElement $attr */
            $key = $attr->getAttribute('Name');
            $vals = [];
            foreach ($xp->query('saml:AttributeValue', $attr) as $v) { $vals[] = trim($v->textContent); }
            if ($key !== '') { $attrs[$key] = count($vals) === 1 ? $vals[0] : $vals; }
        }

        $emailKey = $c['attr_email'];
        $email = $emailKey !== '' && isset($attrs[$emailKey])
            ? (is_array($attrs[$emailKey]) ? ($attrs[$emailKey][0] ?? '') : $attrs[$emailKey])
            : '';
        if ($email === '' && filter_var($nameId, FILTER_VALIDATE_EMAIL)) { $email = $nameId; }
        if ($email === '') {
            foreach (['email', 'mail', 'urn:oid:0.9.2342.19200300.100.1.3'] as $k) {
                if (!empty($attrs[$k])) { $email = is_array($attrs[$k]) ? $attrs[$k][0] : $attrs[$k]; break; }
            }
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new \RuntimeException('No email in assertion'); }

        $nameKey = $c['attr_name'];
        $name = $nameKey !== '' && !empty($attrs[$nameKey])
            ? (is_array($attrs[$nameKey]) ? $attrs[$nameKey][0] : $attrs[$nameKey])
            : '';
        if ($name === '') {
            foreach (['displayName', 'name', 'cn', 'urn:oid:2.16.840.1.113730.3.1.241'] as $k) {
                if (!empty($attrs[$k])) { $name = is_array($attrs[$k]) ? $attrs[$k][0] : $attrs[$k]; break; }
            }
        }
        if ($name === '') { $name = explode('@', $email)[0]; }

        $sessionIndex = trim($xp->evaluate('string(saml:AuthnStatement/@SessionIndex)', $assertion));

        return ['nameid' => $nameId, 'email' => strtolower($email), 'name' => $name, 'session_index' => $sessionIndex, 'attributes' => $attrs];
    }

    private static function firstChildSignature(\DOMElement $el, \DOMXPath $xp): ?\DOMElement {
        foreach ($el->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->namespaceURI === self::NS_DS && $n->localName === 'Signature') {
                return $n;
            }
        }
        return null;
    }

    private static function isDescendantOrSelf(\DOMNode $node, \DOMNode $ancestor): bool {
        for ($n = $node; $n !== null; $n = $n->parentNode) {
            if ($n->isSameNode($ancestor)) return true;
        }
        return false;
    }

    /** Verify an enveloped XML signature over $signed using the trusted public key. */
    private static function verifySignature(\DOMElement $sig, \DOMElement $signed, $pubKey, \DOMXPath $xp): bool {
        $signedInfo = $xp->query('ds:SignedInfo', $sig)->item(0);
        $reference  = $xp->query('ds:SignedInfo/ds:Reference', $sig)->item(0);
        if (!$signedInfo instanceof \DOMElement || !$reference instanceof \DOMElement) return false;

        // The reference URI must point at exactly the element we are validating.
        $refUri = $reference->getAttribute('URI');
        $refId  = ltrim($refUri, '#');
        if ($refId === '') return false;
        $target = self::elementById($signed->ownerDocument, $refId);
        if (!$target instanceof \DOMElement || !$target->isSameNode($signed)) return false;

        $digestAlg = $xp->evaluate('string(ds:DigestMethod/@Algorithm)', $reference);
        $sigAlg    = $xp->evaluate('string(ds:SignatureMethod/@Algorithm)', $signedInfo);
        $hashAlg = self::hashForAlg($digestAlg);
        $opensslAlg = self::opensslAlgFor($sigAlg);
        if ($hashAlg === null || $opensslAlg === null) return false;

        // Enveloped-signature transform: detach the Signature *in place* (so the
        // signed element keeps its real document/namespace context, which is how
        // the IdP canonicalized it), exclusive-C14N, then restore the Signature.
        $sigParent = $sig->parentNode;
        $sigNext   = $sig->nextSibling;
        $sigParent->removeChild($sig);
        $canon = $signed->C14N(true, false); // exclusive c14n, no comments
        $sigParent->insertBefore($sig, $sigNext);

        $digest = base64_encode(hash($hashAlg, $canon, true));
        $expected = trim($xp->evaluate('string(ds:DigestValue)', $reference));
        if (!hash_equals($expected, $digest)) return false;

        // Verify the SignedInfo signature.
        $siCanon = $signedInfo->C14N(true, false);
        $sigValue = base64_decode(preg_replace('/\s+/', '', $xp->evaluate('string(ds:SignatureValue)', $sig)) ?? '', true);
        if ($sigValue === false) return false;
        return openssl_verify($siCanon, $sigValue, $pubKey, $opensslAlg) === 1;
    }

    private static function elementById(\DOMDocument $doc, string $id): ?\DOMElement {
        $xp = new \DOMXPath($doc);
        foreach (['ID', 'Id', 'id'] as $attr) {
            $node = $xp->query("//*[@{$attr}=" . self::xpathLiteral($id) . "]")->item(0);
            if ($node instanceof \DOMElement) return $node;
        }
        return null;
    }

    private static function xpathLiteral(string $s): string {
        if (!str_contains($s, "'")) return "'{$s}'";
        if (!str_contains($s, '"')) return "\"{$s}\"";
        return "concat('" . str_replace("'", "',\"'\",'", $s) . "')";
    }

    private static function validateConditions(\DOMElement $assertion, \DOMXPath $xp, string $audience): void {
        $cond = $xp->query('saml:Conditions', $assertion)->item(0);
        $now = time();
        if ($cond instanceof \DOMElement) {
            $nb = $cond->getAttribute('NotBefore');
            $na = $cond->getAttribute('NotOnOrAfter');
            if ($nb !== '' && $now + self::SKEW < strtotime($nb)) { throw new \RuntimeException('Assertion not yet valid'); }
            if ($na !== '' && $now - self::SKEW >= strtotime($na)) { throw new \RuntimeException('Assertion expired'); }

            $auds = $xp->query('saml:AudienceRestriction/saml:Audience', $cond);
            if ($auds->length > 0 && $audience !== '') {
                $ok = false;
                foreach ($auds as $a) { if (trim($a->textContent) === $audience) { $ok = true; break; } }
                if (!$ok) { throw new \RuntimeException('Audience mismatch'); }
            }
        }
    }

    private static function publicKeyFromCert(string $cert): \OpenSSLAsymmetricKey {
        $pem = trim($cert);
        if (!str_contains($pem, 'BEGIN CERTIFICATE')) {
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . "-----END CERTIFICATE-----\n";
        }
        $x509 = openssl_x509_read($pem);
        if ($x509 === false) { throw new \RuntimeException('Invalid IdP certificate'); }
        $key = openssl_pkey_get_public($x509);
        if ($key === false) { throw new \RuntimeException('Cannot read IdP public key'); }
        return $key;
    }

    private static function hashForAlg(string $alg): ?string {
        return match (true) {
            str_ends_with($alg, 'sha256') => 'sha256',
            str_ends_with($alg, 'sha384') => 'sha384',
            str_ends_with($alg, 'sha512') => 'sha512',
            str_ends_with($alg, 'sha1')   => 'sha1',
            default => null,
        };
    }

    private static function opensslAlgFor(string $alg): ?int {
        return match (true) {
            str_ends_with($alg, 'sha256') => OPENSSL_ALGO_SHA256,
            str_ends_with($alg, 'sha384') => OPENSSL_ALGO_SHA384,
            str_ends_with($alg, 'sha512') => OPENSSL_ALGO_SHA512,
            str_ends_with($alg, 'sha1')   => OPENSSL_ALGO_SHA1,
            default => null,
        };
    }
}
