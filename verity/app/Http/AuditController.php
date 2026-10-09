<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\Db;
use Verity\Support\Security;

final class AuditController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'audit.view');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 50;
        $action = trim((string) ($_GET['action'] ?? ''));
        $where = '1=1';
        $params = [];
        if ($action !== '') {
            $where = 'a.action ILIKE :action';
            $params['action'] = '%' . $action . '%';
        }
        $total = (int) Db::fetchValue("SELECT COUNT(*) FROM audit_event a WHERE $where", $params);
        $params['limit'] = $pageSize;
        $params['offset'] = ($page - 1) * $pageSize;
        $events = Db::fetchAll(
            "SELECT a.*, u.display_name AS actor_name FROM audit_event a
             LEFT JOIN app_user u ON u.id = a.actor_id
             WHERE $where ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset",
            $params
        );

        $title = 'Audit History';
        $navActive = 'audit';
        $breadcrumbs = ['Audit' => null];
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_audit.php';
    }
}
