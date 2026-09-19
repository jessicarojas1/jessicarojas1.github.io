<?php

declare(strict_types=1);

namespace Redoubt\Support;

use RuntimeException;

/**
 * Microsoft Graph client (GCC High) for the document system of record.
 *
 * Uses the client-credentials flow (app-only) against the GCC High token
 * endpoint and calls graph.microsoft.us. Prefer least-privilege application
 * permissions (Sites.Selected) granted per program site. Tokens are cached
 * in-process for their lifetime.
 *
 * This is the framework client; per-module document browsing/search is layered on
 * top in Phase 1 module work (see OPEN_ITEMS.md).
 */
final class Graph
{
    private static ?string $token = null;
    private static int $tokenExpiry = 0;

    public static function isConfigured(): bool
    {
        return Config::tenantId() && Config::clientId() && Config::clientSecret();
    }

    private static function appToken(): string
    {
        if (self::$token !== null && time() < self::$tokenExpiry - 60) {
            return self::$token;
        }
        if (!self::isConfigured()) {
            throw new RuntimeException('Graph is not configured.');
        }
        $url = Config::authorityHost() . '/' . Config::tenantId() . '/oauth2/v2.0/token';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => Config::clientId(),
                'client_secret' => Config::clientSecret(),
                'grant_type'    => 'client_credentials',
                'scope'         => Config::graphScope(),
            ]),
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new RuntimeException('Graph token error: ' . $err);
        }
        $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        if (!isset($data['access_token'])) {
            throw new RuntimeException('Graph token denied: ' . ($data['error_description'] ?? 'unknown'));
        }
        self::$token = (string) $data['access_token'];
        self::$tokenExpiry = time() + (int) ($data['expires_in'] ?? 3600);
        return self::$token;
    }

    /** GET an absolute Graph path (e.g. "/v1.0/sites/{id}/drive/root/children"). */
    public static function get(string $path): array
    {
        $url = Config::graphBaseUrl() . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . self::appToken(), 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            throw new RuntimeException('Graph request error: ' . $err);
        }
        if ($code === 429) {
            throw new RuntimeException('Graph throttled (429) — apply backoff.');
        }
        if ($code >= 400) {
            throw new RuntimeException('Graph error ' . $code);
        }
        return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    }
}
