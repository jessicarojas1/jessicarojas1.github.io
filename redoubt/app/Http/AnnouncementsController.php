<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Announcements;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Security;

/**
 * Announcements module web UI (Annex H). Every action is permission-checked via
 * the same Authorize engine; writes validate CSRF; publish emits a webhook + audit
 * (in the service). Server-rendered forms — no client JS required.
 */
final class AnnouncementsController
{
    /** GET /app/announcements */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, "Announcements require the database (DATABASE_URL). See deployments/AZURE.md / DEPLOYMENT.md.");
            return;
        }
        $programs = self::programs($user, 'announcement.view');
        if ($programs === []) {
            self::plain(403, "403 Forbidden — you have no program where you may view announcements.");
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'announcement.view', ['program_id' => $programId]);

        $items = Announcements::listForUser($user, $programId);
        $can = [
            'create'  => Authorize::can($user, 'announcement.create', ['program_id' => $programId]),
            'edit'    => Authorize::can($user, 'announcement.edit', ['program_id' => $programId]),
            'publish' => Authorize::can($user, 'announcement.publish', ['program_id' => $programId]),
        ];
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_announcements.php';
    }

    /** POST /app/announcements */
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
                Authorize::requirePermission($user, 'announcement.create', ['program_id' => $programId]);
                Announcements::create($programId, self::formData(), $actorId);
                break;
            case 'update':
                Authorize::requirePermission($user, 'announcement.edit', ['program_id' => $programId]);
                Announcements::update($id, $programId, self::formData(), $actorId);
                break;
            case 'publish':
                Authorize::requirePermission($user, 'announcement.publish', ['program_id' => $programId]);
                Announcements::publish($id, $programId, $actorId);
                break;
            case 'delete':
                Authorize::requirePermission($user, 'announcement.edit', ['program_id' => $programId]);
                Announcements::delete($id, $programId, $actorId);
                break;
            default:
                self::plain(400, 'Unknown action.');
                return;
        }
        header('Location: /app/announcements?program_id=' . $programId);
    }

    /** @return array<string,mixed> */
    private static function formData(): array
    {
        $audience = [];
        foreach (['all', 'internal', 'customer'] as $tok) {
            if (!empty($_POST['aud_' . $tok])) {
                $audience[] = $tok;
            }
        }
        $priority = in_array($_POST['priority'] ?? '', ['normal', 'high', 'critical'], true) ? $_POST['priority'] : 'normal';
        $expire = trim((string) ($_POST['expire_at'] ?? ''));
        return [
            'title'     => trim((string) ($_POST['title'] ?? '')),
            'body'      => trim((string) ($_POST['body'] ?? '')),
            'audience'  => $audience,
            'priority'  => $priority,
            'expire_at' => $expire !== '' ? $expire : null,
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
