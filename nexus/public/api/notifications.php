<?php
/**
 * NEXUS - user notifications.
 */

declare(strict_types=1);

use Nexus\Auth;
use Nexus\Database;
use Nexus\Response;

/** @var \Nexus\Router $router */

$router->get('/api/notifications', function () {
    $me = Auth::requireAuth();
    $rows = Database::fetchAll(
        "SELECT * FROM notifications WHERE user_id = :u
          ORDER BY read ASC, created_at DESC LIMIT 100",
        [':u' => $me['sub']]
    );
    Response::list(array_map('notificationShape', $rows), [
        'unread' => countUnread($me['sub']),
    ]);
});

$router->patch('/api/notifications/{id}', function ($args) {
    $me = Auth::requireAuth();
    $body = Response::readJsonBody();
    $read = !empty($body['read']);

    $row = Database::fetchOne(
        'SELECT * FROM notifications WHERE id = :id AND user_id = :u',
        [':id' => $args['id'], ':u' => $me['sub']]
    );
    if ($row === null) {
        Response::notFound('Notification not found');
    }
    Database::execute(
        'UPDATE notifications SET read = :r WHERE id = :id',
        [':r' => $read ? 1 : 0, ':id' => $args['id']]
    );
    Response::ok(['ok' => true]);
});

$router->post('/api/notifications/read-all', function () {
    $me = Auth::requireAuth();
    Database::execute(
        'UPDATE notifications SET read = TRUE WHERE user_id = :u AND read = FALSE',
        [':u' => $me['sub']]
    );
    Response::ok(['ok' => true]);
});

function notificationShape(array $row): array
{
    return [
        'id'        => $row['id'],
        'userId'    => $row['user_id'],
        'ticketId'  => $row['ticket_id'],
        'message'   => $row['message'],
        'read'      => (bool)$row['read'],
        'createdAt' => $row['created_at'],
    ];
}

function countUnread(string $userId): int
{
    $row = Database::fetchOne(
        'SELECT COUNT(*) AS n FROM notifications WHERE user_id = :u AND read = FALSE',
        [':u' => $userId]
    );
    return (int)($row['n'] ?? 0);
}
