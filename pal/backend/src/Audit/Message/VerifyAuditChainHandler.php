<?php

declare(strict_types=1);

namespace App\Audit\Message;

use App\Audit\AuditActor;
use App\Audit\AuditAnchorWriter;
use App\Audit\AuditChainVerifier;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class VerifyAuditChainHandler
{
    public function __construct(
        private readonly AuditChainVerifier $verifier,
        private readonly AuditAnchorWriter $anchors,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(VerifyAuditChain $message): void
    {
        $report = $this->verifier->verify();
        if (!$report->valid) {
            // Not recorded as an audit event on purpose: appending to a broken chain would bury the break.
            $this->logger->critical('Audit chain verification FAILED', [
                'first_broken_event_id' => $report->firstBrokenEventId,
                'failure' => $report->failure,
            ]);

            return;
        }
        if ($message->anchor && $this->anchors->isConfigured()) {
            $this->anchors->write($report);
        }
        $this->audit->record(new AuditEntry(
            'AUDIT_CHAIN_VERIFIED',
            AuditActor::system('scheduler'),
            changes: ['events' => $report->eventsChecked, 'last_event_id' => $report->lastEventId, 'head_hash' => $report->headHash],
        ));
    }
}
