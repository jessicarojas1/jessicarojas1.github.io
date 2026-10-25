<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

/**
 * Sign-in protocol failure. The code is safe to log and to map to a user-facing message;
 * provider error descriptions are never shown to users.
 */
final class OidcException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct('' === $detail ? $reasonCode : $reasonCode.': '.$detail, 0, $previous);
    }
}
