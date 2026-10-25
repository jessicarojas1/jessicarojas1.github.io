<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Authorization\GlobalRole;
use App\Identity\Authorization\Permission;
use App\Identity\Authorization\PermissionVoter;
use App\Identity\Authorization\RolePermissionMap;
use App\Identity\User\PalUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The full role × permission matrix, written out so any change to who can do what is
 * a deliberate, reviewed edit to this table.
 */
final class PermissionMatrixTest extends TestCase
{
    private const EXPECTED = [
        'READER' => [Permission::LIBRARY_READ],
        'AUTHOR' => [Permission::DOCUMENT_CREATE],
        'PUBLISHER' => [Permission::PUBLICATION_RELEASE, Permission::PUBLICATION_SCHEDULE],
        'PAL_ADMINISTRATOR' => [
            Permission::ADMIN_NUMBERING, Permission::ADMIN_RETENTION, Permission::ADMIN_TYPES, Permission::AUDIT_EXPORT,
            Permission::AUDIT_READ, Permission::DOCUMENT_ARCHIVE, Permission::DOCUMENT_CHANGE_OWNER, Permission::DOCUMENT_CREATE,
            Permission::DOCUMENT_LEGAL_HOLD, Permission::DOCUMENT_OBSOLETE, Permission::PUBLICATION_EMERGENCY,
            Permission::REPORT_EXPORT, Permission::WORKFLOW_CANCEL, Permission::WORKFLOW_REASSIGN,
        ],
        'SYSTEM_ADMINISTRATOR' => [
            Permission::ADMIN_DIRECTORY, Permission::ADMIN_ROLES, Permission::ADMIN_WORKFLOWS, Permission::AUDIT_READ, Permission::SYSTEM_CONFIG,
        ],
    ];

    public function testEveryRoleGrantsExactlyTheExpectedPermissions(): void
    {
        foreach (GlobalRole::cases() as $role) {
            $expected = self::EXPECTED[$role->value];
            sort($expected);
            self::assertSame($expected, RolePermissionMap::permissionsFor([$role]), $role->value);
        }
    }

    public function testVoterMatchesTheMatrixForEveryRoleAndPermission(): void
    {
        $voter = new PermissionVoter();
        foreach (GlobalRole::cases() as $role) {
            $token = new UsernamePasswordToken($this->user([$role]), 'main', []);
            foreach (Permission::ALL as $permission) {
                $expected = \in_array($permission, self::EXPECTED[$role->value], true) ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED;
                self::assertSame($expected, $voter->vote($token, null, [$permission]), "{$role->value} / {$permission}");
            }
        }
    }

    public function testSystemAdministratorHasNoDocumentOrPublicationAuthority(): void
    {
        $permissions = RolePermissionMap::permissionsFor([GlobalRole::SystemAdministrator]);
        foreach ($permissions as $permission) {
            self::assertFalse(str_starts_with($permission, 'document.') || str_starts_with($permission, 'publication.') || str_starts_with($permission, 'workflow.'), $permission);
        }
    }

    public function testAnonymousTokensAreDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new PermissionVoter())->vote(new NullToken(), null, [Permission::LIBRARY_READ]));
    }

    public function testUnknownAttributesAreAbstained(): void
    {
        $token = new UsernamePasswordToken($this->user([GlobalRole::SystemAdministrator]), 'main', []);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new PermissionVoter())->vote($token, null, ['document.delete_everything']));
    }

    /**
     * @param list<GlobalRole> $roles
     */
    private function user(array $roles): PalUser
    {
        return new PalUser('5b0c6d3e-7c55-4e8e-9b3d-1f2a3b4c5d6e', 'tid', 'oid', 'Test', null, null, $roles);
    }
}
