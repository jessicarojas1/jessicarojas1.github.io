<?php

declare(strict_types=1);

namespace App\Identity\Controller;

use App\Identity\Oidc\OidcSettings;
use App\Identity\Security\LocalPasswordAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * What the sign-in page needs before anyone is signed in: a login CSRF token and which
 * sign-in methods exist. Only booleans and display labels are exposed, never tenant IDs or
 * other configuration.
 */
final class SignInMethodsController
{
    /** Shown on the sign-in page so the roadmap is visible; not yet implemented. */
    private const PLANNED_PROVIDERS = [
        ['id' => 'okta', 'label' => 'Okta'],
        ['id' => 'google', 'label' => 'Google Workspace'],
        ['id' => 'saml', 'label' => 'SAML 2.0 identity provider'],
    ];

    public function __construct(
        private readonly OidcSettings $oidc,
        private readonly CsrfTokenManagerInterface $csrfTokens,
    ) {
    }

    #[Route('/auth/methods', name: 'auth_methods', methods: ['GET'])]
    public function methods(Request $request): JsonResponse
    {
        $request->getSession()->start();

        return new JsonResponse([
            'csrfToken' => $this->csrfTokens->getToken(LocalPasswordAuthenticator::CSRF_ID)->getValue(),
            'methods' => [
                ['id' => 'password', 'label' => 'Username and password', 'connected' => true],
                ['id' => 'entra', 'label' => 'Microsoft Entra ID', 'connected' => $this->oidc->isConfigured()],
                ...array_map(fn (array $p): array => [...$p, 'connected' => false], self::PLANNED_PROVIDERS),
            ],
        ]);
    }

    /**
     * Handled by LocalPasswordAuthenticator; this route exists so routing does not 404 first.
     */
    #[Route('/auth/password', name: 'auth_password', methods: ['POST'])]
    public function password(): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_credentials', 'message' => 'The username or password is incorrect.'], 401);
    }
}
