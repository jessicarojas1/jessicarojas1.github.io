<?php

declare(strict_types=1);

namespace App\Audit;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Flushes any audit events whose SIEM forward was buffered inside a business transaction. It
 * runs after the HTTP response is sent, after a console command finishes, and after each worker
 * message is handled — once the transaction has committed — so the SIEM copy is emitted only for
 * events that persisted.
 */
final class AuditForwardFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'flush',
            ConsoleEvents::TERMINATE => 'flush',
            WorkerMessageHandledEvent::class => 'flush',
        ];
    }

    public function flush(): void
    {
        $this->audit->flushPendingForward();
    }
}
