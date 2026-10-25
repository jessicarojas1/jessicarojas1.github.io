<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

use Firebase\JWT\JWT;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Authenticates PAL to the token endpoint: a client secret for development, or a signed
 * certificate assertion (RFC 7523 private_key_jwt) for production.
 */
final class ClientCredentials
{
    public function __construct(
        private readonly OidcSettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function tokenRequestParameters(string $tokenEndpoint): array
    {
        $secret = $this->settings->clientSecret();
        if (null !== $secret) {
            return ['client_secret' => $secret];
        }

        return [
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $this->certificateAssertion($tokenEndpoint),
        ];
    }

    private function certificateAssertion(string $audience): string
    {
        $certPath = $this->settings->clientCertPath();
        $keyPath = $this->settings->clientKeyPath();
        if (null === $certPath || null === $keyPath || !is_readable($certPath) || !is_readable($keyPath)) {
            throw new OidcException('not_configured', 'client certificate or key is not readable');
        }
        $certificate = openssl_x509_read((string) file_get_contents($certPath));
        $privateKey = openssl_pkey_get_private((string) file_get_contents($keyPath));
        if (false === $certificate || false === $privateKey || !openssl_x509_export($certificate, $pem) || !\is_string($pem)) {
            throw new OidcException('not_configured', 'client certificate or key could not be parsed');
        }
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '', true);
        if (false === $der) {
            throw new OidcException('not_configured', 'client certificate could not be decoded');
        }

        $now = $this->clock->now()->getTimestamp();

        return JWT::encode(
            [
                'aud' => $audience,
                'iss' => $this->settings->clientId(),
                'sub' => $this->settings->clientId(),
                'jti' => Uuid::v4()->toRfc4122(),
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + 300,
            ],
            $privateKey,
            'RS256',
            null,
            [
                'x5t' => AuthorizationRequest::base64Url(hash('sha1', $der, true)),
                'x5t#S256' => AuthorizationRequest::base64Url(hash('sha256', $der, true)),
            ],
        );
    }
}
