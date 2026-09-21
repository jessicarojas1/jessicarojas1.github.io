<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\AuditLog;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;

/** Compliance Audit Log viewer. Requires audit.view. */
final class AuditController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Audit log requires the database.');
            return;
        }
        $programs = self::programs($user);
        if ($programs === []) {
            self::plain(403, '403 Forbidden — audit log requires audit.view.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'audit.view', ['program_id' => $programId]);

        $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $rows = AuditLog::forProgram($programId, $action ?: null, $offset);
        $total = AuditLog::count($programId, $action ?: null);
        $actions = AuditLog::actions($programId);
        $page = AuditLog::PAGE;
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_audit.php';
    }

    /** @return array<int,string> */
    private static function programs(array $user): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (Authorize::can($user, 'audit.view', ['program_id' => $pid])) {
                $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                $out[$pid] = $row['name'] ?? ('Program #' . $pid);
            }
        }
        return $out;
    }

    private static function plain(int $s, string $m): void
    {
        http_response_code($s);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m;
    }
}
