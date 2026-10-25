<?php

declare(strict_types=1);

namespace App\Identity\Authorization;

final class RolePermissionMap
{
    private const MAP = [
        'READER' => [Permission::LIBRARY_READ],
        'AUTHOR' => [Permission::DOCUMENT_CREATE],
        'PUBLISHER' => [Permission::PUBLICATION_SCHEDULE, Permission::PUBLICATION_RELEASE],
        'PAL_ADMINISTRATOR' => [
            Permission::DOCUMENT_CREATE, Permission::DOCUMENT_CHANGE_OWNER, Permission::DOCUMENT_OBSOLETE,
            Permission::DOCUMENT_ARCHIVE, Permission::DOCUMENT_LEGAL_HOLD, Permission::WORKFLOW_REASSIGN,
            Permission::WORKFLOW_CANCEL, Permission::PUBLICATION_EMERGENCY, Permission::ADMIN_TYPES,
            Permission::ADMIN_NUMBERING, Permission::ADMIN_RETENTION, Permission::AUDIT_READ,
            Permission::AUDIT_EXPORT, Permission::REPORT_EXPORT,
        ],
        // Configures the system; holds no authority over document content or releases.
        'SYSTEM_ADMINISTRATOR' => [
            Permission::SYSTEM_CONFIG, Permission::ADMIN_ROLES, Permission::ADMIN_DIRECTORY,
            Permission::ADMIN_WORKFLOWS, Permission::AUDIT_READ,
        ],
    ];

    /**
     * @param list<GlobalRole> $roles
     *
     * @return list<string>
     */
    public static function permissionsFor(array $roles): array
    {
        $permissions = [];
        foreach ($roles as $role) {
            foreach (self::MAP[$role->value] as $permission) {
                $permissions[$permission] = true;
            }
        }
        $result = array_keys($permissions);
        sort($result);

        return $result;
    }
}
