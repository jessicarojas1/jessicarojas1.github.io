<?php

declare(strict_types=1);

namespace App\Identity\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Unauthenticated API calls get 401 JSON, never a redirect; the SPA decides when to
 * send the browser to /auth/login.
 */
final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Sign in to continue.', 'loginUrl' => '/auth/login'], 401);
    }
}
