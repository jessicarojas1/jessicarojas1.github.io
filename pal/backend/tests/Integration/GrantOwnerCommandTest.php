<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Local\LocalAccountService;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The out-of-band operator command that designates (or removes) a local Owner — the path to
 * the first Owner on an instance that was bootstrapped before the role existed.
 */
final class GrantOwnerCommandTest extends KernelTestCase
{
    use DatabaseReset;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
    }

    private function invoke(string $username, bool $revoke = false): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('pal:users:grant-owner'));
        $tester->execute($revoke ? ['username' => $username, '--revoke' => true] : ['username' => $username]);

        return $tester;
    }

    /** @return list<mixed> */
    private function roles(string $userId): array
    {
        return $this->db->fetchFirstColumn('SELECT role FROM user_role WHERE user_id = ?', [$userId]);
    }

    public function testGrantingAndRevokingTheOwnerRoleIsIdempotentAndAudited(): void
    {
        $accounts = self::getContainer()->get(LocalAccountService::class);
        // A second administrator must remain so revoking this account's Owner is allowed.
        $accounts->create(AuditActor::system('test'), null, 'other', 'Other Admin', null, [GlobalRole::SystemAdministrator]);
        $created = $accounts->create(AuditActor::system('test'), null, 'root', 'Root', null, []);

        self::assertSame(0, $this->invoke('root')->getStatusCode());
        self::assertContains('OWNER', $this->roles($created['id']));
        // Granting again is a no-op (idempotent), not an error.
        self::assertSame(0, $this->invoke('root')->getStatusCode());
        self::assertSame(1, (int) $this->db->fetchOne("SELECT count(*) FROM user_role WHERE user_id = ? AND role = 'OWNER'", [$created['id']]));

        self::assertSame(0, $this->invoke('root', true)->getStatusCode());
        self::assertNotContains('OWNER', $this->roles($created['id']));
        self::assertSame(
            2,
            (int) $this->db->fetchOne("SELECT count(*) FROM audit_event WHERE event_type = 'USER_ROLES_CHANGED' AND subject_id = ?", [$created['id']]),
            'the grant and the revoke were each audited; the no-op grant recorded nothing',
        );
    }

    public function testUnknownUsernameFails(): void
    {
        self::assertSame(1, $this->invoke('nobody')->getStatusCode());
    }

    public function testRevokingTheOnlyAdministratorIsRefused(): void
    {
        $accounts = self::getContainer()->get(LocalAccountService::class);
        $created = $accounts->create(AuditActor::system('test'), null, 'solo', 'Solo', null, []);
        self::assertSame(0, $this->invoke('solo')->getStatusCode());

        $tester = $this->invoke('solo', true);

        self::assertSame(1, $tester->getStatusCode(), 'cannot revoke the last administrator');
        self::assertContains('OWNER', $this->roles($created['id']), 'the revoke was rolled back');
    }
}
