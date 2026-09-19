<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Announcements;
use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Directory;
use Redoubt\Support\Documents;
use Redoubt\Support\Jobs;
use Redoubt\Support\TaskOrders;

/**
 * Content Administration console — one hub for the execution team to manage
 * routine content across modules (the "no developer / no JIRA" requirement, §15).
 * It aggregates only the modules the user is actually allowed to author, with
 * counts and quick links; it grants no new authority of its own.
 */
final class ContentAdminController
{
    /** Module key => [label, icon, manage-permission, url]. */
    private const MODULES = [
        'announcements' => ['Announcements', '📣', 'announcement.create', '/app/announcements'],
        'documents'     => ['Documents', '📁', 'document.create', '/app/documents'],
        'taskorders'    => ['Task Orders', '📄', 'taskorder.create', '/app/task-orders'],
        'jobs'          => ['Jobs', '💼', 'job.create', '/app/jobs'],
        'directory'     => ['Directory', '👥', 'contact.manage', '/app/directory'],
    ];

    /** GET /app/admin/content */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Content Administration requires the database (DATABASE_URL).');
            return;
        }
        $programs = self::adminablePrograms($user);
        if ($programs === []) {
            self::plain(403, '403 Forbidden — you have no content-management access in any program.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Audit::log('content_admin.view', 'console', $programId);

        // Build cards for modules the user may author, with visible counts.
        $cards = [];
        foreach (self::MODULES as $key => [$label, $icon, $perm, $url]) {
            if (!Authorize::can($user, $perm, ['program_id' => $programId])) {
                continue;
            }
            $cards[$key] = [
                'label' => $label, 'icon' => $icon, 'url' => $url,
                'count' => self::count($key, $user, $programId),
            ];
        }
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_content_admin.php';
    }

    private static function count(string $key, array $user, int $programId): int
    {
        return match ($key) {
            'announcements' => count(Announcements::listForUser($user, $programId)),
            'documents'     => count(Documents::listForUser($user, $programId)),
            'taskorders'    => count(TaskOrders::listForUser($user, $programId)),
            'jobs'          => count(Jobs::listForUser($user, $programId)),
            'directory'     => count(Directory::listForUser($user, $programId)),
            default         => 0,
        };
    }

    /** Programs where the user can author at least one content type. @return array<int,string> */
    private static function adminablePrograms(array $user): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            foreach (self::MODULES as [, , $perm]) {
                if (Authorize::can($user, $perm, ['program_id' => $pid])) {
                    $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                    $out[$pid] = $row['name'] ?? ('Program #' . $pid);
                    break;
                }
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
