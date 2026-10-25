<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Who performed an audited action. The snapshot preserves the actor's identity as it
 * was at the time, so later renames or departures never rewrite history.
 */
final class AuditActor
{
    /**
     * @param array<string, string> $snapshot
     */
    private function __construct(
        public readonly ?string $userId,
        public readonly array $snapshot,
    ) {
    }

    public static function user(string $userId, string $displayName, string $idpObjectId): self
    {
        return new self($userId, [
            'type' => 'user',
            'id' => $userId,
            'display_name' => $displayName,
            'idp_object_id' => $idpObjectId,
        ]);
    }

    public static function system(string $name): self
    {
        return new self(null, ['type' => 'system', 'name' => $name]);
    }
}
