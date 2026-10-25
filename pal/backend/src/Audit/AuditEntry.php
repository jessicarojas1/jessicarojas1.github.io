<?php

declare(strict_types=1);

namespace App\Audit;

final class AuditEntry
{
    /**
     * @param array<string, mixed>|null $changes field-level before/after or event details; strings, ints, bools, nulls and arrays only
     */
    public function __construct(
        public readonly string $eventType,
        public readonly AuditActor $actor,
        public readonly ?string $subjectType = null,
        public readonly ?string $subjectId = null,
        public readonly ?array $changes = null,
        public readonly ?string $reason = null,
        public readonly ?string $fromState = null,
        public readonly ?string $toState = null,
    ) {
    }
}
