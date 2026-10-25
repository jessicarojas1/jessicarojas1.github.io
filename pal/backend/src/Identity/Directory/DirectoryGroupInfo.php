<?php

declare(strict_types=1);

namespace App\Identity\Directory;

final class DirectoryGroupInfo
{
    public function __construct(
        public readonly string $objectId,
        public readonly string $displayName,
    ) {
    }
}
