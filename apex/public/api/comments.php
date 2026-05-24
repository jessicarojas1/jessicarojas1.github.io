<?php
/**
 * APEX - /api/tickets/{id}/comments routes.
 */

declare(strict_types=1);

use Apex\Auth;
use Apex\Database;
use Apex\Response;

/** @var \Apex\Router $router */

$router->get('/api/tickets/{id}/comments', function ($args) {
    $me = Auth::requireAuth();
    $ticket = Database::fetchOne('SELECT project_id FROM tickets WHERE id = :id', [':id' => $args['id']]);
    if ($ticket === null) {
        Response::notFound('Ticket not found');
    }
    requireMembership($me, $ticket['project_id']);

    $rows = Database::fetchAll(
        "SELECT c.*, u.display_name AS user_display_name
           FROM comments c
           LEFT JOIN users u ON u.id = c.user_id
          WHERE c.ticket_id = :t
          ORDER BY c.created_at ASC",
        [':t' => $args['id']]
    );
    Response::list(array_map('commentShape', $rows));
});

$router->post('/api/tickets/{id}/comments', function ($args) {
    $me = Auth::requireRole('member');
    $ticket = Database::fetchOne('SELECT * FROM tickets WHERE id = :id', [':id' => $args['id']]);
    if ($ticket === null) {
        Response::notFound('Ticket not found');
    }
    requireMembership($me, $ticket['project_id']);

    $body = Response::readJsonBody();
    $text = trim((string)($body['body'] ?? ''));
    if ($text === '') {
        Response::error('Comment body required', 422, 'VALIDATION');
    }

    $id = Database::newId('cmt');
    Database::execute(
        "INSERT INTO comments (id, ticket_id, user_id, body) VALUES (:id, :t, :u, :b)",
        [':id' => $id, ':t' => $args['id'], ':u' => $me['sub'], ':b' => $text]
    );

    logHistory($args['id'], $me['sub'], 'comment_added', null, null, null);
    notifyWatchers($ticket, "New comment on " . $args['id'], $me['sub']);

    $row = Database::fetchOne(
        "SELECT c.*, u.display_name AS user_display_name
           FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = :id",
        [':id' => $id]
    );
    Response::created(commentShape($row));
});

function commentShape(array $row): array
{
    return [
        'id'              => $row['id'],
        'ticketId'        => $row['ticket_id'],
        'userId'          => $row['user_id'],
        'userDisplayName' => $row['user_display_name'] ?? null,
        'body'            => $row['body'],
        'createdAt'       => $row['created_at'],
        'updatedAt'       => $row['updated_at'],
    ];
}
