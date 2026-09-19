<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Directory;
use Redoubt\Support\Security;

/**
 * Program Directory module (Annex H). Viewers (directory.view) see visibility-
 * trimmed contacts; managers (contact.manage) manage them.
 */
final class DirectoryController
{
    /** GET /app/directory */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Directory requires the database (DATABASE_URL). See docs/DEPLOYMENT.md.');
            return;
        }
        $programs = self::programs($user, 'directory.view');
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no program where you may view the directory.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'directory.view', ['program_id' => $programId]);

        $items = Directory::listForUser($user, $programId);
        $can = ['manage' => Authorize::can($user, 'contact.manage', ['program_id' => $programId])];
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_directory.php';
    }

    /** POST /app/directory */
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

        // All directory writes require contact.manage.
        Authorize::requirePermission($user, 'contact.manage', ['program_id' => $programId]);
        switch ($action) {
            case 'create':
                Directory::create($programId, self::formData(), $actorId);
                break;
            case 'update':
                Directory::update($id, $programId, self::formData(), $actorId);
                break;
            case 'delete':
                Directory::delete($id, $programId, $actorId);
                break;
            default:
                self::plain(400, 'Unknown action.');
                return;
        }
        header('Location: /app/directory?program_id=' . $programId);
    }

    /** @return array<string,mixed> */
    private static function formData(): array
    {
        $vis = [];
        foreach (['all', 'internal', 'customer'] as $tok) {
            if (!empty($_POST['vis_' . $tok])) {
                $vis[] = $tok;
            }
        }
        $companyId = trim((string) ($_POST['company_id'] ?? ''));
        return [
            'freeform'   => trim((string) ($_POST['freeform'] ?? '')) ?: null,
            'role_label' => trim((string) ($_POST['role_label'] ?? '')) ?: null,
            'company_id' => ctype_digit($companyId) ? (int) $companyId : null,
            'visibility' => $vis,
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
