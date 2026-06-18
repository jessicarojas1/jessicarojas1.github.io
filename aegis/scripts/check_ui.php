#!/usr/bin/env php
<?php
/**
 * UI / CSP consistency linter — enforces the CLAUDE.md frontend rules statically.
 *
 *   1. No inline event handlers (onclick=, onchange=, onsubmit=, oninput=, …) in
 *      server-rendered views — interactivity must be delegated via data-* in
 *      public/js/app.js so the strict CSP (no 'unsafe-inline') holds.
 *   2. Every <script> tag carries a nonce (inline or external), except
 *      type="application/ld+json" data blocks.
 *
 * Scans aegis/views/**.php. Exit 0 = clean, 1 = violations found.
 * Wire into CI so a CSP regression fails the build.
 *
 * Usage:  php scripts/check_ui.php
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$root     = dirname(__DIR__);
$viewsDir = $root . '/views';

$HANDLERS = ['onclick','ondblclick','onchange','onsubmit','oninput','onload','onunload',
    'onmouseover','onmouseout','onmousedown','onmouseup','onkeyup','onkeydown','onkeypress',
    'onfocus','onblur','onscroll','onerror','onreset','onselect','ontoggle','oncontextmenu'];
$handlerRe = '/\s(' . implode('|', $HANDLERS) . ')\s*=/i';

$violations = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path  = $file->getPathname();
    $rel   = 'views' . substr($path, strlen($viewsDir));
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    foreach ($lines as $n => $line) {
        // Rule 1: inline event handler attributes.
        if (preg_match($handlerRe, $line, $m)) {
            $violations[] = sprintf('%s:%d  inline handler %s=  → use data-* + app.js', $rel, $n + 1, strtolower($m[1]));
        }
        // Rule 2: <script> without nonce (skip JSON-LD data blocks and closing tags).
        if (preg_match('/<script\b(?![^>]*\bnonce=)(?![^>]*application\/ld\+json)/i', $line)) {
            $violations[] = sprintf('%s:%d  <script> without nonce → add nonce="<?= Security::nonce() ?>"', $rel, $n + 1);
        }
    }
}

if ($violations) {
    echo "UI/CSP violations (" . count($violations) . "):\n";
    foreach ($violations as $v) echo "  [ERROR] {$v}\n";
    echo "\nFix by moving handlers to data-* attributes (public/js/app.js) and adding nonces.\n";
    exit(1);
}

echo "OK — no inline event handlers; all <script> tags carry a nonce.\n";
exit(0);
