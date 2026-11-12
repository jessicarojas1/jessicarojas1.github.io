<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Authorization\Permission;
use App\Identity\Security\PalUserProvider;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Owner (superuser / god mode) role: it holds every permission, it is granted or revoked
 * only by another Owner, it can never be conferred through a directory-group mapping, and an
 * Entra object ID in PAL_BOOTSTRAP_OWNER_OIDS becomes an Owner. Every action stays audited and
 * authorised server-side like any other.
 */
final class OwnerRoleTest extends WebTestCase
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

    /** Signs in a fresh user, optionally with an explicit user_role grant (Owner included). */
    private function signInWithRole(string $objectId, ?string $role): string
    {
        $userId = self::createUser($this->db, $objectId, 'User '.substr($objectId, 0, 4));
        if (null !== $role) {
            $this->db->executeStatement('INSERT INTO user_role (user_id, role) VALUES (?, ?)', [$userId, $role]);
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
    private function sendJson(string $method, string $uri, array $body, string $csrf): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body));
    }

    private function body(): mixed
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    public function testOwnerHoldsEveryPermission(): void
    {
        $this->signInWithRole('a0000000-0000-4000-8000-000000000001', 'OWNER');

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $me = $this->body();
        self::assertContains('OWNER', $me['roles']);
        $permissions = $me['permissions'];
        sort($permissions);
        $all = Permission::ALL;
        sort($all);
        self::assertSame($all, $permissions, 'god mode grants every permission');
    }

    public function testASystemAdministratorCanCreateAnOwner(): void
    {
        $this->signInWithRole('a0000000-0000-4000-8000-000000000002', 'SYSTEM_ADMINISTRATOR');
        $this->sendJson('POST', '/api/admin/users', ['username' => 'newowner', 'displayName' => 'New Owner', 'roles' => ['OWNER']], $this->csrf());

        self::assertResponseStatusCodeSame(201);
        $created = $this->body();
        self::assertTrue($created['ok']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM user_role WHERE user_id = ? AND role = ?', [$created['id'], 'OWNER']));
    }

    public function testASystemAdministratorCanGrantThemselvesTheOwnerRole(): void
    {
        $adminId = $this->signInWithRole('a0000000-0000-4000-8000-000000000007', 'SYSTEM_ADMINISTRATOR');
        $this->sendJson('PUT', '/api/admin/users/'.$adminId.'/roles', ['roles' => ['SYSTEM_ADMINISTRATOR', 'OWNER']], $this->csrf());

        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM user_role WHERE user_id = ? AND role = ?', [$adminId, 'OWNER']));
    }

    public function testAnOwnerCanCreateAndGrantAnotherOwner(): void
    {
        $this->signInWithRole('a0000000-0000-4000-8000-000000000003', 'OWNER');
        $csrf = $this->csrf();

        $this->sendJson('POST', '/api/admin/users', ['username' => 'secondowner', 'displayName' => 'Second Owner', 'roles' => ['OWNER']], $csrf);
        self::assertResponseStatusCodeSame(201);
        $created = $this->body();
        self::assertTrue($created['ok']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM user_role WHERE user_id = ? AND role = ?', [$created['id'], 'OWNER']));
    }

    public function testASystemAdministratorCanRevokeAnOwner(): void
    {
        $ownerId = self::createUser($this->db, 'a0000000-0000-4000-8000-000000000004', 'Existing Owner');
        $this->db->executeStatement('INSERT INTO user_role (user_id, role) VALUES (?, ?)', [$ownerId, 'OWNER']);
        // The acting System Administrator remains an administrator, so the last-admin guard holds.
        $this->signInWithRole('a0000000-0000-4000-8000-000000000005', 'SYSTEM_ADMINISTRATOR');

        $this->sendJson('PUT', '/api/admin/users/'.$ownerId.'/roles', ['roles' => []], $this->csrf());

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM user_role WHERE user_id = ? AND role = ?', [$ownerId, 'OWNER']));
    }

    public function testTheOwnerRoleCannotBeMappedToADirectoryGroup(): void
    {
        $this->signInWithRole('a0000000-0000-4000-8000-000000000006', 'SYSTEM_ADMINISTRATOR');

        $this->sendJson('POST', '/api/admin/role-mappings', ['role' => 'OWNER', 'groupObjectId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], $this->csrf());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('role', $this->body()['fields']);
    }

    public function testAnEntraBootstrapOwnerObjectIdReceivesGodMode(): void
    {
        // PAL_BOOTSTRAP_OWNER_OIDS in phpunit.dist.xml designates this object ID as an Owner.
        $this->signInWithRole('88888888-8888-4888-8888-888888888888', null);

        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        $me = $this->body();
        self::assertContains('OWNER', $me['roles'], 'the bootstrap owner object ID is an Owner');
        self::assertContains(Permission::SYSTEM_CONFIG, $me['permissions']);
        self::assertContains(Permission::PUBLICATION_RELEASE, $me['permissions']);
    }
}
