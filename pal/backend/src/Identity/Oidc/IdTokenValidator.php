<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Symfony\Component\Clock\ClockInterface;

/**
 * Validates an Entra ID v2.0 ID token: RS256 signature against the provider's published
 * keys, lifetime, issuer, audience, tenant and nonce. Every rejection maps to one code.
 */
final class IdTokenValidator
{
    private const LEEWAY_SECONDS = 120;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly OidcProviderMetadata $metadata,
        private readonly OidcSettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    public function validate(string $idToken, string $expectedNonce): IdTokenClaims
    {
        $claims = $this->decode($idToken);

        if (!isset($claims['exp'], $claims['iat']) || !\is_int($claims['exp']) || !\is_int($claims['iat'])) {
            throw new OidcException('id_token_invalid', 'exp/iat missing');
        }
        if (($claims['iss'] ?? null) !== $this->settings->issuer()) {
            throw new OidcException('id_token_invalid', 'issuer mismatch');
        }
        $audience = $claims['aud'] ?? null;
        $audienceOk = \is_string($audience)
            ? strtolower($audience) === $this->settings->clientId()
            : \is_array($audience) && \in_array($this->settings->clientId(), array_map(fn (mixed $a): string => \is_string($a) ? strtolower($a) : '', $audience), true)
                && (1 === \count($audience) || self::lowerString($claims['azp'] ?? null) === $this->settings->clientId());
        if (!$audienceOk) {
            throw new OidcException('id_token_invalid', 'audience mismatch');
        }
        $tenantId = self::lowerString($claims['tid'] ?? null);
        if ($tenantId !== $this->settings->tenantId()) {
            throw new OidcException('id_token_invalid', 'tenant mismatch');
        }
        if (!\is_string($claims['nonce'] ?? null) || !hash_equals($expectedNonce, $claims['nonce'])) {
            throw new OidcException('id_token_invalid', 'nonce mismatch');
        }
        $objectId = self::lowerString($claims['oid'] ?? null);
        if (1 !== preg_match(self::UUID, $objectId)) {
            throw new OidcException('id_token_invalid', 'oid missing');
        }

        $upn = self::cleanString($claims['preferred_username'] ?? null, 320);
        $email = self::cleanString($claims['email'] ?? null, 320);

        return new IdTokenClaims(
            $tenantId,
            $objectId,
            self::cleanString($claims['name'] ?? null, 256) ?? $upn ?? 'Unknown user',
            $email,
            $upn,
            $claims['iat'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $idToken): array
    {
        JWT::$leeway = self::LEEWAY_SECONDS;
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        try {
            try {
                $payload = JWT::decode($idToken, JWK::parseKeySet($this->metadata->jwks(), 'RS256'));
            } catch (\UnexpectedValueException $e) {
                if (!str_contains($e->getMessage(), '"kid"')) {
                    throw $e;
                }
                // Unknown signing key: the provider may have rotated keys. Refresh once.
                $payload = JWT::decode($idToken, JWK::parseKeySet($this->metadata->jwks(true), 'RS256'));
            }
        } catch (OidcException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new OidcException('id_token_invalid', $e::class, $e);
        } finally {
            JWT::$timestamp = null;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($payload, \JSON_THROW_ON_ERROR), true, 32, \JSON_THROW_ON_ERROR);

        return $claims;
    }

    private static function lowerString(mixed $value): string
    {
        return \is_string($value) ? strtolower($value) : '';
    }

    private static function cleanString(mixed $value, int $maxLength): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value));

        return '' === $clean ? null : mb_substr($clean, 0, $maxLength);
    }
}
