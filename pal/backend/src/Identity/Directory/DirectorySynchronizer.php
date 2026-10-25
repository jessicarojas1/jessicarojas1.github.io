<?php

declare(strict_types=1);

namespace App\Identity\Directory;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\User\UserRepository;
use App\Platform\Database\Row;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Mirrors membership of tracked directory groups into PAL, keeping history: removed
 * memberships are closed (valid_to), never deleted. Only groups PAL uses are tracked,
 * not the whole directory.
 */
final class DirectorySynchronizer
{
    public function __construct(
        private readonly DirectoryAdapter $directory,
        private readonly GroupRepository $groups,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->directory->isConfigured();
    }

    /**
     * @return array{id: string, display_name: string}
     */
    public function trackGroup(string $groupObjectId, AuditActor $actor): array
    {
        $info = $this->directory->fetchGroup($groupObjectId);

        return $this->connection->transactional(function () use ($info, $actor): array {
            $result = $this->groups->track($this->directory->tenantId(), $info);
            if ($result['newly_tracked']) {
                $this->audit->record(new AuditEntry('DIRECTORY_GROUP_TRACKED', $actor, 'directory_group', $result['id'], [
                    'idp_object_id' => $info->objectId,
                    'display_name' => $info->displayName,
                ]));
            }

            return ['id' => $result['id'], 'display_name' => $info->displayName];
        });
    }

    public function syncAll(AuditActor $actor): SyncSummary
    {
        $summary = new SyncSummary();
        foreach ($this->groups->trackedGroups() as $group) {
            $groupId = Row::string($group, 'id');
            try {
                $summary->synced[$groupId] = $this->syncGroup($groupId, Row::string($group, 'idp_object_id'), $actor);
            } catch (DirectoryException $e) {
                $this->groups->markSyncFailed($groupId, $e->getMessage());
                $this->logger->error('Directory group sync failed', ['group_id' => $groupId, 'exception' => $e]);
                $summary->failed[$groupId] = $e->getMessage();
            }
        }

        return $summary;
    }

    /**
     * @return array{added: int, removed: int, members: int}
     */
    public function syncGroup(string $groupId, string $groupObjectId, AuditActor $actor): array
    {
        // Fetch everything before opening the transaction: no network I/O while holding locks.
        $info = $this->directory->fetchGroup($groupObjectId);
        $members = $this->directory->fetchGroupUserMembers($groupObjectId);
        $now = $this->clock->now();

        return $this->connection->transactional(function () use ($groupId, $info, $members, $now, $actor): array {
            $current = array_fill_keys($this->groups->openMemberIds($groupId), true);
            $target = [];
            foreach ($members as $member) {
                $user = $this->users->upsertFromDirectory($this->directory->tenantId(), $member, $now);
                $target[$user['id']] = true;
                if (true === $user['was_active'] && !$user['is_active']) {
                    $this->audit->record(new AuditEntry('USER_DEACTIVATED', $actor, 'app_user', $user['id'], reason: 'Account disabled in the directory'));
                }
            }
            $added = array_keys(array_diff_key($target, $current));
            $removed = array_keys(array_diff_key($current, $target));
            foreach ($added as $userId) {
                $this->groups->openMembership($groupId, $userId, $now);
            }
            foreach ($removed as $userId) {
                $this->groups->closeMembership($groupId, $userId, $now);
            }
            $this->groups->markSynced($groupId, $info->displayName, $now);
            if ([] !== $added || [] !== $removed) {
                $this->audit->record(new AuditEntry('DIRECTORY_GROUP_SYNCED', $actor, 'directory_group', $groupId, [
                    'display_name' => $info->displayName,
                    'added_user_ids' => $added,
                    'removed_user_ids' => $removed,
                    'member_count' => \count($target),
                ]));
            }

            return ['added' => \count($added), 'removed' => \count($removed), 'members' => \count($target)];
        });
    }

    /**
     * Refreshes one user's memberships of tracked groups (used at sign-in).
     */
    public function refreshUser(string $userId, string $userObjectId, AuditActor $actor): void
    {
        $directoryGroupIds = $this->directory->fetchUserGroupIds($userObjectId);
        $now = $this->clock->now();

        $this->connection->transactional(function () use ($userId, $directoryGroupIds, $now, $actor): void {
            $tracked = $this->groups->trackedObjectIdMap($this->directory->tenantId());
            $target = [];
            foreach ($directoryGroupIds as $objectId) {
                if (isset($tracked[$objectId])) {
                    $target[$tracked[$objectId]] = true;
                }
            }
            $current = array_fill_keys($this->groups->openGroupIdsForUser($userId), true);
            $added = array_keys(array_diff_key($target, $current));
            $removed = array_keys(array_diff_key($current, $target));
            foreach ($added as $groupId) {
                $this->groups->openMembership($groupId, $userId, $now);
            }
            foreach ($removed as $groupId) {
                $this->groups->closeMembership($groupId, $userId, $now);
            }
            if ([] !== $added || [] !== $removed) {
                $this->audit->record(new AuditEntry('USER_MEMBERSHIPS_REFRESHED', $actor, 'app_user', $userId, [
                    'added_group_ids' => $added,
                    'removed_group_ids' => $removed,
                ]));
            }
        });
    }
}
