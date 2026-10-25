<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Directory\DirectoryException;
use App\Identity\Directory\GraphDirectoryAdapter;
use App\Identity\Oidc\ClientCredentials;
use App\Identity\Oidc\OidcClient;
use App\Identity\Oidc\OidcProviderMetadata;
use App\Identity\Oidc\OidcSettings;
use App\Tests\Support\FakeEntraId;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GraphDirectoryAdapterTest extends TestCase
{
    private const GROUP = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse $graph
     */
    private function adapter(callable $graph): GraphDirectoryAdapter
    {
        $fake = new FakeEntraId();
        $http = new MockHttpClient(fn (string $method, string $url, array $options) => str_starts_with($url, FakeEntraId::GRAPH) ? $graph($method, $url, $options) : $fake($method, $url, $options));
        $settings = new OidcSettings(FakeEntraId::AUTHORITY, FakeEntraId::TENANT, FakeEntraId::CLIENT, 'test-client-secret', '', '', 'http://localhost');
        $metadata = new OidcProviderMetadata($http, new ArrayAdapter(), $settings);
        $client = new OidcClient($http, $metadata, $settings, new ClientCredentials($settings, new MockClock()), new NullLogger());

        return new GraphDirectoryAdapter($http, $client, $settings, FakeEntraId::GRAPH);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private static function json(array $data, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($data), ['http_code' => $status, 'response_headers' => $headers]);
    }

    public function testMembersArePagedAndNormalised(): void
    {
        $page2 = FakeEntraId::GRAPH.'/groups/'.self::GROUP.'/transitiveMembers/microsoft.graph.user?$skiptoken=2';
        $adapter = $this->adapter(function (string $method, string $url, array $options) use ($page2): MockResponse {
            self::assertContains('Authorization: Bearer graph-test-token', $options['headers']);

            return $url === $page2
                ? self::json(['value' => [['id' => 'BBBBBBBB-BBBB-4BBB-8BBB-BBBBBBBBBBBB', 'displayName' => 'Bea', 'accountEnabled' => false]]])
                : self::json(['value' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'displayName' => "Ada\u{0007}", 'mail' => 'ada@example.test', 'accountEnabled' => true], ['id' => 'not-a-guid']], '@odata.nextLink' => $page2]);
        });

        $members = $adapter->fetchGroupUserMembers(self::GROUP);

        self::assertCount(2, $members);
        self::assertSame('Ada', $members[0]->displayName);
        self::assertTrue($members[0]->accountEnabled);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $members[1]->objectId);
        self::assertFalse($members[1]->accountEnabled);
    }

    public function testPaginationLinksOutsideGraphAreRefused(): void
    {
        $adapter = $this->adapter(fn () => self::json(['value' => [], '@odata.nextLink' => 'https://evil.example/steal']));

        $this->expectException(DirectoryException::class);
        $adapter->fetchGroupUserMembers(self::GROUP);
    }

    public function testThrottlingIsRetriedThenSucceeds(): void
    {
        $calls = 0;
        $adapter = $this->adapter(function () use (&$calls): MockResponse {
            return 1 === ++$calls ? self::json([], 429, ['retry-after' => '1']) : self::json(['id' => self::GROUP, 'displayName' => 'Approvers']);
        });

        self::assertSame('Approvers', $adapter->fetchGroup(self::GROUP)->displayName);
        self::assertSame(2, $calls);
    }

    public function testNonGuidIdsAreRejectedBeforeAnyRequest(): void
    {
        $adapter = $this->adapter(fn () => self::fail('No request expected'));

        $this->expectException(DirectoryException::class);
        $adapter->fetchGroup('../users');
    }

    public function testServerErrorsBecomeDirectoryExceptions(): void
    {
        $adapter = $this->adapter(fn () => self::json(['error' => ['code' => 'Forbidden']], 403));

        $this->expectException(DirectoryException::class);
        $adapter->fetchUserGroupIds('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    }
}
