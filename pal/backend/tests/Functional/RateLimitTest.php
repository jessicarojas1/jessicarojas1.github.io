<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The test environment lowers the API budget to 5 requests per minute.
 */
final class RateLimitTest extends WebTestCase
{
    public function testApiRequestsBeyondTheBudgetAreRejectedWithRetryAfter(): void
    {
        $client = self::createClient();
        // Keep one kernel so the in-memory test cache (rate-limit state) survives between requests.
        $client->disableReboot();
        $server = ['REMOTE_ADDR' => '198.51.'.random_int(0, 255).'.'.random_int(1, 254)];

        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/api/branding', server: $server);
            self::assertResponseIsSuccessful();
        }
        $client->request('GET', '/api/branding', server: $server);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }

    public function testBudgetsAreSeparatePerClient(): void
    {
        $client = self::createClient();
        // Keep one kernel so the in-memory test cache (rate-limit state) survives between requests.
        $client->disableReboot();
        $first = '203.0.113.'.random_int(1, 127);
        $second = '203.0.113.'.random_int(128, 254);
        for ($i = 0; $i < 5; ++$i) {
            $client->request('GET', '/api/branding', server: ['REMOTE_ADDR' => $first]);
        }
        $client->request('GET', '/api/branding', server: ['REMOTE_ADDR' => $second]);

        self::assertResponseIsSuccessful();
    }
}
