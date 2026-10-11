<?php

declare(strict_types=1);

/**
 * Pure unit tests — no database required. Exercises Security, Roles,
 * Authorize's role/grant/deny layering (with in-memory $user arrays, so no
 * DB-backed reporting-chain/ownership scoping here — see db_test.php), and
 * the connector capability-manifest honesty rule.
 */

use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Connectors;
use Verity\Support\Roles;
use Verity\Support\Security;
use Verity\Support\Totp;

T::group('Security::h — output escaping');
T::eq('&lt;script&gt;alert(1)&lt;/script&gt;', Security::h('<script>alert(1)</script>'), 'escapes HTML special characters');
T::eq('', Security::h(null), 'null input becomes empty string, not "null"');
T::eq('Tom &amp; Jerry', Security::h('Tom & Jerry'), 'escapes ampersand');

T::group('Security::jsonForScript — script-context JSON hardening');
$encoded = Security::jsonForScript(['x' => '</script><script>alert(1)</script>']);
T::ok(!str_contains($encoded, '</script>'), 'closing script tag is hex-escaped, not literal');
T::ok(!str_contains($encoded, '<script>'), 'opening script tag is hex-escaped, not literal');

T::group('Security — CSRF token round trip');
$_SESSION = $_SESSION ?? [];
$_SESSION['_csrf'] = null;
$token = Security::csrfToken();
T::ok(strlen($token) >= 32, 'a generated CSRF token is non-trivially long');
T::ok(Security::validateCsrf($token), 'the token just issued validates successfully');
T::ok(!Security::validateCsrf('not-the-right-token'), 'an unrelated token is rejected');
T::ok(!Security::validateCsrf(null), 'a missing token is rejected, not silently accepted');

T::group('Auth::passwordPolicyError — length-based policy (no DB, no network)');
// This group tests only the length rule, deterministically — disable the
// HIBP breach check (on by default) so these assertions never depend on
// network reachability or a third party's uptime. The breach check itself
// is tested separately below via the pure, synthetic-response parsing logic.
putenv('PASSWORD_BREACH_CHECK_ENABLED=false');
T::ok(Auth::passwordPolicyError('short') !== null, 'a password under the minimum length is rejected');
T::ok(Auth::passwordPolicyError(str_repeat('a', Auth::MIN_PASSWORD_LENGTH)) === null, 'exactly the minimum length is accepted');
T::ok(Auth::passwordPolicyError(str_repeat('a', Auth::MIN_PASSWORD_LENGTH - 1)) !== null, 'one character under the minimum is rejected');
T::ok(Auth::passwordPolicyError(str_repeat('a', Auth::MAX_PASSWORD_LENGTH)) === null, 'exactly the maximum length is accepted');
T::ok(Auth::passwordPolicyError(str_repeat('a', Auth::MAX_PASSWORD_LENGTH + 1)) !== null, 'one character over the maximum is rejected');
T::ok(Auth::passwordPolicyError('correct horse battery staple!') === null, 'a long passphrase with no symbol-complexity requirement is accepted');
putenv('PASSWORD_BREACH_CHECK_ENABLED'); // restore default (enabled) for any later test/process

T::group('Auth::rangeResponseContainsSuffix — HIBP range-response parsing (pure, no network)');
$sampleBody = "003D68EB55068C33ACE09247EE4C639306B:3\nAAC4F66AD4716BC7C1F5D27B2A1D43D2AED:11\n0034E6D8B8B7C0C1E8D1D3D2D2E2B9A9B9C:5";
T::ok(Auth::rangeResponseContainsSuffix($sampleBody, 'AAC4F66AD4716BC7C1F5D27B2A1D43D2AED'), 'a suffix present in the response is found');
T::ok(Auth::rangeResponseContainsSuffix($sampleBody, 'aac4f66ad4716bc7c1f5d27b2a1d43d2aed'), 'the match is case-insensitive');
T::ok(!Auth::rangeResponseContainsSuffix($sampleBody, 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF'), 'a suffix absent from the response is not found');
T::ok(!Auth::rangeResponseContainsSuffix('', 'AAC4F66AD4716BC7C1F5D27B2A1D43D2AED'), 'an empty response body matches nothing');
T::ok(Auth::rangeResponseContainsSuffix($sampleBody . "\n", 'AAC4F66AD4716BC7C1F5D27B2A1D43D2AED'), 'a trailing newline (as the real API sends) does not break matching');

T::group('Roles — role defaults and coarse-alias expansion');
T::ok(in_array('*', Roles::permissionsFor(['enterprise_admin']), true), 'enterprise_admin holds the wildcard permission');
T::ok(in_array('matrix.view.supervisor', Roles::permissionsFor(['supervisor']), true), 'supervisor role grants matrix.view.supervisor');
T::ok(!in_array('matrix.view.enterprise', Roles::permissionsFor(['supervisor']), true), 'supervisor role does NOT grant enterprise-wide matrix view');
T::eq(['identity.manage.correlate'], Roles::expand('identity.manage'), 'coarse alias expands to its granular permission set');
T::eq(['some.unlisted.key'], Roles::expand('some.unlisted.key'), 'an unknown permission expands to itself unchanged');

T::group('Authorize::can — role/grant/deny layering (no DB; pure $user arrays)');
$security = ['id' => 1, 'person_id' => null, 'roles' => ['security_admin'], 'grants' => ['grant' => [], 'deny' => []]];
T::ok(Authorize::can($security, 'identity.view'), 'security_admin has identity.view by role default');
T::ok(Authorize::can($security, 'settings.manage'), 'security_admin has settings.manage by role default');
$deniedExplicitly = ['id' => 2, 'person_id' => null, 'roles' => ['security_admin'], 'grants' => ['grant' => [], 'deny' => ['identity.view']]];
T::ok(!Authorize::can($deniedExplicitly, 'identity.view'), 'an explicit deny overrides the role default — denials always win');
$noRoleButGranted = ['id' => 3, 'person_id' => null, 'roles' => [], 'grants' => ['grant' => ['audit.view'], 'deny' => []]];
T::ok(Authorize::can($noRoleButGranted, 'audit.view'), 'a permission with no role default can still be reached via an explicit grant');
T::ok(!Authorize::can($noRoleButGranted, 'settings.manage'), 'a user with no matching role or grant is denied');

// Regression test for a real bug found and fixed during this build: the
// wildcard role ('*') must not bypass an explicit per-permission deny.
// effectivePermissions() for a wildcard role only ever contains the literal
// key '*' (never the expanded list of every permission), so unset()-ing a
// specific denied permission against that map was previously a silent
// no-op — the deny never took effect for enterprise_admin. can() now checks
// the raw deny list before consulting the wildcard at all.
$adminDeniedOnePermission = ['id' => 4, 'person_id' => null, 'roles' => ['enterprise_admin'], 'grants' => ['grant' => [], 'deny' => ['settings.manage']]];
T::ok(!Authorize::can($adminDeniedOnePermission, 'settings.manage'), 'an explicit deny overrides even the wildcard (*) role — denials always win, with no exceptions');
T::ok(Authorize::can($adminDeniedOnePermission, 'audit.view'), 'the wildcard role still grants every OTHER permission normally — only the denied one is blocked');

T::group('Totp::hotp — verified against RFC 4226 Appendix D\'s own published test vectors');
// Secret "12345678901234567890" (20 ASCII bytes) and counters 0-9 are the
// RFC's own worked example — hand-rolled crypto gets tested against the
// spec's answer key, not just trusted because the code reads plausibly.
$rfc4226Secret = '12345678901234567890';
$rfc4226Expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
foreach ($rfc4226Expected as $counter => $expectedCode) {
    T::eq($expectedCode, Totp::hotp($rfc4226Secret, $counter), "HOTP counter=$counter matches the RFC 4226 published test vector");
}

T::group('Totp::base32Encode/Decode — verified against RFC 4648\'s own published test vectors');
T::eq('MY', Totp::base32Encode('f'), 'base32("f") matches RFC 4648 §10 (unpadded)');
T::eq('MZXQ', Totp::base32Encode('fo'), 'base32("fo") matches RFC 4648 §10 (unpadded)');
T::eq('MZXW6', Totp::base32Encode('foo'), 'base32("foo") matches RFC 4648 §10 (unpadded)');
T::eq('MZXW6YTBOI', Totp::base32Encode('foobar'), 'base32("foobar") matches RFC 4648 §10 (unpadded)');
foreach (['', 'f', 'fo', 'foo', 'foob', 'fooba', 'foobar', random_bytes(20)] as $original) {
    T::eq($original, Totp::base32Decode(Totp::base32Encode($original)), 'base32 encode/decode round-trips for a ' . strlen($original) . '-byte input');
}

T::group('Totp::verify — time-step drift window, boundary-tested against real HOTP counters');
$secret = Totp::generateSecret();
T::eq(32, strlen($secret), 'a generated secret is 32 base32 characters (160 bits, RFC 4226\'s own recommended HMAC-SHA1 key size)');
$code = Totp::currentCode($secret);
T::ok(Totp::verify($secret, $code), 'the current code verifies successfully');
T::ok(!Totp::verify($secret, '000000'), 'an arbitrary wrong code is rejected');
T::ok(!Totp::verify($secret, '12345'), 'a 5-digit (malformed) code is rejected before any HMAC computation runs');
T::ok(!Totp::verify($secret, 'abcdef'), 'a non-numeric code is rejected before any HMAC computation runs');
$step = intdiv(time(), Totp::PERIOD_SECONDS);
$rawKey = Totp::base32Decode($secret);
T::ok(Totp::verify($secret, Totp::hotp($rawKey, $step + 1)), 'a code from one step in the future verifies (clock-drift tolerance)');
T::ok(Totp::verify($secret, Totp::hotp($rawKey, $step - 1)), 'a code from one step in the past verifies (clock-drift tolerance)');
T::ok(!Totp::verify($secret, Totp::hotp($rawKey, $step + 2)), 'a code two steps in the future is rejected — the drift window has a real boundary, not an unbounded one');
T::ok(!Totp::verify($secret, Totp::hotp($rawKey, $step - 2)), 'a code two steps in the past is rejected — same boundary enforced on both sides');

T::group('Connectors::defaultManifest — never claims an unconfirmed capability');
$manual = Connectors::defaultManifest('manual');
T::ok(array_sum($manual) === 0, 'a manual connector claims ZERO automated capabilities by default');
$mock = Connectors::defaultManifest('entra_gcc_high_mock');
T::ok($mock['discover_accounts'] === true, 'the GCC High mock connector claims discover_accounts (it is a mock, documented as such)');
T::ok($mock['provision_access'] === false, 'the GCC High mock connector does NOT claim provisioning — that is unimplemented (Phase 4)');
foreach (Connectors::CAPABILITY_KEYS as $key) {
    T::ok(array_key_exists($key, $manual), "manual manifest defines every capability key ($key), even when false");
}
