#!/usr/bin/env php
<?php
/**
 * Write-permission coverage check.
 *
 * Flags the "write gated on a read permission" bug class: a state-changing
 * handler (one that calls Security::validateCsrf — every POST does, enforced by
 * check_csrf.php) whose ONLY Auth::requirePermission gate is a read-level
 * permission (*.view / *.read). Such a method lets a read-only user mutate data.
 *
 * This was found twice by hand (RACI::save / saveResponsibility on risk.view,
 * Privacy::createRequest on compliance.view); this guard prevents recurrence.
 *
 * Uses the PHP tokenizer (not regex) for accurate method/brace tracking.
 *
 * Usage:  php scripts/check_write_permissions.php
 * Exit:   0 = clean, 1 = a write method is gated only on a read permission.
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$controllersDir = dirname(__DIR__) . '/controllers';

// Permission suffixes that are READ-only (never sufficient to authorize a write).
$READ_SUFFIXES = ['view', 'read'];

// Methods that legitimately validate CSRF but are not privileged writes a write
// permission should gate (self-service actions scoped to the current user).
$ALLOWLIST = [
    // A user marks THEIR OWN assigned training complete — the UPDATE is scoped to
    // user_id = Auth::id(), so awareness.view (assignee can see it) is correct;
    // the privileged write (assign to others) is separately gated on awareness.manage.
    'AwarenessController::complete',
];

$gaps = [];
$checked = 0;

foreach (glob($controllersDir . '/*.php') ?: [] as $file) {
    $controller = basename($file, '.php');
    $tokens = token_get_all(file_get_contents($file));
    $i = 0; $n = count($tokens);

    while ($i < $n) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_PUBLIC) { $i++; continue; }

        // Resolve: public [static] function <name>
        $j = $i + 1; $isFunc = false; $isStatic = false;
        while ($j < $n) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_FUNCTION) { $isFunc = true; break; }
            if (is_array($t) && $t[0] === T_STATIC) { $isStatic = true; $j++; continue; }
            if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_ABSTRACT, T_FINAL, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; continue; }
            break;
        }
        if (!$isFunc || $isStatic) { $i = $j + 1; continue; }

        $k = $j + 1;
        while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
        if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) { $i = $j + 1; continue; }
        $method = $tokens[$k][1];
        if (str_starts_with($method, '__')) { $i = $k + 1; continue; }

        // Find the body opening brace.
        $b = $k;
        while ($b < $n && $tokens[$b] !== '{') { if ($tokens[$b] === ';') break; $b++; }
        if ($b >= $n || $tokens[$b] !== '{') { $i = $k + 1; continue; }

        // Walk the body: detect validateCsrf and collect requirePermission args.
        $depth = 0; $hasCsrf = false; $perms = []; $p = $b;
        for (; $p < $n; $p++) {
            $t = $tokens[$p];
            if ($t === '{') { $depth++; continue; }
            if ($t === '}') { $depth--; if ($depth === 0) break; continue; }
            if (!is_array($t) || $t[0] !== T_STRING) continue;
            if ($t[1] === 'validateCsrf') { $hasCsrf = true; continue; }
            if ($t[1] === 'requirePermission') {
                // next non-trivial tokens: '(' then a quoted string literal
                $q = $p + 1;
                while ($q < $n && (is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE)) $q++;
                if ($q < $n && $tokens[$q] === '(') {
                    $q++;
                    while ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) $q++;
                    if ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_CONSTANT_ENCAPSED_STRING) {
                        $perms[] = trim($tokens[$q][1], "'\"");
                    }
                }
            }
        }

        if ($hasCsrf) {
            $checked++;
            $key = "{$controller}::{$method}";
            if ($perms && !in_array($key, $ALLOWLIST, true)) {
                // A write is safe if at least one gate is NOT read-level.
                $hasWriteGate = false;
                foreach ($perms as $perm) {
                    $suffix = strtolower(substr((string) strrchr($perm, '.'), 1));
                    if (!in_array($suffix, $READ_SUFFIXES, true)) { $hasWriteGate = true; break; }
                }
                if (!$hasWriteGate) {
                    $gaps[] = "{$key} (gated only on: " . implode(', ', $perms) . ')';
                }
            }
        }
        $i = $p + 1;
    }
}

echo "State-changing (CSRF-validating) actions scanned: {$checked}\n";
echo "Allowlisted: " . count($ALLOWLIST) . "\n";

if ($gaps) {
    echo "\nWrite methods gated ONLY on a read (*.view/*.read) permission:\n";
    foreach ($gaps as $g) echo "  [REVIEW] {$g}\n";
    echo "\nA state-changing action must require a write-level permission, not a read one.\n";
    exit(1);
}
echo "\nOK — no state-changing action is gated only on a read permission.\n";
exit(0);
