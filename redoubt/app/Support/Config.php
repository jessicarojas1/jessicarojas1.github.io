<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Environment-backed configuration accessor.
 *
 * All configuration (including the GCC High endpoints and Entra app-registration
 * values) comes from the environment. Secrets must be supplied by the container
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
        return self::get('APP_ENV', 'discovery') ?? 'discovery';
    }

    // --- Microsoft cloud endpoints (default to GCC High, NOT commercial) ----

    public static function authorityHost(): string
    {
        return rtrim(self::get('ENTRA_AUTHORITY_HOST', 'https://login.microsoftonline.us'), '/');
    }

    public static function graphBaseUrl(): string
    {
        return rtrim(self::get('GRAPH_BASE_URL', 'https://graph.microsoft.us'), '/');
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

    public static function graphScope(): string
    {
        return self::get('GRAPH_SCOPES', self::graphBaseUrl() . '/.default') ?? self::graphBaseUrl() . '/.default';
    }

    /** True when the Entra app registration is configured enough to attempt sign-in. */
    public static function authConfigured(): bool
    {
        return self::tenantId() && self::clientId() && self::clientSecret() && self::redirectUri();
    }

    public static function databaseUrl(): ?string
    {
        return self::get('DATABASE_URL');
    }

    /**
     * Entra SSO is OPTIONAL. When configured it's offered as an additional sign-in
     * method; the portal always supports built-in local email/password accounts so
     * it works standalone with no external API. (authConfigured() above reports
     * whether the Entra option is available.)
     */
}
