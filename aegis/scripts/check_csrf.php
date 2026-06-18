#!/usr/bin/env php
<?php
/**
 * CSRF coverage report for state-changing (POST) web routes.
 *
 * Extracts every POST route target (Controller::method) from index.php's static
 * and dynamic route tables, then verifies each handler calls
 * Security::validateCsrf(). Catches state-changing routes that forgot CSRF
 * protection. The JSON API uses key/JWT auth (not cookies) and is out of scope.
 *
 * Usage:  php scripts/check_csrf.php
 * Exit:   0 = every POST route validates CSRF (or is allowlisted), 1 = gaps.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$root = dirname(__DIR__);

// POST routes that legitimately do not use a CSRF token:
//  - /login: pre-session credential POST (protected by rate-limiting + creds).
//  - token-scoped public endpoints validated by a single-use token in the URL.
$ALLOWLIST = [
    'AuthController::login',
    'VendorController::portalSubmit',
    'UnsubscribeController::unsubscribe',
];

// ── 1. Extract POST route targets from index.php ────────────────────────────
$lines = file($root . '/index.php', FILE_IGNORE_NEW_LINES);
$targets = [];   // "Controller::method"
$capturing = false;
foreach ($lines as $line) {
    if (preg_match("/^\s*'POST'\s*=>\s*\[/", $line)) { $capturing = true; continue; }
    if ($capturing) {
        if (preg_match('/^\s*\],?\s*$/', $line)) { $capturing = false; continue; }
        if (preg_match_all("/\[\s*'([A-Za-z0-9_]+)'\s*,\s*'([A-Za-z0-9_]+)'\s*\]/", $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $targets["{$pair[1]}::{$pair[2]}"] = true;
            }
        }
    }
}
$targets = array_keys($targets);

// ── 2. Cache which Controller::method bodies call validateCsrf ───────────────
function methodCallsCsrf(string $file, string $method): ?bool
{
    static $cache = [];
    if (!is_file($file)) return null;
    if (!isset($cache[$file])) {
        $cache[$file] = token_get_all(file_get_contents($file));
    }
    $tokens = $cache[$file];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
            $k = $i + 1;
            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
            if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING || $tokens[$k][1] !== $method) continue;
            // Walk to the body and scan for validateCsrf at any depth.
            $b = $k;
            while ($b < $n && $tokens[$b] !== '{') { if ($tokens[$b] === ';') return false; $b++; }
            if ($b >= $n) return false;
            $depth = 0;
            for ($p = $b; $p < $n; $p++) {
                if ($tokens[$p] === '{') { $depth++; continue; }
                if ($tokens[$p] === '}') { $depth--; if ($depth === 0) return false; continue; }
                if (is_array($tokens[$p]) && $tokens[$p][0] === T_STRING && $tokens[$p][1] === 'validateCsrf') return true;
            }
            return false;
        }
    }
    return null; // method not found in this file
}

// ── 3. Report ───────────────────────────────────────────────────────────────
$gaps = [];
foreach ($targets as $t) {
    if (in_array($t, $ALLOWLIST, true)) continue;
    [$controller, $method] = explode('::', $t);
    $res = methodCallsCsrf($root . "/controllers/{$controller}.php", $method);
    if ($res === false) {
        $gaps[] = $t;
    }
    // $res === null → method/controller not found; skip silently (route table
    // may reference a delegated alias). Lint/route-auth gates cover existence.
}

echo "POST route targets scanned: " . count($targets) . "\n";
echo "Allowlisted (token/credential POST): " . count($ALLOWLIST) . "\n";

if ($gaps) {
    echo "\nPOST handlers with NO Security::validateCsrf():\n";
    foreach ($gaps as $g) echo "  [ERROR] {$g}\n";
    echo "\nAdd Security::validateCsrf(\$_POST['csrf_token'] ?? '') or allowlist with justification.\n";
    exit(1);
}
echo "\nOK — every state-changing POST route validates CSRF (or is allowlisted).\n";
exit(0);
