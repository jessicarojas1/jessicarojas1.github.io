<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Matrix;
use Verity\Support\Security;

/**
 * Minimal versioned REST surface. Reuses the exact same Authorize engine as
 * the HTML routes — never a parallel permission check. This is intentionally
 * small in this build pass (one read endpoint); see docs/API_SPECIFICATION.md
 * for what exists and OPEN_ITEMS.md for what doesn't yet.
 */
final class ApiRouter
{
    public static function dispatch(string $method, string $path): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($method === 'GET' && $path === '/api/v1/matrix') {
            self::matrix();
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }

    private static function matrix(): void
    {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $user = Auth::user();
        $view = (string) ($_GET['view'] ?? 'enterprise');
        $scope = ['view' => $view];

        if ($view === 'supervisor') {
            if (!Authorize::can($user, 'matrix.view.supervisor')) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            $selfId = $user['person_id'] ?? null;
            $ids = $selfId !== null ? Authorize::reportsOf((int) $selfId) : [];
            if ($selfId !== null) {
                $ids[] = (int) $selfId;
            }
            $scope['person_ids'] = $ids;
        } else {
            $permission = match ($view) {
                'privileged' => 'matrix.view.privileged',
                'exception' => 'matrix.view.exception',
                default => 'matrix.view.enterprise',
            };
            if (!Authorize::can($user, $permission)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
        }

        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $result = Matrix::query($scope, $limit, $offset);
        echo Security::jsonForScript($result);
    }
}
