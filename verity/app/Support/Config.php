<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Environment-backed configuration accessor.
 *
 * All configuration — including the GCC High endpoints and the Entra app
 * registration values used by the (not-yet-wired) GCC High connector — comes
 * from the environment. Secrets must be supplied by the container
 * orchestrator or a secret manager — never hard-coded, never committed.
 */
final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $_ENV[$key] ?? $default;
        }
        return $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function env(): string
    {
        return self::get('APP_ENV', 'development') ?? 'development';
    }

    public static function databaseUrl(): ?string
    {
        return self::get('DATABASE_URL');
    }

    // --- Microsoft GCC High endpoints (NEVER commercial) --------------------
    // Section 5 of the Verity build directive: GCC High is mandatory and the
    // commercial graph.microsoft.com / login.microsoftonline.com endpoints must
    // never be used. No live connection is implemented yet (Phase 4) — these
    // accessors exist now so the connector framework has nowhere else to look
    // up the wrong host from when it is built.

    public static function graphBaseUrl(): string
    {
        return rtrim(self::get('GRAPH_BASE_URL', 'https://graph.microsoft.us') ?? 'https://graph.microsoft.us', '/');
    }

    public static function authorityHost(): string
    {
        return rtrim(self::get('ENTRA_AUTHORITY_HOST', 'https://login.microsoftonline.us') ?? 'https://login.microsoftonline.us', '/');
    }

    public static function azurePortalUrl(): string
    {
        return rtrim(self::get('AZURE_PORTAL_URL', 'https://portal.azure.us') ?? 'https://portal.azure.us', '/');
    }

    public static function tenantId(): ?string
    {
        return self::get('ENTRA_TENANT_ID');
    }

    public static function clientId(): ?string
    {
        return self::get('ENTRA_CLIENT_ID');
    }

    public static function clientSecret(): ?string
    {
        return self::get('ENTRA_CLIENT_SECRET');
    }

    public static function redirectUri(): ?string
    {
        return self::get('ENTRA_REDIRECT_URI');
    }

    /** True when the Entra GCC High app registration is configured enough to attempt sign-in. */
    public static function entraConfigured(): bool
    {
        return self::tenantId() !== null && self::clientId() !== null
            && self::clientSecret() !== null && self::redirectUri() !== null;
    }
}
