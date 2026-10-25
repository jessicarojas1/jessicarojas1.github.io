<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Every state-changing API request must carry the session's CSRF token in X-CSRF-Token
 * (defence in depth on top of SameSite=Lax cookies). Runs before the firewall so the
 * logout endpoint is covered too, and so logout only works via POST.
 */
final class CsrfHeaderSubscriber implements EventSubscriberInterface
{
    public const TOKEN_ID = 'pal_api';
    public const HEADER = 'X-CSRF-Token';
    public const LOGOUT_PATH = '/api/session/logout';

    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokens)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After the session listener (128) and router (32), before the firewall (8).
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }
        if (self::LOGOUT_PATH === $request->getPathInfo() && 'POST' !== $request->getMethod()) {
            $event->setResponse(new JsonResponse(['error' => 'method_not_allowed', 'message' => 'Use POST to sign out.'], 405, ['Allow' => 'POST']));

            return;
        }
        if (\in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $token = $request->headers->get(self::HEADER);
        if (!\is_string($token) || '' === $token || !$this->csrfTokens->isTokenValid(new CsrfToken(self::TOKEN_ID, $token))) {
            $event->setResponse(new JsonResponse(['error' => 'csrf_invalid', 'message' => 'The request could not be verified. Reload the page and try again.'], 403));
        }
    }
}
