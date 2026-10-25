<?php

declare(strict_types=1);

namespace App\Identity\Directory;

use App\Identity\Oidc\OidcClient;
use App\Identity\Oidc\OidcException;
use App\Identity\Oidc\OidcSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Microsoft Graph, read-only, application permissions (GroupMember.Read.All, plus
 * User.Read.All for accountEnabled). Pagination links are only followed on the
 * configured Graph origin, so a tampered response cannot redirect requests elsewhere.
 */
final class GraphDirectoryAdapter implements DirectoryAdapter
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const MAX_PAGES = 200;
    private const MAX_RETRIES = 2;

    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OidcClient $oidcClient,
        private readonly OidcSettings $settings,
        #[Autowire(env: 'GRAPH_BASE_URL')]
        private readonly string $graphBaseUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured() && 'https' === parse_url($this->graphBaseUrl, \PHP_URL_SCHEME);
    }

    public function tenantId(): string
    {
        return $this->settings->tenantId();
    }

    public function fetchGroup(string $groupObjectId): DirectoryGroupInfo
    {
        $groupObjectId = self::assertUuid($groupObjectId);
        $data = $this->get($this->base().'/groups/'.$groupObjectId.'?$select=id,displayName');

        return new DirectoryGroupInfo(self::assertUuid(\is_string($data['id'] ?? null) ? $data['id'] : ''), self::name($data['displayName'] ?? null, $groupObjectId));
    }

    public function fetchGroupUserMembers(string $groupObjectId): array
    {
        $groupObjectId = self::assertUuid($groupObjectId);
        $users = [];
        $url = $this->base().'/groups/'.$groupObjectId.'/transitiveMembers/microsoft.graph.user'
            .'?$select=id,displayName,mail,userPrincipalName,accountEnabled&$top=999&$count=true';
        foreach ($this->paginate($url) as $item) {
            $id = \is_string($item['id'] ?? null) ? strtolower($item['id']) : '';
            if (1 !== preg_match(self::UUID, $id)) {
                continue;
            }
            $upn = \is_string($item['userPrincipalName'] ?? null) ? mb_substr($item['userPrincipalName'], 0, 320) : null;
            $users[$id] = new DirectoryUserInfo(
                $id,
                self::name($item['displayName'] ?? null, $upn ?? $id),
                \is_string($item['mail'] ?? null) ? mb_substr($item['mail'], 0, 320) : null,
                $upn,
                \is_bool($item['accountEnabled'] ?? null) ? $item['accountEnabled'] : null,
            );
        }

        return array_values($users);
    }

    public function fetchUserGroupIds(string $userObjectId): array
    {
        $userObjectId = self::assertUuid($userObjectId);
        $ids = [];
        $url = $this->base().'/users/'.$userObjectId.'/transitiveMemberOf/microsoft.graph.group?$select=id&$top=999&$count=true';
        foreach ($this->paginate($url) as $item) {
            $id = \is_string($item['id'] ?? null) ? strtolower($item['id']) : '';
            if (1 === preg_match(self::UUID, $id)) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function paginate(string $url): \Generator
    {
        $pages = 0;
        $next = $url;
        while (null !== $next) {
            if (++$pages > self::MAX_PAGES) {
                throw new DirectoryException('Graph pagination exceeded the page limit.');
            }
            $data = $this->get($next);
            foreach (\is_array($data['value'] ?? null) ? $data['value'] : [] as $item) {
                if (\is_array($item)) {
                    /** @var array<string, mixed> $item */
                    yield $item;
                }
            }
            $link = $data['@odata.nextLink'] ?? null;
            if (null === $link) {
                $next = null;
            } elseif (\is_string($link) && str_starts_with($link, $this->base().'/')) {
                $next = $link;
            } else {
                throw new DirectoryException('Graph returned a pagination link outside the Graph origin.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        for ($attempt = 0; ; ++$attempt) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->accessToken(),
                        'Accept' => 'application/json',
                        'ConsistencyLevel' => 'eventual',
                    ],
                    'timeout' => 15,
                    'max_redirects' => 0,
                ]);
                $status = $response->getStatusCode();
                if (\in_array($status, [429, 503, 504], true) && $attempt < self::MAX_RETRIES) {
                    $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 1);
                    usleep(min(max($retryAfter, 1), 5) * 1_000_000);
                    continue;
                }
                if (401 === $status && 0 === $attempt) {
                    $this->accessToken = null;
                    continue;
                }
                if ($status >= 400) {
                    throw new DirectoryException(\sprintf('Graph request failed with HTTP %d.', $status));
                }
                /** @var array<string, mixed> $data */
                $data = $response->toArray();

                return $data;
            } catch (DirectoryException $e) {
                throw $e;
            } catch (OidcException $e) {
                throw new DirectoryException('Could not obtain a Graph access token: '.$e->reasonCode, 0, $e);
            } catch (\Throwable $e) {
                throw new DirectoryException('Graph request failed.', 0, $e);
            }
        }
    }

    private function accessToken(): string
    {
        // Held in memory only: application tokens are never written to cache or disk.
        if (null === $this->accessToken || time() >= $this->accessTokenExpiresAt) {
            $origin = parse_url($this->graphBaseUrl, \PHP_URL_SCHEME).'://'.parse_url($this->graphBaseUrl, \PHP_URL_HOST);
            $token = $this->oidcClient->clientCredentialsToken($origin.'/.default');
            $this->accessToken = $token['access_token'];
            $this->accessTokenExpiresAt = time() + max($token['expires_in'] - 120, 60);
        }

        return $this->accessToken;
    }

    private function base(): string
    {
        if (!$this->isConfigured()) {
            throw new DirectoryException('Directory sync is not configured.');
        }

        return rtrim($this->graphBaseUrl, '/');
    }

    private static function assertUuid(string $id): string
    {
        $id = strtolower(trim($id));
        if (1 !== preg_match(self::UUID, $id)) {
            throw new DirectoryException('Directory object IDs must be GUIDs.');
        }

        return $id;
    }

    private static function name(mixed $value, string $fallback): string
    {
        $name = \is_string($value) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value)) : '';

        return '' === $name ? $fallback : mb_substr($name, 0, 256);
    }
}
