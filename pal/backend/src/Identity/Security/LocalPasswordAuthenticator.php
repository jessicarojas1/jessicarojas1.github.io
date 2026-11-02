<?php

declare(strict_types=1);

namespace App\Identity\Security;

use App\Identity\Local\LocalAccountService;
use App\Platform\Http\ApiProblem;
use App\Platform\Http\JsonBody;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Username/password sign-in for local accounts (POST /auth/password, JSON). Login CSRF
 * is required (token from GET /auth/methods). Throttled per client IP and per username,
 * with account lockout in LocalAccountService. Every failure gets the same answer, so
 * the response never reveals whether an account exists.
 */
final class LocalPasswordAuthenticator extends AbstractAuthenticator
{
    public const PATH = '/auth/password';
    public const CSRF_ID = 'pal_login';

    public function __construct(
        private readonly LocalAccountService $accounts,
        private readonly PalUserProvider $users,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.auth_login')]
        private readonly RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.login_username')]
        private readonly RateLimiterFactory $usernameLimiter,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::PATH === $request->getPathInfo() && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $body = JsonBody::decode($request, 4096);
        } catch (ApiProblem) {
            throw new CustomUserMessageAuthenticationException('invalid_request');
        }
        $username = \is_string($body['username'] ?? null) ? LocalAccountService::normalizeUsername($body['username']) : '';
        $password = \is_string($body['password'] ?? null) ? $body['password'] : '';
        if (!$this->ipLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()
            || ('' !== $username && !$this->usernameLimiter->create($username)->consume()->isAccepted())) {
            throw new CustomUserMessageAuthenticationException('too_many_attempts');
        }
        if ('' === $username || '' === $password || \strlen($password) > 1024) {
            throw new CustomUserMessageAuthenticationException('invalid_credentials');
        }
        // Checked before verify(): a correct password records a successful sign-in, which must
        // not happen for a request that is then refused for its CSRF token.
        $csrfToken = (string) $request->headers->get(CsrfHeaderSubscriber::HEADER, '');
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $csrfToken))) {
            throw new CustomUserMessageAuthenticationException('invalid_credentials');
        }
        $userId = $this->accounts->verify($username, $password);
        if (null === $userId) {
            throw new CustomUserMessageAuthenticationException('invalid_credentials');
        }

        return new SelfValidatingPassport(
            new UserBadge($userId, $this->users->loadUserByIdentifier(...)),
            [new CsrfTokenBadge(self::CSRF_ID, $csrfToken)],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $request->getSession()->set(SessionLifetimeSubscriber::AUTH_TIME_KEY, $this->clock->now()->getTimestamp());
        $user = $token->getUser();

        return new JsonResponse([
            'ok' => true,
            'mustChangePassword' => $user instanceof \App\Identity\User\PalUser && $user->mustChangePassword,
        ]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return match ($exception->getMessageKey()) {
            'too_many_attempts' => new JsonResponse(['error' => 'too_many_attempts', 'message' => 'Too many sign-in attempts. Wait a few minutes and try again.'], 429),
            'invalid_request' => new JsonResponse(['error' => 'invalid_request', 'message' => 'The sign-in request was malformed.'], 400),
            // Also covers an invalid CSRF token, so the page can simply reload its token.
            default => new JsonResponse(['error' => 'invalid_credentials', 'message' => 'The username or password is incorrect, or the account is temporarily locked.'], 401),
        };
    }
}
