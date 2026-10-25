<?php

declare(strict_types=1);

namespace App\Identity\Message;

use App\Audit\AuditActor;
use App\Identity\Directory\DirectorySynchronizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncDirectoryHandler
{
    public function __construct(
        private readonly DirectorySynchronizer $synchronizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncDirectory $message): void
    {
        if (!$this->synchronizer->isConfigured()) {
            $this->logger->info('Scheduled directory sync skipped: directory is not configured');

            return;
        }
        $summary = $this->synchronizer->syncAll(AuditActor::system('scheduler'));
        $this->logger->info('Scheduled directory sync finished', ['synced' => \count($summary->synced), 'failed' => \count($summary->failed)]);
    }
}
