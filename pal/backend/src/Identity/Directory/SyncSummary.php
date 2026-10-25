<?php

declare(strict_types=1);

namespace App\Identity\Directory;

final class SyncSummary
{
    /** @var array<string, array{added: int, removed: int, members: int}> */
    public array $synced = [];

    /** @var array<string, string> */
    public array $failed = [];

    public function hasFailures(): bool
    {
        return [] !== $this->failed;
    }
}
