<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Db;
use Redoubt\Support\Notifications;
use Redoubt\Support\Security;

/**
 * Notification center. Lists the signed-in user's in-portal notifications and
 * supports mark-read / mark-all / open (mark read then jump to the item).
 */
final class NotificationsController
{
    /** GET /app/notifications */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured() || empty($user['id'])) {
            self::plain(503, 'Notifications require the database (DATABASE_URL).');
            return;
        }
        $items = Notifications::listFor((int) $user['id']);
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_notifications.php';
    }

    /** POST /app/notifications */
    public static function post(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::plain(419, 'CSRF validation failed.');
            return;
        }
        $uid = (int) ($user['id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'mark_all') {
            Notifications::markAllRead($uid);
            header('Location: /app/notifications');
            return;
        }
        if ($action === 'open') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = Db::fetchOne('SELECT ref FROM notification WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $uid]);
            Notifications::markRead($uid, $id);
            $url = '/app/notifications';
            if ($row !== null) {
                $ref = is_string($row['ref']) ? (json_decode($row['ref'], true) ?: []) : [];
                $cand = (string) ($ref['url'] ?? '');
                if (str_starts_with($cand, '/app/')) {   // only allow internal targets
                    $url = $cand;
                }
            }
            header('Location: ' . $url);
            return;
        }
        self::plain(400, 'Unknown action.');
    }

    private static function plain(int $status, string $msg): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
}
