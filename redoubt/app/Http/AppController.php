<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Dashboard;
use Redoubt\Support\Db;

/**
 * Authenticated home — an executive, role-aware program dashboard that ties every
 * module together: KPI tiles, "My Actions", recent announcements, and upcoming
 * milestones, all permission-trimmed to what the signed-in user may see.
 */
final class AppController
{
    public static function home(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Audit::log('app.view', 'home');

        $programs = [];
        if (Db::isConfigured()) {
            foreach (array_keys($user['memberships'] ?? []) as $pid) {
                $pid = (int) $pid;
                $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                $programs[$pid] = $row['name'] ?? ('Program #' . $pid);
            }
        }

        $programId = 0;
        $data = null;
        if ($programs !== []) {
            $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
            if (!isset($programs[$programId])) {
                $programId = (int) array_key_first($programs);
            }
            $data = Dashboard::forUser($user, $programId);
        }

        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_home.php';
    }
}
