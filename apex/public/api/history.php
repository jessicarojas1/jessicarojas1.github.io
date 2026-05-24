<?php
/**
 * APEX - ticket audit log.
 */

declare(strict_types=1);

use Apex\Auth;
use Apex\Database;
use Apex\Response;

/** @var \Apex\Router $router */

$router->get('/api/tickets/{id}/history', function ($args) {
    $me = Auth::requireAuth();
    $ticket = Database::fetchOne('SELECT project_id FROM tickets WHERE id = :id', [':id' => $args['id']]);
    if ($ticket === null) {
        Response::notFound('Ticket not found');
    }
    requireMembership($me, $ticket['project_id']);

    $rows = Database::fetchAll(
        "SELECT h.*, u.display_name AS user_display_name
           FROM history h
           LEFT JOIN users u ON u.id = h.user_id
          WHERE h.ticket_id = :t
          ORDER BY h.timestamp DESC",
        [':t' => $args['id']]
    );

    Response::list(array_map(fn ($r) => [
        'id'              => $r['id'],
        'ticketId'        => $r['ticket_id'],
        'userId'          => $r['user_id'],
        'userDisplayName' => $r['user_display_name'] ?? null,
        'event'           => $r['event'],
        'field'           => $r['field'],
        'fromVal'         => $r['from_val'],
        'toVal'           => $r['to_val'],
        'timestamp'       => $r['timestamp'],
    ], $rows));
});

$router->get('/api/projects/{id}/history', function ($args) {
    $me = Auth::requireAuth();
    requireMembership($me, $args['id']);

    $rows = Database::fetchAll(
        "SELECT h.*, u.display_name AS user_display_name
           FROM history h
           LEFT JOIN users u ON u.id = h.user_id
           JOIN tickets t ON t.id = h.ticket_id
          WHERE t.project_id = :p
          ORDER BY h.timestamp DESC
          LIMIT 200",
        [':p' => $args['id']]
    );
    Response::list(array_map(fn ($r) => [
        'id'              => $r['id'],
        'ticketId'        => $r['ticket_id'],
        'userId'          => $r['user_id'],
        'userDisplayName' => $r['user_display_name'] ?? null,
        'event'           => $r['event'],
        'field'           => $r['field'],
        'fromVal'         => $r['from_val'],
        'toVal'           => $r['to_val'],
        'timestamp'       => $r['timestamp'],
    ], $rows));
});
