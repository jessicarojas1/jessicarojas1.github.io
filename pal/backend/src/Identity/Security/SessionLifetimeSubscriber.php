<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\User\PalUser;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Absolute session lifetime: however active the user is, they must sign in again after
 * PAL_SESSION_ABSOLUTE_SECONDS. (Idle expiry is the session's gc_maxlifetime.)
 */
final class SessionLifetimeSubscriber implements EventSubscriberInterface
{
    public const AUTH_TIME_KEY = 'pal.auth_time';

    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:PAL_SESSION_ABSOLUTE_SECONDS')]
        private readonly int $absoluteLifetime,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8) has restored the token.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api') || !$request->hasSession()) {
            return;
        }
        if (!$this->tokens->getToken()?->getUser() instanceof PalUser) {
            return;
        }
        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        $authTime = $session->get(self::AUTH_TIME_KEY);
        if (!\is_int($authTime)) {
            $session->set(self::AUTH_TIME_KEY, $now);

            return;
        }
        if ($now - $authTime > $this->absoluteLifetime) {
            $session->invalidate();
            $this->tokens->setToken(null);
            $event->setResponse(new JsonResponse(['error' => 'session_expired', 'message' => 'Your session has ended. Sign in again.', 'loginUrl' => '/auth/login'], 401));
        }
    }
}
