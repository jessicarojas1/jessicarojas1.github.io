<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditActor;
use App\Identity\Authorization\GlobalRole;
use App\Identity\Authorization\RoleResolver;
use App\Identity\Directory\DirectorySynchronizer;
use App\Tests\Support\DatabaseReset;
use App\Tests\Support\FakeEntraId;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DirectorySyncTest extends KernelTestCase
{
    use DatabaseReset;

    private const GROUP = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const ADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const BEA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private Connection $db;
    private DirectorySynchronizer $sync;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->sync = self::getContainer()->get(DirectorySynchronizer::class);
        self::resetTables($this->db);
        FakeEntraId::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private static function member(string $id, string $name, ?bool $enabled = true): array
    {
        return ['id' => $id, 'displayName' => $name, 'mail' => strtolower($name).'@example.test', 'userPrincipalName' => strtolower($name).'@example.test', 'accountEnabled' => $enabled];
    }

    public function testMembershipChangesAreMirroredWithHistoryAndAudit(): void
    {
        FakeEntraId::$groups[self::GROUP] = ['displayName' => 'PAL Approvers', 'members' => [self::member(self::ADA, 'Ada'), self::member(self::BEA, 'Bea')]];
        $actor = AuditActor::system('test');
        $group = $this->sync->trackGroup(self::GROUP, $actor);

        $first = $this->sync->syncGroup($group['id'], self::GROUP, $actor);
        self::assertSame(['added' => 2, 'removed' => 0, 'members' => 2], $first);

        FakeEntraId::$groups[self::GROUP]['members'] = [self::member(self::ADA, 'Ada')];
        $second = $this->sync->syncGroup($group['id'], self::GROUP, $actor);
        self::assertSame(['added' => 0, 'removed' => 1, 'members' => 1], $second);

        $rows = $this->db->fetchAllAssociative('SELECT u.idp_object_id, gm.valid_to FROM group_membership gm JOIN app_user u ON u.id = gm.user_id ORDER BY u.idp_object_id');
        self::assertNull($rows[0]['valid_to'], 'Ada stays a member');
        self::assertNotNull($rows[1]['valid_to'], 'Bea\'s membership is closed, not deleted');
        self::assertSame(['DIRECTORY_GROUP_TRACKED', 'DIRECTORY_GROUP_SYNCED', 'DIRECTORY_GROUP_SYNCED'], $this->db->fetchFirstColumn('SELECT event_type FROM audit_event ORDER BY id'));

        $unchanged = $this->sync->syncGroup($group['id'], self::GROUP, $actor);
        self::assertSame(0, $unchanged['added'] + $unchanged['removed']);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT count(*) FROM audit_event'), 'No-op syncs add no audit noise');
    }

    public function testAccountsDisabledInTheDirectoryAreDeactivated(): void
    {
        FakeEntraId::$groups[self::GROUP] = ['displayName' => 'All Staff', 'members' => [self::member(self::ADA, 'Ada')]];
        $actor = AuditActor::system('test');
        $group = $this->sync->trackGroup(self::GROUP, $actor);
        $this->sync->syncGroup($group['id'], self::GROUP, $actor);

        FakeEntraId::$groups[self::GROUP]['members'] = [self::member(self::ADA, 'Ada', false)];
        $this->sync->syncGroup($group['id'], self::GROUP, $actor);

        self::assertFalse((bool) $this->db->fetchOne('SELECT is_active FROM app_user'));
        self::assertContains('USER_DEACTIVATED', $this->db->fetchFirstColumn('SELECT event_type FROM audit_event'));
    }

    public function testMappedGroupMembersReceiveTheRole(): void
    {
        FakeEntraId::$groups[self::GROUP] = ['displayName' => 'PAL Authors', 'members' => [self::member(self::ADA, 'Ada')]];
        $actor = AuditActor::system('test');
        $group = $this->sync->trackGroup(self::GROUP, $actor);
        $this->db->executeStatement("INSERT INTO global_role_mapping (role, group_id) VALUES ('AUTHOR', ?)", [$group['id']]);
        $this->sync->syncGroup($group['id'], self::GROUP, $actor);

        $ada = $this->db->fetchAssociative('SELECT id, idp_object_id FROM app_user');
        $roles = self::getContainer()->get(RoleResolver::class)->rolesFor((string) $ada['id'], (string) $ada['idp_object_id']);

        self::assertSame([GlobalRole::Reader, GlobalRole::Author], $roles);
    }

    public function testFailedGroupsAreRecordedAndOthersStillSync(): void
    {
        $actor = AuditActor::system('test');
        FakeEntraId::$groups[self::GROUP] = ['displayName' => 'Good', 'members' => [self::member(self::ADA, 'Ada')]];
        $this->sync->trackGroup(self::GROUP, $actor);
        $this->db->executeStatement("INSERT INTO directory_group (idp_tenant_id, idp_object_id, display_name) VALUES (?, 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'Deleted group')", [FakeEntraId::TENANT]);

        $summary = $this->sync->syncAll($actor);

        self::assertCount(1, $summary->synced);
        self::assertCount(1, $summary->failed);
        self::assertNotNull($this->db->fetchOne("SELECT sync_error FROM directory_group WHERE display_name = 'Deleted group'"));
    }
}
