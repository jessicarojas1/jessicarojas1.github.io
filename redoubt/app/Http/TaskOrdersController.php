<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Security;
use Redoubt\Support\TaskOrders;

/**
 * Task Orders module (Annex H). Program- and company-scoped; every action is
 * permission-checked; approve emits a signed webhook + audit (in the service).
 */
final class TaskOrdersController
{
    /** GET /app/task-orders */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Task Orders require the database (DATABASE_URL). See docs/DEPLOYMENT.md.');
            return;
        }
        $programs = self::programs($user, 'taskorder.view');
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no program where you may view task orders.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'taskorder.view', ['program_id' => $programId]);

        $items = TaskOrders::listForUser($user, $programId);
        $can = [
            'create'  => Authorize::can($user, 'taskorder.create', ['program_id' => $programId]),
            'edit'    => Authorize::can($user, 'taskorder.edit', ['program_id' => $programId]),
            'approve' => Authorize::can($user, 'taskorder.approve', ['program_id' => $programId]),
        ];
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_taskorders.php';
    }

    /** POST /app/task-orders */
    public static function post(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::plain(419, 'CSRF validation failed.');
            return;
        }
        $programId = (int) ($_POST['program_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $actorId = $user['id'] ?? null;

        switch ($action) {
            case 'create':
                Authorize::requirePermission($user, 'taskorder.create', ['program_id' => $programId]);
                TaskOrders::create($programId, self::formData(), $actorId);
                break;
            case 'update':
                Authorize::requirePermission($user, 'taskorder.edit', ['program_id' => $programId]);
                TaskOrders::update($id, $programId, self::formData(), $actorId);
                break;
            case 'approve':
                Authorize::requirePermission($user, 'taskorder.approve', ['program_id' => $programId]);
                TaskOrders::approve($id, $programId, $actorId);
                break;
            case 'delete':
                Authorize::requirePermission($user, 'taskorder.edit', ['program_id' => $programId]);
                TaskOrders::delete($id, $programId, $actorId);
                break;
            default:
                self::plain(400, 'Unknown action.');
                return;
        }
        header('Location: /app/task-orders?program_id=' . $programId);
    }

    /** @return array<string,mixed> */
    private static function formData(): array
    {
        $scope = [];
        foreach (explode(',', (string) ($_POST['company_scope'] ?? '')) as $tok) {
            $tok = trim($tok);
            if ($tok !== '') {
                $scope[] = ctype_digit($tok) ? (int) $tok : $tok;
            }
        }
        return [
            'number'        => trim((string) ($_POST['number'] ?? '')),
            'title'         => trim((string) ($_POST['title'] ?? '')),
            'status'        => (string) ($_POST['status'] ?? 'draft'),
            'company_scope' => $scope,
            'sp_link'       => trim((string) ($_POST['sp_link'] ?? '')) ?: null,
        ];
    }

    /** @return array<int,string> */
    private static function programs(array $user, string $perm): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (Authorize::can($user, $perm, ['program_id' => $pid])) {
                $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                $out[$pid] = $row['name'] ?? ('Program #' . $pid);
            }
        }
        return $out;
    }

    private static function plain(int $status, string $msg): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
}
