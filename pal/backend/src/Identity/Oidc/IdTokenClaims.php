<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

final class IdTokenClaims
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $objectId,
        public readonly string $displayName,
        public readonly ?string $email,
        public readonly ?string $userPrincipalName,
        public readonly int $issuedAt,
    ) {
    }
}
