<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Local\LocalAccountService;
use App\Tests\Support\DatabaseReset;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BootstrapPasswordTest extends KernelTestCase
{
    use DatabaseReset;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        self::resetTables($this->db);
    }

    public function testSettingTheBootstrapPasswordReplacesTheHashKeepsItTemporaryAndAuditsIt(): void
    {
        $accounts = self::getContainer()->get(LocalAccountService::class);
        $created = $accounts->create(AuditActor::system('test'), null, 'root', 'Root', null, [GlobalRole::SystemAdministrator]);
        $before = (string) $this->db->fetchOne('SELECT password_hash FROM local_credential WHERE user_id = ?', [$created['id']]);

        $accounts->setBootstrapPassword(AuditActor::system('bootstrap'), $created['id'], 'a strong bootstrap passphrase');

        $row = $this->db->fetchAssociative('SELECT password_hash, must_change FROM local_credential WHERE user_id = ?', [$created['id']]);
        self::assertIsArray($row);
        self::assertNotSame($before, $row['password_hash'], 'the temporary hash is replaced');
        self::assertTrue(password_verify('a strong bootstrap passphrase', (string) $row['password_hash']));
        self::assertTrue(true === $row['must_change'], 'the bootstrap password stays temporary');
        self::assertSame(
            1,
            (int) $this->db->fetchOne("SELECT count(*) FROM audit_event WHERE event_type = 'USER_PASSWORD_CHANGED' AND subject_id = ?", [$created['id']]),
            'the bootstrap password set is audited',
        );
    }
}
