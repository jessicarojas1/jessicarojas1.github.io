<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Authorization-code flow with PKCE (sign-in) and client-credentials flow (Graph sync)
 * against Entra ID. PAL never keeps user access or refresh tokens: after sign-in the
 * user is identified by PAL's own server-side session.
 */
final class OidcClient
{
    private const SCOPES = 'openid profile email';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OidcProviderMetadata $metadata,
        private readonly OidcSettings $settings,
        private readonly ClientCredentials $credentials,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authorizationUrl(AuthorizationRequest $request): string
    {
        $query = http_build_query([
            'client_id' => $this->settings->clientId(),
            'response_type' => 'code',
            'response_mode' => 'query',
            'redirect_uri' => $this->settings->redirectUri(),
            'scope' => self::SCOPES,
            'state' => $request->state,
            'nonce' => $request->nonce,
            'code_challenge' => $request->codeChallenge(),
            'code_challenge_method' => 'S256',
        ], '', '&', \PHP_QUERY_RFC3986);

        return $this->metadata->authorizationEndpoint().'?'.$query;
    }

    public function exchangeCode(string $code, AuthorizationRequest $request): string
    {
        $tokenEndpoint = $this->metadata->tokenEndpoint();
        $response = $this->postForm($tokenEndpoint, [
            'grant_type' => 'authorization_code',
            'client_id' => $this->settings->clientId(),
            'code' => $code,
            'redirect_uri' => $this->settings->redirectUri(),
            'code_verifier' => $request->codeVerifier,
            'scope' => self::SCOPES,
            ...$this->credentials->tokenRequestParameters($tokenEndpoint),
        ]);
        if (!isset($response['id_token']) || !\is_string($response['id_token'])) {
            throw new OidcException('token_exchange_failed', 'no id_token in response');
        }

        return $response['id_token'];
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function clientCredentialsToken(string $scope): array
    {
        $tokenEndpoint = $this->metadata->tokenEndpoint();
        $response = $this->postForm($tokenEndpoint, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->settings->clientId(),
            'scope' => $scope,
            ...$this->credentials->tokenRequestParameters($tokenEndpoint),
        ]);
        if (!isset($response['access_token']) || !\is_string($response['access_token'])) {
            throw new OidcException('token_exchange_failed', 'no access_token in response');
        }

        return [
            'access_token' => $response['access_token'],
            'expires_in' => isset($response['expires_in']) && is_numeric($response['expires_in']) ? (int) $response['expires_in'] : 300,
        ];
    }

    /**
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    private function postForm(string $url, array $form): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'body' => $form,
                'timeout' => 10,
                'max_redirects' => 0,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            /** @var array<string, mixed> $data */
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new OidcException('provider_unavailable', 'token endpoint unreachable', $e);
        }
        if ($status >= 400) {
            $error = \is_string($data['error'] ?? null) ? $data['error'] : 'unknown';
            $this->logger->warning('Token endpoint rejected the request', [
                'status' => $status,
                'error' => $error,
                'error_codes' => $data['error_codes'] ?? null,
                'trace_id' => $data['trace_id'] ?? null,
            ]);
            throw new OidcException('token_exchange_failed', 'provider error '.preg_replace('/[^a-z_]/', '', $error));
        }

        return $data;
    }
}
