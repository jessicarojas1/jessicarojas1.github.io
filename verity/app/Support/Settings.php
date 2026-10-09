<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Platform-wide settings persisted in app_config (single-tenant key/JSON
 * value store — Verity has no program/company tenancy). Covers the mandatory
 * Settings/Branding standard: logo URL, display name, accent color.
 *
 * Values are sanitized on write; views still escape on output.
 */
final class Settings
{
    /** @return array<string,mixed> */
    public static function get(string $key, array $default = []): array
    {
        if (!Db::isConfigured()) {
            return $default;
        }
        $row = Db::fetchOne('SELECT json_value FROM app_config WHERE key = :k', ['k' => $key]);
        if ($row === null) {
            return $default;
        }
        $v = is_string($row['json_value']) ? json_decode($row['json_value'], true) : $row['json_value'];
        return is_array($v) ? $v + $default : $default;
    }

    /** @param array<string,mixed> $value */
    public static function set(string $key, array $value, ?int $actorId = null): void
    {
        Db::query(
            'INSERT INTO app_config (key, json_value) VALUES (:k, :v::jsonb)
             ON CONFLICT (key) DO UPDATE SET json_value = EXCLUDED.json_value, updated_at = NOW()',
            ['k' => $key, 'v' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        Audit::log('settings.save', $key, null, $value, null, $actorId);
    }

    // --- Branding -------------------------------------------------------

    /** @return array{logoUrl:?string,displayName:?string,accent:?string} */
    public static function branding(): array
    {
        $b = self::get('branding');
        return [
            'logoUrl' => self::safeLogoUrl($b['logoUrl'] ?? null),
            'displayName' => self::safeName($b['displayName'] ?? null),
            'accent' => self::safeAccent($b['accent'] ?? null),
        ];
    }

    public static function saveBranding(array $in, ?int $actorId): void
    {
        self::set('branding', [
            'logoUrl' => self::safeLogoUrl($in['logoUrl'] ?? null),
            'displayName' => self::safeName($in['displayName'] ?? null),
            'accent' => self::safeAccent($in['accent'] ?? null),
        ], $actorId);
    }

    // --- Sanitizers -------------------------------------------------------

    /** Allow only http(s) URLs or data:image/... URIs; else null. */
    public static function safeLogoUrl(?string $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url) || preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=\s]+$#', $url)) {
            return $url;
        }
        return null;
    }

    public static function safeName(?string $name): ?string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            return null;
        }
        return mb_substr($name, 0, 80);
    }

    /** Allow only a hex color (#rgb, #rrggbb, #rrggbbaa); else null. */
    public static function safeAccent(?string $c): ?string
    {
        $c = is_string($c) ? trim($c) : '';
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $c) ? $c : null;
    }
}
