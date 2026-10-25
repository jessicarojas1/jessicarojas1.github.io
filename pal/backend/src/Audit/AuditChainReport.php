<?php

declare(strict_types=1);

namespace App\Audit;

final class AuditChainReport
{
    public function __construct(
        public readonly bool $valid,
        public readonly int $eventsChecked,
        public readonly ?int $lastEventId,
        public readonly string $headHash,
        public readonly ?int $firstBrokenEventId = null,
        public readonly ?string $failure = null,
    ) {
    }
}
