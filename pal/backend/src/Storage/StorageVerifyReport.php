<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Result of verifying referenced blobs against the store: how many were checked, and which
 * are missing (not in the store) or corrupt (present but no longer matching their SHA-256,
 * or unreadable). Used by pal:storage:verify and disaster-recovery consistency checks.
 */
final class StorageVerifyReport
{
    /**
     * @param list<string> $missing SHA-256 identifiers the store does not hold
     * @param list<string> $corrupt SHA-256 identifiers whose bytes no longer match, or could not be read
     */
    public function __construct(
        public readonly int $checked,
        public readonly array $missing,
        public readonly array $corrupt,
    ) {
    }

    public function okCount(): int
    {
        return $this->checked - \count($this->missing) - \count($this->corrupt);
    }

    public function isClean(): bool
    {
        return [] === $this->missing && [] === $this->corrupt;
    }
}
