<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Accepts an upstream X-Request-Id when it is well-formed (so a reverse proxy can
 * propagate its ID), otherwise generates one, and echoes it on the response.
 */
final class RequestIdSubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-Request-Id';

    public function __construct(private readonly RequestContext $context)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 512],
            KernelEvents::RESPONSE => ['onResponse', -512],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $incoming = $event->getRequest()->headers->get(self::HEADER);
        if (\is_string($incoming) && 1 === preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming)) {
            $this->context->useCorrelationId($incoming);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set(self::HEADER, $this->context->correlationId());
        }
    }
}
