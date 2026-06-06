<?php
/**
 * APEX - Application settings (branding).
 *
 * Implements the project "Settings & Branding Standard":
 *   GET  /api/settings/branding   read current branding (public — needed by the
 *                                 login/landing screen before sign-in)
 *   POST /api/settings/branding   update branding (admin only)
 *
 * Branding is shared, server-side state stored in the `app_settings` table.
 * The table is created idempotently here so deployments whose schema predates
 * this feature pick it up automatically (the schema.sql installer also defines
 * it for fresh installs).
 *
 * Write protection follows APEX's existing convention: there is no separate
 * CSRF token system — the API is authenticated by a JWT supplied in the
 * `Authorization: Bearer` header (read from in-memory / localStorage, not
 * auto-attached cross-site), and mutating routes additionally enforce role via
 * Auth::requireRole('admin').
 */

declare(strict_types=1);

use Apex\Auth;
use Apex\Database;
use Apex\Response;

/** @var \Apex\Router $router */

const APEX_BRANDING_KEY = 'branding';

// Built-in defaults — the app's stock mark / name / accent. Used as the
// empty-state so unset branding never breaks the UI.
const APEX_BRANDING_DEFAULTS = [
    'displayName' => 'APEX',
    'logoUrl'     => '',
    'accentColor' => '#6366f1',
];

/** Ensure the settings table exists (idempotent — safe on every call). */
function apexEnsureSettingsTable(): void
{
    Database::execute(
        "CREATE TABLE IF NOT EXISTS app_settings (
            key        VARCHAR(50)  PRIMARY KEY,
            value      JSONB        NOT NULL DEFAULT '{}'::jsonb,
            updated_at TIMESTAMPTZ  DEFAULT NOW()
        )"
    );
}

/**
 * Sanitize a logo URL: allow only http(s):// or data:image/... — anything
 * else (javascript:, data:text/html, etc.) is rejected to an empty string so
 * the UI falls back to the built-in mark.
 */
function apexSanitizeLogoUrl(mixed $raw): string
{
    $url = trim((string)$raw);
    if ($url === '') {
        return '';
    }
    if (strlen($url) > 2_000_000) { // ~2MB cap for data: URLs
        return '';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (preg_match('#^data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=\s]+$#i', $url)) {
        return $url;
    }
    return '';
}

/** Validate a hex accent colour (#rgb or #rrggbb); fall back to default. */
function apexSanitizeAccent(mixed $raw): string
{
    $c = trim((string)$raw);
    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c)) {
        return strtolower($c);
    }
    return APEX_BRANDING_DEFAULTS['accentColor'];
}

/** Merge stored branding over defaults. */
function apexLoadBranding(): array
{
    apexEnsureSettingsTable();
    $row = Database::fetchOne(
        'SELECT value FROM app_settings WHERE key = :k',
        [':k' => APEX_BRANDING_KEY]
    );
    $stored = [];
    if ($row !== null && isset($row['value'])) {
        $decoded = json_decode((string)$row['value'], true);
        if (is_array($decoded)) {
            $stored = $decoded;
        }
    }
    return [
        'displayName' => (string)($stored['displayName'] ?? APEX_BRANDING_DEFAULTS['displayName']),
        'logoUrl'     => (string)($stored['logoUrl']     ?? APEX_BRANDING_DEFAULTS['logoUrl']),
        'accentColor' => (string)($stored['accentColor'] ?? APEX_BRANDING_DEFAULTS['accentColor']),
    ];
}

// ── Routes ─────────────────────────────────────────────────────────────

// Public read so the login/landing screen can brand itself pre-auth.
$router->get('/api/settings/branding', function () {
    Response::ok(apexLoadBranding());
});

$router->post('/api/settings/branding', function () {
    Auth::requireRole('admin');
    apexEnsureSettingsTable();

    $body = Response::readJsonBody();

    $displayName = trim((string)($body['displayName'] ?? ''));
    if ($displayName === '') {
        $displayName = APEX_BRANDING_DEFAULTS['displayName'];
    }
    // Cap length and strip control chars; output is always escaped client-side.
    $displayName = preg_replace('/[\x00-\x1F\x7F]/u', '', $displayName) ?? '';
    $displayName = mb_substr($displayName, 0, 120);

    $branding = [
        'displayName' => $displayName,
        'logoUrl'     => apexSanitizeLogoUrl($body['logoUrl'] ?? ''),
        'accentColor' => apexSanitizeAccent($body['accentColor'] ?? ''),
    ];

    $json = json_encode($branding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Upsert. updated_at maintained explicitly here (raw SQL, not Database::update()).
    Database::execute(
        "INSERT INTO app_settings (key, value, updated_at)
              VALUES (:k, CAST(:v AS jsonb), NOW())
         ON CONFLICT (key)
         DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()",
        [':k' => APEX_BRANDING_KEY, ':v' => $json]
    );

    Response::ok($branding);
});
