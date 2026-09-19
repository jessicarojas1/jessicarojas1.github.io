<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Search;
use Redoubt\Support\Security;

/**
 * Global permission-aware search (§13). Results are trimmed by each module's own
 * authorization — a user can never discover what they cannot access.
 */
final class SearchController
{
    /** GET /app/search?q=&program_id= */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Search requires the database (DATABASE_URL). See docs/DEPLOYMENT.md.');
            return;
        }
        $programs = self::programs($user);
        if ($programs === []) {
            self::plain(403, '403 Forbidden — you have no program access.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        $q = (string) ($_GET['q'] ?? '');
        $results = $q !== '' ? Search::run($user, $programId, $q) : [];
        if ($q !== '') {
            Audit::log('search', 'q=' . mb_substr($q, 0, 80), $programId, $user['id'] ?? null);
        }
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_search.php';
    }

    /** @return array<int,string> — any program the user belongs to. */
    private static function programs(array $user): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
            $out[$pid] = $row['name'] ?? ('Program #' . $pid);
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
