<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testLivenessAndReadiness(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health/live');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/health/ready');
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ready', 'checks' => ['database' => 'ok', 'migrations' => 'ok']], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testSecurityHeadersAndRequestIdArePresent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health/live', server: ['HTTP_X_REQUEST_ID' => 'upstream-req-0001']);

        $headers = $client->getResponse()->headers;
        self::assertSame('upstream-req-0001', $headers->get('X-Request-Id'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertStringContainsString("default-src 'none'", (string) $headers->get('Content-Security-Policy'));
    }

    public function testMalformedRequestIdsAreReplaced(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health/live', server: ['HTTP_X_REQUEST_ID' => "bad id\r\n"]);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $client->getResponse()->headers->get('X-Request-Id'));
    }
}
