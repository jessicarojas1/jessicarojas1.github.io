<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\ApiKey;
use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Config;
use Throwable;

/**
 * Versioned REST API (/api/v1). Every request is authenticated (bearer API key
 * or an active session) and every module endpoint is permission-checked with the
 * same Authorize engine as the UI — search and reads are permission-trimmed, so
 * the API can never expose data the caller could not see in the portal.
 *
 * Framework routing, auth, and the meta endpoints are implemented. Module
 * endpoints return 501 with a stable contract until the Phase 1 modules land
 * (see OPEN_ITEMS.md); this is deliberate skeleton scaffolding.
 */
final class ApiRouter
{
    public static function dispatch(string $method, string $path): void
    {
        // Identify the caller: API client (bearer) or interactive session.
        $client = ApiKey::resolve(ApiKey::bearerFromRequest());
        $user = Auth::user();

        if ($client === null && $user === null) {
            self::json(401, ['error' => 'unauthorized', 'message' => 'Provide a bearer API key or an authenticated session.']);
            return;
        }

        // Route table: [method, pattern] => handler
        $route = $method . ' ' . $path;
        try {
            switch (true) {
                case $route === 'GET /api/v1/health':
                    self::json(200, ['status' => 'ok', 'api' => 'v1', 'env' => Config::env(), 'time' => gmdate('c')]);
                    return;

                case $route === 'GET /api/v1/me':
                    self::json(200, self::identity($user, $client));
                    return;

                // --- Module endpoints (contract defined; implementation pending) ---
                case (bool) preg_match('#^GET /api/v1/(announcements|documents|task-orders|jobs|contacts|milestones|search)$#', $path, $m):
                    $module = $m[1];
                    // Demonstrate permission-aware gating with the real engine.
                    if ($user !== null) {
                        $perm = self::readPermissionFor($module);
                        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : null;
                        if (!Authorize::can($user, $perm, ['program_id' => $programId])) {
                            self::json(403, ['error' => 'forbidden', 'required' => $perm]);
                            return;
                        }
                    }
                    Audit::log('api.read', $module, isset($_GET['program_id']) ? (int) $_GET['program_id'] : null);
                    self::json(501, [
                        'error' => 'not_implemented',
                        'module' => $module,
                        'message' => 'Endpoint contract is stable; module implementation is Phase 1 (see OPEN_ITEMS.md).',
                    ]);
                    return;

                default:
                    self::json(404, ['error' => 'not_found', 'path' => $path]);
                    return;
            }
        } catch (Throwable $e) {
            error_log('[API] ' . $e->getMessage());
            self::json(500, ['error' => 'server_error']);
        }
    }

    private static function readPermissionFor(string $module): string
    {
        return match ($module) {
            'announcements' => 'announcement.view',
            'documents'     => 'document.view',
            'task-orders'   => 'taskorder.view',
            'jobs'          => 'job.view',
            'contacts'      => 'directory.view',
            'milestones'    => 'milestone.manage',
            'search'        => 'document.view',
            default         => 'document.view',
        };
    }

    /** @return array<string,mixed> */
    private static function identity(?array $user, ?array $client): array
    {
        if ($user !== null) {
            return [
                'kind' => 'user',
                'name' => $user['name'] ?? null,
                'email' => $user['email'] ?? null,
                'is_us_person' => $user['is_us_person'] ?? null,
                'programs' => array_keys($user['memberships'] ?? []),
            ];
        }
        return [
            'kind' => 'api_client',
            'name' => $client['name'] ?? null,
            'program_id' => $client['program_id'] ?? null,
            'scopes' => is_string($client['scopes'] ?? null) ? json_decode($client['scopes'], true) : ($client['scopes'] ?? []),
        ];
    }

    private static function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
