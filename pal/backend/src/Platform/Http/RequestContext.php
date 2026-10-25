<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Symfony\Component\Uid\Uuid;

/**
 * Holds the correlation ID of the current request (or console process) so logs,
 * audit events and responses can be tied together.
 */
final class RequestContext
{
    private ?string $correlationId = null;

    public function correlationId(): string
    {
        return $this->correlationId ??= Uuid::v4()->toRfc4122();
    }

    public function useCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }
}
