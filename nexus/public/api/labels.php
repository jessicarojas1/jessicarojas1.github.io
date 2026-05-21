<?php
/**
 * NEXUS - project labels.
 */

declare(strict_types=1);

use Nexus\Auth;
use Nexus\Database;
use Nexus\Response;

/** @var \Nexus\Router $router */

$router->get('/api/projects/{id}/labels', function ($args) {
    $me = Auth::requireAuth();
    requireMembership($me, $args['id']);
    $rows = Database::fetchAll(
        'SELECT * FROM labels WHERE project_id = :p ORDER BY name',
        [':p' => $args['id']]
    );
    Response::list(array_map('labelShape', $rows));
});

$router->post('/api/projects/{id}/labels', function ($args) {
    Auth::requireRole('admin');
    $body  = Response::readJsonBody();
    $name  = trim((string)($body['name'] ?? ''));
    $color = (string)($body['color'] ?? '#6b7280');
    if ($name === '') {
        Response::error('name required', 422, 'VALIDATION');
    }
    $id = Database::newId('lbl');
    Database::execute(
        "INSERT INTO labels (id, project_id, name, color) VALUES (:id, :p, :n, :c)",
        [':id' => $id, ':p' => $args['id'], ':n' => $name, ':c' => $color]
    );
    $row = Database::fetchOne('SELECT * FROM labels WHERE id = :id', [':id' => $id]);
    Response::created(labelShape($row));
});

$router->patch('/api/labels/{id}', function ($args) {
    Auth::requireRole('admin');
    $body = Response::readJsonBody();
    $sets = [];
    $params = [':id' => $args['id']];
    foreach (['name', 'color'] as $col) {
        if (array_key_exists($col, $body)) {
            $sets[] = "$col = :$col";
            $params[":$col"] = $body[$col];
        }
    }
    if (!$sets) {
        Response::error('Nothing to update', 422, 'VALIDATION');
    }
    Database::execute("UPDATE labels SET " . implode(', ', $sets) . " WHERE id = :id", $params);
    $row = Database::fetchOne('SELECT * FROM labels WHERE id = :id', [':id' => $args['id']]);
    if ($row === null) {
        Response::notFound('Label not found');
    }
    Response::ok(labelShape($row));
});

$router->delete('/api/labels/{id}', function ($args) {
    Auth::requireRole('admin');
    Database::execute('DELETE FROM labels WHERE id = :id', [':id' => $args['id']]);
    Response::ok(['ok' => true]);
});

function labelShape(array $row): array
{
    return [
        'id'        => $row['id'],
        'projectId' => $row['project_id'],
        'name'      => $row['name'],
        'color'     => $row['color'],
    ];
}
