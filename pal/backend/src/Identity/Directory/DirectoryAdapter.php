<?php

declare(strict_types=1);

namespace App\Identity\Directory;

/**
 * Read-only access to the organisation's directory. Entra ID via Microsoft Graph is the
 * selected implementation (D18); the interface keeps authorization code independent of it.
 */
interface DirectoryAdapter
{
    public function isConfigured(): bool;

    public function tenantId(): string;

    /**
     * @throws DirectoryException
     */
    public function fetchGroup(string $groupObjectId): DirectoryGroupInfo;

    /**
     * All users in the group, including through nested groups.
     *
     * @return list<DirectoryUserInfo>
     *
     * @throws DirectoryException
     */
    public function fetchGroupUserMembers(string $groupObjectId): array;

    /**
     * Object IDs of every group the user belongs to, including through nesting.
     *
     * @return list<string>
     *
     * @throws DirectoryException
     */
    public function fetchUserGroupIds(string $userObjectId): array;
}
