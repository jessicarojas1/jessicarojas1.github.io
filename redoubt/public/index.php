<?php
/**
 * REDOUBT — GMRE Program Portal Framework
 * Front controller for the Discovery Package microsite.
 *
 * This is the DISCOVERY-PHASE entry point. It intentionally serves a static,
 * read-only Product + Architecture Discovery Package. No program data, no
 * authentication, and no content-management surfaces are wired up yet — those
 * are Phase 1 (MVP) per docs/ARCHITECTURE.md and OPEN_ITEMS.md.
 *
 * Runtime: PHP 8.2+ (see composer.json). Deploy: Docker / Render (see Dockerfile,
 * render.yaml, deployments/LOCAL_DEVELOPMENT.md).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Per-request CSP nonce (single source of truth for any inline <script>/<style>).
// Mirrors the AEGIS Security::nonce() convention so Phase 1 can lift-and-shift.
// ---------------------------------------------------------------------------
$nonce = base64_encode(random_bytes(16));

// ---------------------------------------------------------------------------
// Security headers. Strict by default; relaxed ONLY to allow the Mermaid CDN
// used to render the two architecture diagrams. If the CDN is blocked (e.g.
// air-gapped preview), the page degrades to showing the diagram source.
// ---------------------------------------------------------------------------
$csp = implode('; ', [
    "default-src 'self'",
    "base-uri 'self'",
    "frame-ancestors 'none'",
    "object-src 'none'",
    "img-src 'self' data:",
    "font-src 'self' https://fonts.gstatic.com",
    "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
    "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net",
    "connect-src 'self' https://cdn.jsdelivr.net",
]);

header("Content-Security-Policy: {$csp}");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ---------------------------------------------------------------------------
// Minimal routing. Keep the surface tiny and predictable.
// ---------------------------------------------------------------------------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

switch ($path) {
    case '/health':
    case '/healthz':
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'ok',
            'service' => 'redoubt-program-portal',
            'phase'   => 'discovery',
            'time'    => gmdate('c'),
        ], JSON_THROW_ON_ERROR);
        return;

    case '/':
        $NONCE = $nonce; // exposed to the view
        require __DIR__ . '/../app/Views/discovery.php';
        return;

    default:
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 Not Found\n\nREDOUBT discovery site serves only '/' and '/health' during the discovery phase.";
        return;
}
