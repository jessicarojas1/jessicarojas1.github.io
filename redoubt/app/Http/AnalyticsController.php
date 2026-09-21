<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Analytics;
use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;

/** Program Analytics / Health. Requires audit.view. */
final class AnalyticsController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Analytics require the database.');
            return;
        }
        $programs = self::programs($user, 'audit.view');
        if ($programs === []) {
            self::plain(403, '403 Forbidden — analytics require audit.view.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'audit.view', ['program_id' => $programId]);
        $data = Analytics::forProgram($programId);
        Audit::log('analytics.view', 'program#' . $programId, $programId);
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_analytics.php';
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

    private static function plain(int $s, string $m): void
    {
        http_response_code($s);
        header('Content-Type: text/plain; charset=utf-8');
        echo $m;
    }
}
