<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\RemediationTasks;
use Verity\Support\Security;

final class RemediationController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        $canManage = Authorize::can($user, 'remediation.manage');
        Authorize::requirePermission($user, $canManage ? 'remediation.manage' : 'remediation.view');

        $filters = ['status' => trim((string) ($_GET['status'] ?? 'open'))];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 50;
        $total = RemediationTasks::countAll($filters);
        $tasks = RemediationTasks::list($filters, $pageSize, ($page - 1) * $pageSize);

        $title = 'Remediation Tasks';
        $navActive = 'remediation';
        $breadcrumbs = ['Remediation Tasks' => null];
        $NONCE = $nonce;
        $csrf = Security::csrfField();
        require dirname(__DIR__) . '/Views/app_remediation.php';
    }

    public static function create(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'remediation.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $accountId = (int) ($_POST['system_account_id'] ?? 0);
        try {
            RemediationTasks::create([
                'system_account_id' => $accountId,
                'entitlement_id' => (int) ($_POST['entitlement_id'] ?? 0) ?: null,
                'task_type' => (string) ($_POST['task_type'] ?? ''),
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
            ], $user['id']);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/accounts/view?id=' . $accountId);
    }

    public static function resolve(): void
    {
        self::decide('resolve');
    }

    public static function dismiss(): void
    {
        self::decide('dismiss');
    }

    private static function decide(string $action): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'remediation.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $note = trim((string) ($_POST['note'] ?? '')) ?: null;
        try {
            if ($action === 'resolve') {
                RemediationTasks::resolve($id, $note, (int) $user['id']);
            } else {
                RemediationTasks::dismiss($id, $note, (int) $user['id']);
            }
        } catch (\RuntimeException $e) {
            http_response_code(400);
            echo Security::h($e->getMessage());
            return;
        }
        header('Location: /app/remediation');
    }
}
