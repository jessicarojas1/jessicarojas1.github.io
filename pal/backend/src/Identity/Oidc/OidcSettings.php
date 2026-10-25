<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Entra ID application settings. Validated when sign-in is used rather than at boot,
 * so health checks and tooling work on a host where sign-in is not configured yet.
 */
final class OidcSettings
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        #[Autowire(env: 'OIDC_AUTHORITY')] private readonly string $authority,
        #[Autowire(env: 'OIDC_TENANT_ID')] private readonly string $tenantId,
        #[Autowire(env: 'OIDC_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'OIDC_CLIENT_SECRET')] private readonly string $clientSecret,
        #[Autowire(env: 'OIDC_CLIENT_CERT_PATH')] private readonly string $clientCertPath,
        #[Autowire(env: 'OIDC_CLIENT_KEY_PATH')] private readonly string $clientKeyPath,
        #[Autowire(env: 'PAL_BASE_URL')] private readonly string $baseUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return null === $this->configurationProblem();
    }

    public function assertConfigured(): void
    {
        $problem = $this->configurationProblem();
        if (null !== $problem) {
            throw new OidcException('not_configured', $problem);
        }
    }

    public function tenantId(): string
    {
        return strtolower($this->tenantId);
    }

    public function clientId(): string
    {
        return strtolower($this->clientId);
    }

    public function issuer(): string
    {
        return rtrim($this->authority, '/').'/'.$this->tenantId().'/v2.0';
    }

    public function discoveryUrl(): string
    {
        return $this->issuer().'/.well-known/openid-configuration';
    }

    public function authorityHost(): string
    {
        return (string) parse_url($this->authority, \PHP_URL_HOST);
    }

    public function redirectUri(): string
    {
        return rtrim($this->baseUrl, '/').'/auth/callback';
    }

    public function postLogoutRedirectUri(): string
    {
        return rtrim($this->baseUrl, '/').'/';
    }

    public function clientSecret(): ?string
    {
        return '' === $this->clientSecret ? null : $this->clientSecret;
    }

    public function clientCertPath(): ?string
    {
        return '' === $this->clientCertPath ? null : $this->clientCertPath;
    }

    public function clientKeyPath(): ?string
    {
        return '' === $this->clientKeyPath ? null : $this->clientKeyPath;
    }

    private function configurationProblem(): ?string
    {
        if (1 !== preg_match(self::UUID, $this->tenantId()) || '00000000-0000-0000-0000-000000000000' === $this->tenantId()) {
            return 'OIDC_TENANT_ID must be the Entra tenant GUID';
        }
        if (1 !== preg_match(self::UUID, $this->clientId()) || '00000000-0000-0000-0000-000000000000' === $this->clientId()) {
            return 'OIDC_CLIENT_ID must be the application (client) GUID';
        }
        if ('https' !== parse_url($this->authority, \PHP_URL_SCHEME)) {
            return 'OIDC_AUTHORITY must be an https URL';
        }
        $scheme = parse_url($this->baseUrl, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return 'PAL_BASE_URL must be an absolute http(s) URL';
        }
        $hasSecret = null !== $this->clientSecret();
        $hasCertificate = null !== $this->clientCertPath() && null !== $this->clientKeyPath();
        if ($hasSecret === $hasCertificate) {
            return 'configure exactly one client credential: OIDC_CLIENT_SECRET, or OIDC_CLIENT_CERT_PATH + OIDC_CLIENT_KEY_PATH';
        }

        return null;
    }
}
