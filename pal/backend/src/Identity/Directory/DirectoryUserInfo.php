<?php

declare(strict_types=1);

namespace App\Identity\Directory;

final class DirectoryUserInfo
{
    public function __construct(
        public readonly string $objectId,
        public readonly string $displayName,
        public readonly ?string $email,
        public readonly ?string $userPrincipalName,
        /** null when the directory did not return the attribute */
        public readonly ?bool $accountEnabled,
    ) {
    }
}
