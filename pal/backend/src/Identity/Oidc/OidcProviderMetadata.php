<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and caches the provider's discovery document and signing keys. Endpoints are
 * only accepted over https on the configured authority host.
 */
final class OidcProviderMetadata
{
    private const TTL = 86_400;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly OidcSettings $settings,
    ) {
    }

    public function authorizationEndpoint(): string
    {
        return $this->endpoint('authorization_endpoint');
    }

    public function tokenEndpoint(): string
    {
        return $this->endpoint('token_endpoint');
    }

    public function endSessionEndpoint(): ?string
    {
        $document = $this->document();

        return isset($document['end_session_endpoint']) && \is_string($document['end_session_endpoint'])
            ? $this->assertTrustedUrl($document['end_session_endpoint'])
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jwks(bool $forceRefresh = false): array
    {
        $key = 'pal.oidc.jwks.'.hash('sha256', $this->settings->discoveryUrl());
        if ($forceRefresh) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);
            $jwks = $this->fetchJson($this->endpoint('jwks_uri'));
            if (!isset($jwks['keys']) || !\is_array($jwks['keys'])) {
                throw new OidcException('provider_unavailable', 'JWKS document has no keys');
            }

            return $jwks;
        });
    }

    private function endpoint(string $name): string
    {
        $document = $this->document();
        if (!isset($document[$name]) || !\is_string($document[$name])) {
            throw new OidcException('provider_unavailable', "discovery document has no {$name}");
        }

        return $this->assertTrustedUrl($document[$name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $this->settings->assertConfigured();

        return $this->cache->get('pal.oidc.discovery.'.hash('sha256', $this->settings->discoveryUrl()), function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);

            return $this->fetchJson($this->settings->discoveryUrl());
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 10, 'max_redirects' => 0]);
            /** @var array<string, mixed> $data */
            $data = $response->toArray();

            return $data;
        } catch (\Throwable $e) {
            throw new OidcException('provider_unavailable', 'could not fetch provider metadata', $e);
        }
    }

    private function assertTrustedUrl(string $url): string
    {
        if ('https' !== parse_url($url, \PHP_URL_SCHEME) || parse_url($url, \PHP_URL_HOST) !== $this->settings->authorityHost()) {
            throw new OidcException('provider_untrusted', 'endpoint is not https on the authority host');
        }

        return $url;
    }
}
