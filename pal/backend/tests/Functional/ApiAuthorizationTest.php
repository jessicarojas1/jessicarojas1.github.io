<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Security\PalUserProvider;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Endpoint × role checks for the Phase 1 API, plus CSRF and input validation.
 */
final class ApiAuthorizationTest extends WebTestCase
{
    use DatabaseReset;

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
    }

    private function signInAs(string $objectId, ?string $role = null): string
    {
        $userId = self::createUser($this->db, $objectId, 'User '.substr($objectId, 0, 4));
        if (null !== $role) {
            self::grantRole($this->db, $userId, $role);
        }
        $this->client->loginUser(self::getContainer()->get(PalUserProvider::class)->loadUserByIdentifier($userId));

        return $userId;
    }

    private function csrf(): string
    {
        $this->client->request('GET', '/api/me');

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true)['csrfToken'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sendJson(string $method, string $uri, array $body, ?string $csrf): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $csrf) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $this->client->request($method, $uri, server: $server, content: json_encode($body));
    }

    public function testProtectedEndpointsRequireSignIn(): void
    {
        foreach (['/api/me', '/api/admin/role-mappings'] as $uri) {
            $this->client->request('GET', $uri);
            self::assertResponseStatusCodeSame(401, $uri);
            self::assertSame('unauthenticated', json_decode((string) $this->client->getResponse()->getContent(), true)['error']);
        }
    }

    public function testBrandingIsPublicToRead(): void
    {
        $this->client->request('GET', '/api/branding');

        self::assertResponseIsSuccessful();
        self::assertSame('PAL', json_decode((string) $this->client->getResponse()->getContent(), true)['displayName']);
    }

    public function testReadersCannotUseAdministration(): void
    {
        $this->signInAs('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $csrf = $this->csrf();

        $this->client->request('GET', '/api/admin/role-mappings');
        self::assertResponseStatusCodeSame(403);
        $this->sendJson('PUT', '/api/admin/branding', ['displayName' => 'Hacked'], $csrf);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPalAdministratorsCannotChangeSystemConfiguration(): void
    {
        $this->signInAs('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'PAL_ADMINISTRATOR');

        $this->sendJson('PUT', '/api/admin/branding', ['displayName' => 'Nope'], $this->csrf());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/admin/role-mappings');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSystemAdministratorUpdatesBrandingWithAudit(): void
    {
        $this->signInAs('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'SYSTEM_ADMINISTRATOR');
        $logo = 'data:image/png;base64,'.base64_encode('png-bytes');

        $this->sendJson('PUT', '/api/admin/branding', ['displayName' => 'Acme PAL', 'logoUrl' => $logo, 'accentColor' => '#112233'], $this->csrf());

        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/branding');
        $branding = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Acme PAL', $branding['displayName']);
        self::assertSame('#112233', $branding['accentColor']);
        $changes = json_decode((string) $this->db->fetchOne("SELECT changes FROM audit_event WHERE event_type = 'BRANDING_UPDATED'"), true);
        self::assertStringStartsWith('data-url sha256:', $changes['logo']['to']);
    }

    public function testBootstrapAdministratorFromEnvironment(): void
    {
        $this->signInAs('99999999-9999-4999-8999-999999999999');
        $this->client->request('GET', '/api/me');

        self::assertContains('SYSTEM_ADMINISTRATOR', json_decode((string) $this->client->getResponse()->getContent(), true)['roles']);
    }

    public function testStateChangingRequestsRequireAValidCsrfToken(): void
    {
        $this->signInAs('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        $this->sendJson('PATCH', '/api/me/preferences', ['timezone' => 'Europe/Berlin'], null);
        self::assertResponseStatusCodeSame(403);
        $this->sendJson('PATCH', '/api/me/preferences', ['timezone' => 'Europe/Berlin'], 'forged-token');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->db->fetchOne('SELECT timezone FROM app_user'));
    }

    public function testTimezonePreferenceIsValidatedAndAudited(): void
    {
        $this->signInAs('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $csrf = $this->csrf();

        $this->sendJson('PATCH', '/api/me/preferences', ['timezone' => '+02:00'], $csrf);
        self::assertResponseStatusCodeSame(422);
        $this->sendJson('PATCH', '/api/me/preferences', ['timezone' => 'Europe/Berlin'], $csrf);
        self::assertResponseIsSuccessful();

        self::assertSame('Europe/Berlin', $this->db->fetchOne('SELECT timezone FROM app_user'));
        self::assertSame('USER_PREFERENCES_CHANGED', $this->db->fetchOne('SELECT event_type FROM audit_event ORDER BY id DESC LIMIT 1'));
    }

    public function testMalformedBodiesAreRejectedCleanly(): void
    {
        $this->signInAs('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $csrf = $this->csrf();

        $this->client->request('PATCH', '/api/me/preferences', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: '{not json');
        self::assertResponseStatusCodeSame(400);
        $this->client->request('PATCH', '/api/me/preferences', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_X_CSRF_TOKEN' => $csrf], content: '{}');
        self::assertResponseStatusCodeSame(415);
    }

    public function testDisablingAUserEndsTheirAccessOnTheNextRequest(): void
    {
        $userId = $this->signInAs('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();

        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$userId]);
        $this->client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRoleChangesApplyWithoutSigningOut(): void
    {
        $userId = $this->signInAs('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $this->client->request('GET', '/api/admin/role-mappings');
        self::assertResponseStatusCodeSame(403);

        self::grantRole($this->db, $userId, 'SYSTEM_ADMINISTRATOR');
        $this->client->request('GET', '/api/admin/role-mappings');

        self::assertResponseIsSuccessful();
    }
}
