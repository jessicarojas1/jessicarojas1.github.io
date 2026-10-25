<?php

declare(strict_types=1);

namespace App\Audit;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Appends the verified chain head to an external file. Its value depends on where that
 * directory lives: it should be write-once storage the database administrators cannot alter.
 */
final class AuditAnchorWriter
{
    public function __construct(
        #[Autowire(env: 'PAL_AUDIT_ANCHOR_DIR')]
        private readonly string $anchorDirectory,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim($this->anchorDirectory);
    }

    public function write(AuditChainReport $report): string
    {
        if (!$this->isConfigured() || !is_dir($this->anchorDirectory) || !is_writable($this->anchorDirectory)) {
            throw new \RuntimeException('PAL_AUDIT_ANCHOR_DIR is not set to a writable directory.');
        }
        $path = rtrim($this->anchorDirectory, '/').'/audit-anchors.jsonl';
        $line = json_encode([
            'anchored_at' => AuditCanonicalizer::formatTimestamp($this->clock->now()),
            'last_event_id' => $report->lastEventId,
            'head_hash' => $report->headHash,
            'events' => $report->eventsChecked,
        ], \JSON_THROW_ON_ERROR)."\n";
        if (false === file_put_contents($path, $line, \FILE_APPEND | \LOCK_EX)) {
            throw new \RuntimeException('Could not write the audit anchor.');
        }

        return $path;
    }
}
