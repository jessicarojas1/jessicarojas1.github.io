<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\User\PalUser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A user signed in with a temporary password can only change it (or sign out) until they
 * do. Every other API call is refused.
 */
final class PasswordChangeRequiredSubscriber implements EventSubscriberInterface
{
    private const ALLOWED = ['/api/me', '/api/me/password', '/api/session/logout', '/api/branding'];

    public function __construct(private readonly TokenStorageInterface $tokens)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 3]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!$event->isMainRequest() || !str_starts_with($path, '/api') || \in_array($path, self::ALLOWED, true)) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if ($user instanceof PalUser && $user->mustChangePassword) {
            $event->setResponse(new JsonResponse(['error' => 'password_change_required', 'message' => 'Choose a new password to continue.'], 403));
        }
    }
}
