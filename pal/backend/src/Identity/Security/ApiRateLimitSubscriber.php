<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\User\PalUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Per-user (or, before sign-in, per-client-IP) request budget for the API. Correct
 * client IPs depend on TRUSTED_PROXIES; otherwise every user shares the proxy's budget.
 */
final class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        #[Autowire(service: 'limiter.api')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8) so the user is known.
        return [KernelEvents::REQUEST => ['onRequest', 2]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        $key = $user instanceof PalUser ? 'user:'.$user->id : 'ip:'.($request->getClientIp() ?? 'unknown');
        $limit = $this->limiter->create($key)->consume();
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
