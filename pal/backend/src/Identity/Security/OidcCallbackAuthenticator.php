<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Audit\AuditActor;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Identity\Oidc\IdTokenValidator;
use App\Identity\Oidc\OidcClient;
use App\Identity\Oidc\OidcException;
use App\Identity\Oidc\PendingAuthorizations;
use App\Identity\User\SignInService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Completes the Entra ID sign-in at /auth/callback: one-time state check, code exchange
 * with PKCE, ID-token validation, user provisioning. On success Symfony migrates the
 * session ID (fixation protection) and PAL redirects to the validated return path.
 */
final class OidcCallbackAuthenticator extends AbstractAuthenticator
{
    public const CALLBACK_PATH = '/auth/callback';
    private const RETURN_TO_ATTRIBUTE = '_pal_return_to';
    private const HAD_PENDING_STATE_ATTRIBUTE = '_pal_had_pending_state';
    private const USER_FACING_ERRORS = ['sign_in_cancelled', 'account_disabled', 'sign_in_expired', 'sign_in_failed', 'sign_in_unavailable', 'too_many_attempts'];

    public function __construct(
        private readonly OidcClient $client,
        private readonly IdTokenValidator $validator,
        private readonly SignInService $signIn,
        private readonly PalUserProvider $userProvider,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.auth_login')]
        private readonly RateLimiterFactory $loginLimiter,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::CALLBACK_PATH === $request->getPathInfo() && $request->isMethod('GET');
    }

    public function authenticate(Request $request): Passport
    {
        // Checked before anything else (including audit writes), so the public callback
        // cannot be used to flood the append-only audit trail.
        if (!$this->loginLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new CustomUserMessageAuthenticationException('too_many_attempts');
        }
        if ($request->query->has('error')) {
            $this->logger->notice('Identity provider returned an error to the callback', [
                'error' => preg_replace('/[^a-z_]/', '', (string) $request->query->get('error')),
            ]);
            throw new CustomUserMessageAuthenticationException('sign_in_cancelled');
        }
        $state = $request->query->get('state');
        $code = $request->query->get('code');
        if (!\is_string($state) || !\is_string($code) || '' === $code || \strlen($code) > 4096) {
            throw new CustomUserMessageAuthenticationException('sign_in_failed');
        }
        $pending = PendingAuthorizations::take($request->getSession(), $state, $this->clock->now()->getTimestamp());
        if (null === $pending) {
            throw new CustomUserMessageAuthenticationException('sign_in_expired');
        }
        $request->attributes->set(self::HAD_PENDING_STATE_ATTRIBUTE, true);

        try {
            $idToken = $this->client->exchangeCode($code, $pending);
            $claims = $this->validator->validate($idToken, $pending->nonce);
            $userId = $this->signIn->signIn($claims);
        } catch (OidcException $e) {
            $this->logger->warning('Sign-in failed', ['reason' => $e->reasonCode, 'exception' => $e]);
            throw new CustomUserMessageAuthenticationException(match ($e->reasonCode) {
                'account_disabled' => 'account_disabled',
                'not_configured', 'provider_unavailable', 'provider_untrusted' => 'sign_in_unavailable',
                default => 'sign_in_failed',
            }, [], 0, $e);
        }

        $request->attributes->set(self::RETURN_TO_ATTRIBUTE, $pending->returnTo);

        return new SelfValidatingPassport(new UserBadge($userId, $this->userProvider->loadUserByIdentifier(...)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $request->getSession()->set(SessionLifetimeSubscriber::AUTH_TIME_KEY, $this->clock->now()->getTimestamp());

        return new RedirectResponse(ReturnToValidator::sanitize($request->attributes->get(self::RETURN_TO_ATTRIBUTE)));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $code = $exception->getMessageKey();
        if (!\in_array($code, self::USER_FACING_ERRORS, true)) {
            $code = 'sign_in_failed';
        }
        // Only attempts that started a real sign-in reach the audit trail. Unsolicited hits on
        // the public callback (no matching state) are logged but never written to the chain.
        if (true === $request->attributes->get(self::HAD_PENDING_STATE_ATTRIBUTE)) {
            $this->audit->record(new AuditEntry('USER_SIGN_IN_FAILED', AuditActor::system('sign_in'), reason: $code, changes: [
                'client_ip' => $request->getClientIp(),
            ]));
        } else {
            $this->logger->notice('Sign-in callback rejected without a pending sign-in', ['reason' => $code, 'client_ip' => $request->getClientIp()]);
        }

        return new RedirectResponse('/sign-in?auth_error='.$code);
    }
}
