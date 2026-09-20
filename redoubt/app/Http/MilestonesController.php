<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Milestones;
use Redoubt\Support\Security;

/**
 * Milestones / Key Dates module (Annex H). View for members (milestone.view),
 * manage for milestone.manage.
 */
final class MilestonesController
{
    /** GET /app/milestones */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Milestones require the database (DATABASE_URL).');
            return;
        }
        $programs = self::programs($user, 'milestone.view');
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no program where you may view milestones.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'milestone.view', ['program_id' => $programId]);

        $items = Milestones::listForProgram($programId);
        $can = ['manage' => Authorize::can($user, 'milestone.manage', ['program_id' => $programId])];
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_milestones.php';
    }

    /** POST /app/milestones */
    public static function post(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::plain(419, 'CSRF validation failed.');
            return;
        }
        $programId = (int) ($_POST['program_id'] ?? 0);
        Authorize::requirePermission($user, 'milestone.manage', ['program_id' => $programId]);
        $actorId = $user['id'] ?? null;
        $data = [
            'title'    => trim((string) ($_POST['title'] ?? '')),
            'due_date' => trim((string) ($_POST['due_date'] ?? '')),
            'type'     => (string) ($_POST['type'] ?? 'event'),
        ];
        switch ((string) ($_POST['action'] ?? '')) {
            case 'create':
                Milestones::create($programId, $data, $actorId);
                break;
            case 'update':
                Milestones::update((int) ($_POST['id'] ?? 0), $programId, $data, $actorId);
                break;
            case 'delete':
                Milestones::delete((int) ($_POST['id'] ?? 0), $programId, $actorId);
                break;
            default:
                self::plain(400, 'Unknown action.');
                return;
        }
        header('Location: /app/milestones?program_id=' . $programId);
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
