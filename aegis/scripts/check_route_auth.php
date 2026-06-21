#!/usr/bin/env php
<?php
/**
 * Route authorization coverage report.
 *
 * Statically scans every public controller method and flags those that never
 * call Auth::requireAuth / requirePermission / requireAdmin (and aren't on the
 * intentional-public allowlist). This catches the "protected route with no
 * server-side authorization" class of bug — UI hiding is never sufficient.
 *
 * Uses the PHP tokenizer (not regex) for accurate method/brace tracking.
 *
 * Usage:  php scripts/check_route_auth.php
 * Exit:   0 = every public action is authorized or allowlisted, 1 = gaps found.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$controllersDir = dirname(__DIR__) . '/controllers';

// Intentionally public (pre-auth) endpoints — Controller::method.
$ALLOWLIST = [
    // Auth / login / recovery flow — pre-auth by design.
    'AuthController::loginForm', 'AuthController::login', 'AuthController::logout',
    'AuthController::mfaVerifyForm', 'AuthController::mfaVerify', 'AuthController::mfaBackupVerify',
    'AuthController::forgotPasswordForm', 'AuthController::forgotPassword',
    'AuthController::resetPasswordForm', 'AuthController::resetPassword',
    // SSO — pre-auth by definition.
    'SSOController::login', 'SSOController::callback',
    // Health probes — must be unauthenticated.
    'HealthController::live', 'HealthController::ready',
    // Token-scoped public endpoints (no session; validated by a single-use token).
    'UnsubscribeController::unsubscribe', 'UnsubscribeController::verifyEmail',
    'VendorController::portalView', 'VendorController::portalSubmit',
    // Delegates to an already-authorized action (view() enforces risk.view).
    'RiskController::editForm',
];

$AUTH_MARKERS = ['requireAuth', 'requirePermission', 'requireAdmin', 'requirePlatformAdmin'];

$gaps = [];
$checked = 0;

foreach (glob($controllersDir . '/*.php') ?: [] as $file) {
    $controller = basename($file, '.php');
    $tokens = token_get_all(file_get_contents($file));

    $i = 0; $n = count($tokens);
    while ($i < $n) {
        // Find: T_PUBLIC ... T_FUNCTION T_STRING(name)
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_PUBLIC) {
            // Look ahead for T_FUNCTION before any ';' or '{'. Track 'static':
            // route actions are always instance methods (dispatcher does
            // `new $controller()->$action()`), so static methods are helpers, not
            // routes, and are skipped.
            $j = $i + 1; $isFunc = false; $isStatic = false;
            while ($j < $n) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_FUNCTION) { $isFunc = true; break; }
                if (is_array($t) && $t[0] === T_STATIC) { $isStatic = true; $j++; continue; }
                if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; continue; }
                break;
            }
            if (!$isFunc || $isStatic) { $i = $j + 1; continue; }
            // Method name
            $k = $j + 1;
            while ($k < $n && (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE)) $k++;
            if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) { $i = $j + 1; continue; }
            $method = $tokens[$k][1];

            // Skip constructors / magic methods.
            if (str_starts_with($method, '__')) { $i = $k + 1; continue; }

            // Walk to the opening brace, then capture the body by brace depth.
            $b = $k;
            while ($b < $n && $tokens[$b] !== '{') {
                if ($tokens[$b] === ';') break; // abstract/interface method, no body
                $b++;
            }
            if ($b >= $n || $tokens[$b] !== '{') { $i = $k + 1; continue; }

            $depth = 0; $hasAuth = false; $p = $b;
            for (; $p < $n; $p++) {
                $t = $tokens[$p];
                if ($t === '{') { $depth++; continue; }
                if ($t === '}') { $depth--; if ($depth === 0) break; continue; }
                if (is_array($t) && $t[0] === T_STRING && in_array($t[1], $AUTH_MARKERS, true)) {
                    $hasAuth = true;
                }
            }

            $checked++;
            $key = "{$controller}::{$method}";
            if (!$hasAuth && !in_array($key, $ALLOWLIST, true)) {
                $gaps[] = $key;
            }
            $i = $p + 1;
            continue;
        }
        $i++;
    }
}

echo "Public controller actions scanned: {$checked}\n";
echo "Allowlisted public endpoints: " . count($ALLOWLIST) . "\n";

if ($gaps) {
    echo "\nActions with NO Auth::require* call (and not allowlisted):\n";
    foreach ($gaps as $g) echo "  [REVIEW] {$g}\n";
    echo "\nEach must either enforce authorization or be added to the allowlist with justification.\n";
    exit(1);
}
echo "\nOK — every public controller action enforces authorization or is allowlisted.\n";
exit(0);
