<?php

declare(strict_types=1);

namespace App\Identity\Message;

use App\Audit\AuditActor;
use App\Identity\Directory\DirectoryException;
use App\Identity\Directory\DirectorySynchronizer;
use App\Identity\Directory\GroupRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncDirectoryGroupHandler
{
    public function __construct(
        private readonly DirectorySynchronizer $synchronizer,
        private readonly GroupRepository $groups,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncDirectoryGroup $message): void
    {
        if (!$this->synchronizer->isConfigured()) {
            $this->logger->info('Role-mapping group sync skipped: directory is not configured', ['group_id' => $message->groupId]);

            return;
        }
        try {
            $result = $this->synchronizer->syncGroup($message->groupId, $message->groupObjectId, AuditActor::system('role-mapping'));
            $this->logger->info('Role-mapping group sync finished', [
                'group_id' => $message->groupId,
                'added' => $result['added'],
                'removed' => $result['removed'],
                'members' => $result['members'],
            ]);
        } catch (DirectoryException $e) {
            // Mirror syncAll: record the failure on the group (the admin UI surfaces sync_error)
            // and let the next scheduled sync retry, rather than failing the message so Messenger
            // re-queues it blindly. A transient Graph hiccup should not fill the failed transport.
            $this->groups->markSyncFailed($message->groupId, $e->getMessage());
            $this->logger->error('Role-mapping group sync failed', ['group_id' => $message->groupId, 'exception' => $e]);
        }
    }
}
