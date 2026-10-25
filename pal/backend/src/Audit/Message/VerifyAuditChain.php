<?php

declare(strict_types=1);

namespace App\Audit\Message;

final class VerifyAuditChain
{
    public function __construct(public readonly bool $anchor = true)
    {
    }
}
