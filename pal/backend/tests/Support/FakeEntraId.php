<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Firebase\JWT\JWT;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * In-process stand-in for Entra ID and Microsoft Graph, wired in as the test HTTP
 * client's mock response factory. Signs real RS256 ID tokens with a per-process key,
 * publishing a JWKS without "alg" exactly as Entra does.
 */
final class FakeEntraId
{
    public const TENANT = '11111111-1111-4111-8111-111111111111';
    public const CLIENT = '22222222-2222-4222-8222-222222222222';
    public const AUTHORITY = 'https://login.microsoftonline.com';
    public const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** @var array<string, mixed> claims for the next authorization-code exchange (merged over defaults) */
    public static array $nextIdTokenClaims = [];
    /** @var array<string, list<string>> user object ID => group object IDs */
    public static array $userGroups = [];
    /** @var array<string, array{displayName: string, members: list<array<string, mixed>>}> */
    public static array $groups = [];
    public static ?string $nextTokenError = null;
    /** @var list<array{method: string, url: string, body: string}> */
    public static array $requests = [];

    private static ?\OpenSSLAsymmetricKey $key = null;

    public static function reset(): void
    {
        self::$nextIdTokenClaims = [];
        self::$userGroups = [];
        self::$groups = [];
        self::$nextTokenError = null;
        self::$requests = [];
    }

    public static function issuer(): string
    {
        return self::AUTHORITY.'/'.self::TENANT.'/v2.0';
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function mintIdToken(array $claims, ?\OpenSSLAsymmetricKey $signingKey = null, string $kid = 'test-key-1'): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => self::issuer(),
            'aud' => self::CLIENT,
            'tid' => self::TENANT,
            'oid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'name' => 'Ada Author',
            'preferred_username' => 'ada@example.test',
            'email' => 'ada@example.test',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'ver' => '2.0',
        ], $claims);
        $payload = array_filter($payload, fn (mixed $v): bool => null !== $v);

        return JWT::encode($payload, $signingKey ?? self::key(), 'RS256', $kid);
    }

    public static function key(): \OpenSSLAsymmetricKey
    {
        if (null === self::$key) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
            if (false === $key) {
                throw new \RuntimeException('Could not generate a test RSA key.');
            }
            self::$key = $key;
        }

        return self::$key;
    }

    /**
     * @return array<string, mixed>
     */
    public static function jwks(): array
    {
        $details = openssl_pkey_get_details(self::key());
        if (false === $details) {
            throw new \RuntimeException('Could not read the test key.');
        }
        $b64 = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'test-key-1', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $body = \is_string($options['body'] ?? null) ? $options['body'] : '';
        self::$requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
        $base = self::AUTHORITY.'/'.self::TENANT;

        return match (true) {
            $url === self::issuer().'/.well-known/openid-configuration' => self::json([
                'issuer' => self::issuer(),
                'authorization_endpoint' => $base.'/oauth2/v2.0/authorize',
                'token_endpoint' => $base.'/oauth2/v2.0/token',
                'jwks_uri' => $base.'/discovery/v2.0/keys',
                'end_session_endpoint' => $base.'/oauth2/v2.0/logout',
            ]),
            $url === $base.'/discovery/v2.0/keys' => self::json(self::jwks()),
            $url === $base.'/oauth2/v2.0/token' => $this->token($body),
            str_starts_with($url, self::GRAPH.'/') => $this->graph(substr($url, \strlen(self::GRAPH))),
            default => new MockResponse('{"error":"unexpected_url"}', ['http_code' => 599]),
        };
    }

    private function token(string $body): MockResponse
    {
        parse_str($body, $form);
        if (null !== self::$nextTokenError) {
            $error = self::$nextTokenError;
            self::$nextTokenError = null;

            return self::json(['error' => $error, 'error_description' => 'AADSTS-test'], 400);
        }
        if ('client_credentials' === ($form['grant_type'] ?? null)) {
            return self::json(['access_token' => 'graph-test-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
        }
        if ('authorization_code' === ($form['grant_type'] ?? null) && 'test-client-secret' === ($form['client_secret'] ?? null) && '' !== ($form['code_verifier'] ?? '')) {
            return self::json(['id_token' => self::mintIdToken(self::$nextIdTokenClaims), 'token_type' => 'Bearer']);
        }

        return self::json(['error' => 'invalid_request'], 400);
    }

    private function graph(string $path): MockResponse
    {
        $route = (string) parse_url($path, \PHP_URL_PATH);
        if (1 === preg_match('#^/users/([0-9a-f-]{36})/transitiveMemberOf/microsoft\.graph\.group$#', $route, $m)) {
            return self::json(['value' => array_map(fn (string $id): array => ['id' => $id], self::$userGroups[$m[1]] ?? [])]);
        }
        if (1 === preg_match('#^/groups/([0-9a-f-]{36})/transitiveMembers/microsoft\.graph\.user$#', $route, $m)) {
            return isset(self::$groups[$m[1]]) ? self::json(['value' => self::$groups[$m[1]]['members']]) : self::json(['error' => ['code' => 'Request_ResourceNotFound']], 404);
        }
        if (1 === preg_match('#^/groups/([0-9a-f-]{36})$#', $route, $m)) {
            return isset(self::$groups[$m[1]]) ? self::json(['id' => $m[1], 'displayName' => self::$groups[$m[1]]['displayName']]) : self::json(['error' => ['code' => 'Request_ResourceNotFound']], 404);
        }

        return self::json(['error' => ['code' => 'BadRequest']], 400);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
