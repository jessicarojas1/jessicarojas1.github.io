<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Oidc\OidcException;
use App\Identity\Oidc\OidcProviderMetadata;
use App\Identity\Oidc\OidcSettings;
use App\Identity\User\PalUser;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Audits sign-out and tells the SPA where to send the browser to end the Entra ID
 * session as well (single sign-out), when the provider publishes that endpoint.
 */
final class LogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OidcProviderMetadata $metadata,
        private readonly OidcSettings $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onLogout'];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof PalUser) {
            $this->audit->record(new AuditEntry('USER_SIGNED_OUT', AuditActor::user($user->id, $user->displayName, $user->objectId), 'app_user', $user->id));
        }

        $endSessionUrl = null;
        try {
            $endpoint = $this->metadata->endSessionEndpoint();
            if (null !== $endpoint) {
                $endSessionUrl = $endpoint.'?'.http_build_query(['post_logout_redirect_uri' => $this->settings->postLogoutRedirectUri()], '', '&', \PHP_QUERY_RFC3986);
            }
        } catch (OidcException) {
            // Local sign-out still succeeds when the provider is unreachable or not configured.
        }

        $event->setResponse(new JsonResponse(['ok' => true, 'endSessionUrl' => $endSessionUrl]));
    }
}
