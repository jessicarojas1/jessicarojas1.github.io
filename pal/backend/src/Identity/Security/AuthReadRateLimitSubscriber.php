<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Per-client-IP budget for the unauthenticated sign-in reads that each start a session
 * (`GET /auth/methods`, `GET /auth/login`). Without it a cookieless client can loop these to
 * flood the session store. The OIDC callback has its own limiter and the password POST is
 * limited by the authenticator, so they are not covered here.
 *
 * Correct client IPs depend on TRUSTED_PROXIES; otherwise requests share the proxy's budget.
 */
final class AuthReadRateLimitSubscriber implements EventSubscriberInterface
{
    private const PATHS = ['/auth/methods', '/auth/login'];

    public function __construct(
        #[Autowire(service: 'limiter.auth_read')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Before the firewall so a flood is rejected before a session is created.
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || !\in_array($request->getPathInfo(), self::PATHS, true)) {
            return;
        }
        $limit = $this->limiter->create('ip:'.($request->getClientIp() ?? 'unknown'))->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $event->setResponse(new JsonResponse(
                ['error' => 'too_many_requests', 'message' => 'Too many requests. Try again shortly.'],
                429,
                ['Retry-After' => (string) $retryAfter],
            ));
        }
    }
}
