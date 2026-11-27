<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Security\PalUserProvider;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Decision #31: granting Owner, removing it from someone else, and self-grants of System
 * Administrator or document authority need a second Owner or System Administrator — unless
 * nobody else could approve, in which case the change is applied and flagged.
 */
final class TwoPersonRuleTest extends WebTestCase
{
    use DatabaseReset;

    private KernelBrowser $client;
    private Connection $db;
    /** @var array<string, string> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
        $n = 0;
        foreach (['alice' => 'SYSTEM_ADMINISTRATOR', 'bob' => 'SYSTEM_ADMINISTRATOR', 'carol' => null, 'pam' => 'PAL_ADMINISTRATOR'] as $name => $role) {
            $this->users[$name] = self::createUser($this->db, 'd0000000-0000-4000-8000-00000000000'.++$n, ucfirst($name));
            if (null !== $role) {
                $this->db->executeStatement('INSERT INTO user_role (user_id, role) VALUES (?, ?)', [$this->users[$name], $role]);
            }
        }
    }

    private function as(string $who): string
    {
        $this->client->loginUser(self::getContainer()->get(PalUserProvider::class)->loadUserByIdentifier($this->users[$who]));
        $this->client->request('GET', '/api/me');

        return (string) $this->json()['csrfToken'];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $csrf, string $method, string $uri, array $body = []): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body));

        return $this->json();
    }

    /**
     * @return list<string>
     */
    private function rolesOf(string $who): array
    {
        $roles = array_map('strval', $this->db->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ? ORDER BY role', [$this->users[$who]]));

        return $roles;
    }

    public function testMakingYourselfOwnerWaitsForASecondAdministrator(): void
    {
        $result = $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['alice'].'/roles', ['roles' => ['OWNER', 'SYSTEM_ADMINISTRATOR'], 'reason' => 'Need god mode for migration']);
        self::assertResponseStatusCodeSame(202);
        self::assertTrue($result['pending']);
        self::assertSame(['SYSTEM_ADMINISTRATOR'], $this->rolesOf('alice'), 'Nothing changes until approved.');
        $requestId = (string) $result['requestId'];

        // Alice cannot approve her own request, and a PAL Administrator cannot decide at all.
        $this->call($this->as('alice'), 'POST', '/api/admin/role-requests/'.$requestId.'/approve');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('second_person_required', $this->json()['error']);
        $this->call($this->as('pam'), 'POST', '/api/admin/role-requests/'.$requestId.'/approve');
        self::assertResponseStatusCodeSame(403);

        // Bob sees it and approves it.
        $this->as('bob');
        $this->client->request('GET', '/api/admin/role-requests');
        $listed = $this->json()['requests'];
        self::assertCount(1, $listed);
        self::assertTrue($listed[0]['canDecide']);
        self::assertSame(['OWNER', 'SYSTEM_ADMINISTRATOR'], $listed[0]['to']);
        self::assertSame('Need god mode for migration', $listed[0]['reason']);
        $this->call($this->as('bob'), 'POST', '/api/admin/role-requests/'.$requestId.'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame(['OWNER', 'SYSTEM_ADMINISTRATOR'], $this->rolesOf('alice'));
        self::assertSame($this->users['bob'], $this->db->fetchOne("SELECT granted_by FROM user_role WHERE user_id = ? AND role = 'OWNER'", [$this->users['alice']]));
        self::assertSame(
            ['ROLE_CHANGE_REQUESTED', 'USER_ROLES_CHANGED', 'ROLE_CHANGE_APPROVED'],
            array_map('strval', $this->db->fetchFirstColumn("SELECT event_type FROM audit_event WHERE event_type LIKE 'ROLE_CHANGE_%' OR event_type = 'USER_ROLES_CHANGED' ORDER BY id")),
        );
        // A decided request cannot be decided again.
        $this->call($this->as('bob'), 'POST', '/api/admin/role-requests/'.$requestId.'/approve');
        self::assertResponseStatusCodeSame(409);
    }

    public function testGivingYourselfDocumentAuthorityIsHeldButGrantingOthersIsNot(): void
    {
        $csrf = $this->as('alice');
        $this->call($csrf, 'PUT', '/api/admin/users/'.$this->users['alice'].'/roles', ['roles' => ['PUBLISHER', 'SYSTEM_ADMINISTRATOR']]);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(['SYSTEM_ADMINISTRATOR'], $this->rolesOf('alice'));

        // A System Administrator still assigns document authority to other people directly.
        $this->call($csrf, 'PUT', '/api/admin/users/'.$this->users['carol'].'/roles', ['roles' => ['AUTHOR']]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['AUTHOR'], $this->rolesOf('carol'));
    }

    public function testTheTargetCannotApproveTheirOwnPromotion(): void
    {
        // A third administrator exists, so promoting Bob needs someone other than Alice and Bob.
        $dave = self::createUser($this->db, 'd0000000-0000-4000-8000-000000000009', 'Dave');
        $this->db->executeStatement("INSERT INTO user_role (user_id, role) VALUES (?, 'SYSTEM_ADMINISTRATOR')", [$dave]);
        $requestId = (string) $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['bob'].'/roles', ['roles' => ['OWNER', 'SYSTEM_ADMINISTRATOR']])['requestId'];
        self::assertResponseStatusCodeSame(202);

        $this->call($this->as('bob'), 'POST', '/api/admin/role-requests/'.$requestId.'/approve');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['SYSTEM_ADMINISTRATOR'], $this->rolesOf('bob'));
    }

    public function testRejectionCancellationOneOpenRequestAndStaleness(): void
    {
        $csrf = $this->as('alice');
        $first = (string) $this->call($csrf, 'PUT', '/api/admin/users/'.$this->users['carol'].'/roles', ['roles' => ['OWNER']])['requestId'];
        $this->call($csrf, 'PUT', '/api/admin/users/'.$this->users['carol'].'/roles', ['roles' => ['OWNER', 'AUTHOR']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('request_pending', $this->json()['error']);

        // Bob rejects with a note.
        $this->call($this->as('bob'), 'POST', '/api/admin/role-requests/'.$first.'/reject', ['note' => 'Not needed']);
        self::assertResponseIsSuccessful();
        self::assertSame('REJECTED', $this->db->fetchOne('SELECT status FROM role_change_request WHERE id = ?', [$first]));

        // Alice asks again, then cancels her own request.
        $second = (string) $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['carol'].'/roles', ['roles' => ['OWNER']])['requestId'];
        $this->call($this->as('alice'), 'POST', '/api/admin/role-requests/'.$second.'/reject');
        self::assertResponseIsSuccessful();
        self::assertSame('CANCELLED', $this->db->fetchOne('SELECT status FROM role_change_request WHERE id = ?', [$second]));

        // A request is refused when the person's roles changed after it was made.
        $third = (string) $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['carol'].'/roles', ['roles' => ['OWNER']])['requestId'];
        $this->db->executeStatement("INSERT INTO user_role (user_id, role) VALUES (?, 'AUTHOR')", [$this->users['carol']]);
        $this->call($this->as('bob'), 'POST', '/api/admin/role-requests/'.$third.'/approve');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('request_stale', $this->json()['error']);

        // An expired request is closed and cannot be approved.
        $this->db->executeStatement("UPDATE role_change_request SET expires_at = now() - interval '1 minute' WHERE id = ?", [$third]);
        $this->call($this->as('bob'), 'POST', '/api/admin/role-requests/'.$third.'/approve');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('request_closed', $this->json()['error']);
    }

    public function testANewOwnerAccountWaitsAndMustSetItsOwnPasswordFirst(): void
    {
        $created = $this->call($this->as('alice'), 'POST', '/api/admin/users', ['username' => 'newowner', 'displayName' => 'New Owner', 'roles' => ['OWNER']]);
        self::assertResponseStatusCodeSame(201);
        self::assertArrayHasKey('pendingRoleRequest', $created);
        self::assertSame([], array_map('strval', $this->db->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ?', [$created['id']])));

        // Alice saw the temporary password, so the account cannot become an Owner yet.
        $this->call($this->as('bob'), 'POST', '/api/admin/role-requests/'.$created['pendingRoleRequest'].'/approve');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('password_not_set', $this->json()['error']);

        $this->db->executeStatement('UPDATE local_credential SET must_change = false WHERE user_id = ?', [$created['id']]);
        $this->call($this->as('bob'), 'POST', '/api/admin/role-requests/'.$created['pendingRoleRequest'].'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame(['OWNER'], array_map('strval', $this->db->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ?', [$created['id']])));
    }

    public function testASoleAdministratorProceedsButTheChangeIsFlagged(): void
    {
        // Only Alice remains as an administrator.
        $this->db->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?', [$this->users['bob']]);
        $result = $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['alice'].'/roles', ['roles' => ['OWNER', 'SYSTEM_ADMINISTRATOR']]);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($result['soleAdministrator']);
        self::assertSame(['OWNER', 'SYSTEM_ADMINISTRATOR'], $this->rolesOf('alice'));

        $this->client->request('GET', '/api/admin/role-requests');
        $flagged = $this->json()['soleAdministratorChanges'];
        self::assertCount(1, $flagged);
        self::assertSame('Alice', $flagged[0]['user']);
    }

    public function testASockPuppetAdministratorCannotApprove(): void
    {
        // Alice creates a System Administrator account she controls (she sees its password).
        $created = $this->call($this->as('alice'), 'POST', '/api/admin/users', ['username' => 'puppet', 'displayName' => 'Puppet', 'roles' => ['SYSTEM_ADMINISTRATOR']]);
        self::assertResponseStatusCodeSame(201);
        $this->users['puppet'] = (string) $created['id'];
        $this->db->executeStatement('UPDATE local_credential SET must_change = false WHERE user_id = ?', [$created['id']]);

        $requestId = (string) $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['alice'].'/roles', ['roles' => ['OWNER', 'SYSTEM_ADMINISTRATOR']])['requestId'];
        self::assertResponseStatusCodeSame(202);
        $this->as('puppet');
        $this->client->request('GET', '/api/admin/role-requests');
        self::assertFalse($this->json()['requests'][0]['canDecide']);
        $this->call($this->as('puppet'), 'POST', '/api/admin/role-requests/'.$requestId.'/approve');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('approver_not_independent', $this->json()['error']);
        self::assertSame(['SYSTEM_ADMINISTRATOR'], $this->rolesOf('alice'));
    }

    public function testResettingAnotherAdministratorsPasswordDisqualifiesThemAsApprover(): void
    {
        // Bob becomes a local account so his password can be reset.
        $this->db->executeStatement("UPDATE app_user SET idp_tenant_id = NULL, idp_object_id = NULL, username = 'bob', source = 'LOCAL' WHERE id = ?", [$this->users['bob']]);
        $this->db->executeStatement("INSERT INTO local_credential (user_id, password_hash, must_change) VALUES (?, 'x', false)", [$this->users['bob']]);
        $this->call($this->as('alice'), 'POST', '/api/admin/users/'.$this->users['bob'].'/reset-password');
        self::assertResponseIsSuccessful();

        // Bob was the only other administrator and Alice controls his password now, so there is
        // no independent approver; and Alice has not removed anyone, so the change applies but
        // is flagged rather than being approved "by Bob".
        $result = $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['alice'].'/roles', ['roles' => ['OWNER', 'SYSTEM_ADMINISTRATOR']]);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($result['soleAdministrator']);
    }

    public function testClearingTheFieldDoesNotUnlockTheSoleAdministratorException(): void
    {
        $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['bob'].'/active', ['isActive' => false]);
        self::assertResponseIsSuccessful();
        $this->call($this->as('alice'), 'PUT', '/api/admin/users/'.$this->users['alice'].'/roles', ['roles' => ['OWNER', 'SYSTEM_ADMINISTRATOR']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('second_person_required', $this->json()['error']);
        self::assertSame(['SYSTEM_ADMINISTRATOR'], $this->rolesOf('alice'));
    }

    public function testOnlyAnOwnerCanResetAnOwnersPassword(): void
    {
        $this->db->executeStatement("UPDATE app_user SET idp_tenant_id = NULL, idp_object_id = NULL, username = 'carol', source = 'LOCAL' WHERE id = ?", [$this->users['carol']]);
        $this->db->executeStatement("INSERT INTO local_credential (user_id, password_hash, must_change) VALUES (?, 'x', false)", [$this->users['carol']]);
        $this->db->executeStatement("INSERT INTO user_role (user_id, role) VALUES (?, 'OWNER')", [$this->users['carol']]);

        $this->call($this->as('alice'), 'POST', '/api/admin/users/'.$this->users['carol'].'/reset-password');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('owner_reset_forbidden', $this->json()['error']);
    }
}
