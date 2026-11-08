<?php

declare(strict_types=1);

namespace App\Identity\Message;

/**
 * Sync one tracked directory group's membership off the request thread.
 *
 * Dispatched after a role mapping is added so a large group's initial membership pull
 * (Graph fetch + per-member upsert) cannot time out the HTTP request (OPEN_ITEMS I8).
 * Delivery is at-least-once; the handler is idempotent because {@see \App\Identity\Directory\DirectorySynchronizer::syncGroup()}
 * reconciles against current membership rather than assuming an empty starting state.
 */
final class SyncDirectoryGroup
{
    public function __construct(
        public readonly string $groupId,
        public readonly string $groupObjectId,
    ) {
    }
}
