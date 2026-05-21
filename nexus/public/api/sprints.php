<?php
/**
 * NEXUS - sprint management.
 *
 * Completing a sprint (status -> 'completed') automatically moves any
 * tickets still attached that aren't Done back to the backlog (sprint_id NULL).
 */

declare(strict_types=1);

use Nexus\Auth;
use Nexus\Database;
use Nexus\Response;

/** @var \Nexus\Router $router */

$router->get('/api/projects/{id}/sprints', function ($args) {
    $me = Auth::requireAuth();
    requireMembership($me, $args['id']);
    $rows = Database::fetchAll(
        'SELECT * FROM sprints WHERE project_id = :p ORDER BY COALESCE(start_date, end_date) DESC, name',
        [':p' => $args['id']]
    );
    Response::list(array_map('sprintShape', $rows));
});

$router->post('/api/projects/{id}/sprints', function ($args) {
    Auth::requireRole('admin');
    $body = Response::readJsonBody();
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        Response::error('Sprint name required', 422, 'VALIDATION');
    }
    $id = Database::newId('spr');
    Database::execute(
        "INSERT INTO sprints (id, project_id, name, goal, start_date, end_date, status)
              VALUES (:id, :p, :n, :g, :s, :e, :st)",
        [
            ':id' => $id,
            ':p'  => $args['id'],
            ':n'  => $name,
            ':g'  => $body['goal']      ?? null,
            ':s'  => $body['startDate'] ?? null,
            ':e'  => $body['endDate']   ?? null,
            ':st' => $body['status']    ?? 'planning',
        ]
    );
    $row = Database::fetchOne('SELECT * FROM sprints WHERE id = :id', [':id' => $id]);
    Response::created(sprintShape($row));
});

$router->patch('/api/sprints/{id}', function ($args) {
    $me = Auth::requireRole('admin');
    $existing = Database::fetchOne('SELECT * FROM sprints WHERE id = :id', [':id' => $args['id']]);
    if ($existing === null) {
        Response::notFound('Sprint not found');
    }

    $body = Response::readJsonBody();
    $map  = [
        'name'      => 'name',
        'goal'      => 'goal',
        'startDate' => 'start_date',
        'endDate'   => 'end_date',
        'status'    => 'status',
    ];
    $sets   = [];
    $params = [':id' => $args['id']];
    foreach ($map as $key => $col) {
        if (array_key_exists($key, $body)) {
            $sets[] = "$col = :$col";
            $params[":$col"] = $body[$key];
        }
    }
    if (!$sets) {
        Response::error('Nothing to update', 422, 'VALIDATION');
    }
    Database::execute("UPDATE sprints SET " . implode(', ', $sets) . " WHERE id = :id", $params);

    // If we just completed the sprint, dis-associate any non-Done tickets.
    $newStatus = $body['status'] ?? null;
    if ($newStatus === 'completed' && $existing['status'] !== 'completed') {
        Database::execute(
            "UPDATE tickets SET sprint_id = NULL, updated_at = NOW()
              WHERE sprint_id = :s AND status <> 'Done'",
            [':s' => $args['id']]
        );
    }

    $row = Database::fetchOne('SELECT * FROM sprints WHERE id = :id', [':id' => $args['id']]);
    Response::ok(sprintShape($row));
});

function sprintShape(array $row): array
{
    return [
        'id'        => $row['id'],
        'projectId' => $row['project_id'],
        'name'      => $row['name'],
        'goal'      => $row['goal'],
        'startDate' => $row['start_date'],
        'endDate'   => $row['end_date'],
        'status'    => $row['status'],
    ];
}
