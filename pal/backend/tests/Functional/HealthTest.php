<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
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
        self::assertSame(['status' => 'ready', 'checks' => ['database' => 'ok', 'migrations' => 'ok', 'schema_version' => 'ok', 'storage' => 'ok']], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testReadinessFailsWhenTheDatabaseSchemaIsNewerThanTheImage(): void
    {
        $client = self::createClient();
        $db = self::getContainer()->get(Connection::class);
        // Simulate a newer image having migrated the DB: an executed version this image lacks.
        $ghost = 'DoctrineMigrations\\Version29990101000000';
        $db->executeStatement('INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES (?, now(), 1)', [$ghost]);

        try {
            $client->request('GET', '/health/ready');
            self::assertResponseStatusCodeSame(503);
            $body = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertSame('not_ready', $body['status']);
            self::assertSame('fail', $body['checks']['schema_version'], 'an older image against a newer schema is not ready');
            self::assertSame('ok', $body['checks']['migrations'], 'no migration is pending; only the image is behind');
        } finally {
            $db->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [$ghost]);
        }
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
