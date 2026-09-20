<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Announcements;
use Redoubt\Support\ApiKey;
use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Config;
use Redoubt\Support\Db;
use Redoubt\Support\Directory;
use Redoubt\Support\Documents;
use Redoubt\Support\Jobs;
use Redoubt\Support\Milestones;
use Redoubt\Support\Search;
use Redoubt\Support\TaskOrders;
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

                // --- Implemented, permission-trimmed module endpoints ---
                case $route === 'GET /api/v1/announcements':
                    self::announcements($user, $client);
                    return;

                case $route === 'GET /api/v1/documents':
                    self::moduleList($user, $client, 'document.view',
                        static fn (array $u, int $p) => Documents::listForUser($u, $p),
                        static fn (int $p) => Documents::listForApiClient($p), 'documents');
                    return;

                case $route === 'GET /api/v1/task-orders':
                    self::moduleList($user, $client, 'taskorder.view',
                        static fn (array $u, int $p) => TaskOrders::listForUser($u, $p),
                        static fn (int $p) => TaskOrders::listForProgram($p), 'task-orders');
                    return;

                case $route === 'GET /api/v1/jobs':
                    self::moduleList($user, $client, 'job.view',
                        static fn (array $u, int $p) => Jobs::listForUser($u, $p),
                        static fn (int $p) => Jobs::listOpen($p), 'jobs');
                    return;

                case $route === 'GET /api/v1/directory':
                    self::moduleList($user, $client, 'directory.view',
                        static fn (array $u, int $p) => Directory::listForUser($u, $p),
                        static fn (int $p) => Directory::listForProgram($p), 'directory');
                    return;

                case $route === 'GET /api/v1/milestones':
                    self::moduleList($user, $client, 'milestone.view',
                        static fn (array $u, int $p) => Milestones::listForProgram($p),
                        static fn (int $p) => Milestones::listForProgram($p), 'milestones');
                    return;

                case $route === 'GET /api/v1/search':
                    self::searchApi($user);
                    return;

                // --- Other module endpoints (contract defined; implementation pending) ---
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
            'milestones'    => 'milestone.view',
            'search'        => 'document.view',
            default         => 'document.view',
        };
    }

    /** GET /api/v1/announcements?program_id= — permission-trimmed for the caller. */
    private static function announcements(?array $user, ?array $client): void
    {
        if (!Db::isConfigured()) {
            self::json(503, ['error' => 'database_not_configured']);
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
        if ($programId <= 0) {
            self::json(400, ['error' => 'program_id_required']);
            return;
        }

        if ($user !== null) {
            if (!Authorize::can($user, 'announcement.view', ['program_id' => $programId])) {
                self::json(403, ['error' => 'forbidden']);
                return;
            }
            $items = Announcements::listForUser($user, $programId);
        } else {
            // API client: must be scoped to this program and hold the scope.
            $clientProgram = $client['program_id'] !== null ? (int) $client['program_id'] : null;
            $scopes = is_string($client['scopes'] ?? null) ? (json_decode($client['scopes'], true) ?: []) : ($client['scopes'] ?? []);
            $scoped = in_array('*', $scopes, true) || in_array('announcement.view', $scopes, true);
            if (($clientProgram !== null && $clientProgram !== $programId) || !$scoped) {
                self::json(403, ['error' => 'forbidden']);
                return;
            }
            $items = Announcements::listPublished($programId);
        }
        Audit::log('api.read', 'announcements', $programId);
        self::json(200, ['program_id' => $programId, 'count' => count($items), 'announcements' => $items]);
    }

    /**
     * Generic permission-trimmed list endpoint for a module.
     * $userFn(user, programId) trims by the full Authorize engine (incl. export
     * gate); $clientFn(programId) is the API-client view (export-controlled excluded).
     */
    private static function moduleList(?array $user, ?array $client, string $perm, callable $userFn, callable $clientFn, string $label): void
    {
        if (!Db::isConfigured()) {
            self::json(503, ['error' => 'database_not_configured']);
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
        if ($programId <= 0) {
            self::json(400, ['error' => 'program_id_required']);
            return;
        }
        if ($user !== null) {
            if (!Authorize::can($user, $perm, ['program_id' => $programId])) {
                self::json(403, ['error' => 'forbidden']);
                return;
            }
            $items = $userFn($user, $programId);
        } else {
            $clientProgram = $client['program_id'] !== null ? (int) $client['program_id'] : null;
            $scopes = is_string($client['scopes'] ?? null) ? (json_decode($client['scopes'], true) ?: []) : ($client['scopes'] ?? []);
            $scoped = in_array('*', $scopes, true) || in_array($perm, $scopes, true);
            if (($clientProgram !== null && $clientProgram !== $programId) || !$scoped) {
                self::json(403, ['error' => 'forbidden']);
                return;
            }
            $items = $clientFn($programId);
        }
        Audit::log('api.read', $label, $programId);
        self::json(200, ['program_id' => $programId, 'count' => count($items), 'items' => $items]);
    }

    /** GET /api/v1/search?q=&program_id= — permission-trimmed; requires a user session. */
    private static function searchApi(?array $user): void
    {
        if (!Db::isConfigured()) {
            self::json(503, ['error' => 'database_not_configured']);
            return;
        }
        if ($user === null) {
            self::json(400, ['error' => 'search_requires_user_session']);
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
        if ($programId <= 0 || !isset($user['memberships'][$programId])) {
            self::json(403, ['error' => 'forbidden']);
            return;
        }
        $q = (string) ($_GET['q'] ?? '');
        $items = $q !== '' ? Search::run($user, $programId, $q) : [];
        Audit::log('api.search', 'q', $programId);
        self::json(200, ['program_id' => $programId, 'count' => count($items), 'results' => $items]);
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
