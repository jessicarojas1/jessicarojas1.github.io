<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\Oidc\AuthorizationRequest;
use App\Identity\Oidc\OidcClient;
use App\Identity\Oidc\OidcException;
use App\Identity\Oidc\PendingAuthorizations;
use App\Identity\Security\ReturnToValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController
{
    public function __construct(
        private readonly OidcClient $client,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.auth_login')]
        private readonly RateLimiterFactory $loginLimiter,
    ) {
    }

    #[Route('/auth/login', name: 'auth_login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        if (!$this->loginLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new RedirectResponse('/?auth_error=too_many_attempts');
        }
        $pending = AuthorizationRequest::start(
            ReturnToValidator::sanitize($request->query->get('returnTo')),
            $this->clock->now()->getTimestamp(),
        );
        try {
            $url = $this->client->authorizationUrl($pending);
        } catch (OidcException $e) {
            $this->logger->error('Sign-in is unavailable', ['reason' => $e->reasonCode, 'exception' => $e]);

            return new RedirectResponse('/?auth_error=sign_in_unavailable');
        }
        PendingAuthorizations::put($request->getSession(), $pending, $this->clock->now()->getTimestamp());

        return new RedirectResponse($url);
    }

    /**
     * Handled by OidcCallbackAuthenticator. The route must exist so routing does not 404
     * before the firewall runs; reaching this method means the authenticator did not act.
     */
    #[Route('/auth/callback', name: 'auth_callback', methods: ['GET'])]
    public function callback(): Response
    {
        return new RedirectResponse('/?auth_error=sign_in_failed');
    }

    /**
     * Handled by the firewall's logout listener (see LogoutSubscriber).
     */
    #[Route('/api/session/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Logout is handled by the security firewall.');
    }
}
