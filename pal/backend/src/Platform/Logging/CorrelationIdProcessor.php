<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use App\Platform\Http\RequestContext;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

#[AsMonologProcessor]
final class CorrelationIdProcessor implements ProcessorInterface
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: [...$record->extra, 'correlation_id' => $this->context->correlationId()]);
    }
}
