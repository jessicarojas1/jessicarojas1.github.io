<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Security;

/**
 * Authenticated application shell. Phase 1 skeleton renders a role-aware home
 * that proves the pipeline (OIDC sign-in → session → membership/grant load →
 * server-side authorization). Portal modules mount here as they are built.
 */
final class AppController
{
    public static function home(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Audit::log('app.view', 'home');

        // Compute what this user may do in each program (server-side truth).
        $capabilities = [];
        foreach (array_keys($user['memberships'] ?? []) as $programId) {
            $capabilities[$programId] = Authorize::effectivePermissions($user, (int) $programId);
        }

        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_home.php';
    }
}
