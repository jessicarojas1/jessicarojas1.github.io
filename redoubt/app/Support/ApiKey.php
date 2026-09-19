<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * API client authentication via bearer token.
 *
 * Tokens are presented as `Authorization: Bearer rk_<id>_<secret>`. Only a
 * SHA-256 hash of the secret is stored (api_client.key_hash); the plaintext is
 * shown once at creation. Each client is program-scoped and carries a list of
 * granular scopes that the API layer checks like permissions.
 */
final class ApiKey
{
    /** Generate a new token; returns [plaintext, id, hash]. */
    public static function generate(): array
    {
        $id = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(24));
        $plaintext = "rk_{$id}_{$secret}";
        return [$plaintext, $id, hash('sha256', $secret)];
    }

    /**
     * Resolve a bearer token to an api_client row, or null.
     * @return array<string,mixed>|null
     */
    public static function resolve(?string $bearer): ?array
    {
        if ($bearer === null || !str_starts_with($bearer, 'rk_') || !Db::isConfigured()) {
            return null;
        }
        $parts = explode('_', $bearer, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [, $id, $secret] = $parts;
        $row = Db::fetchOne(
            'SELECT id, name, program_id, scopes, key_hash, active FROM api_client WHERE client_ref = :ref',
            ['ref' => $id]
        );
        if ($row === null || !$row['active']) {
            return null;
        }
        if (!hash_equals((string) $row['key_hash'], hash('sha256', $secret))) {
            return null;
        }
        return $row;
    }

    /** Read the bearer token from the Authorization header. */
    public static function bearerFromRequest(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }
        return null;
    }
}
