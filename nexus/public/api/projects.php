<?php
/**
 * NEXUS - /api/projects/* routes.
 */

declare(strict_types=1);

use Nexus\Auth;
use Nexus\Database;
use Nexus\Response;

/** @var \Nexus\Router $router */

$router->get('/api/projects', function () {
    $me = Auth::requireAuth();
    // Admins see all projects; others see only ones they belong to.
    $sql = "SELECT p.*,
                   (SELECT COUNT(*) FROM tickets t WHERE t.project_id = p.id) AS ticket_count
              FROM projects p";
    $params = [];
    if (($me['role'] ?? '') !== 'admin') {
        $sql .= " WHERE p.id IN (SELECT project_id FROM project_members WHERE user_id = :uid)";
        $params[':uid'] = $me['sub'];
    }
    $sql .= " ORDER BY p.created_at DESC";
    $rows = Database::fetchAll($sql, $params);
    Response::list(array_map('projectShape', $rows));
});

$router->post('/api/projects', function () {
    $me = Auth::requireRole('admin');
    $body = Response::readJsonBody();

    $key  = strtoupper(trim((string)($body['key']  ?? '')));
    $name = trim((string)($body['name'] ?? ''));
    if ($key === '' || $name === '') {
        Response::error('key and name are required', 422, 'VALIDATION');
    }
    if (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $key)) {
        Response::error('key must be 2-10 uppercase alphanumerics starting with a letter', 422, 'VALIDATION');
    }

    $id          = Database::newId('proj');
    $description = trim((string)($body['description'] ?? ''));
    $color       = (string)($body['color'] ?? '#6366f1');
    $icon        = (string)($body['icon']  ?? '🚀');
    $leadId      = $body['leadId'] ?? $me['sub'];

    Database::transaction(function () use ($id, $key, $name, $description, $color, $icon, $leadId, $me) {
        Database::execute(
            "INSERT INTO projects (id, key, name, description, color, icon, lead_id)
                  VALUES (:id, :key, :name, :description, :color, :icon, :lead)",
            [
                ':id' => $id, ':key' => $key, ':name' => $name,
                ':description' => $description, ':color' => $color,
                ':icon' => $icon, ':lead' => $leadId,
            ]
        );
        Database::execute(
            "INSERT INTO project_members (project_id, user_id, role) VALUES (:p, :u, 'admin')
                  ON CONFLICT DO NOTHING",
            [':p' => $id, ':u' => $me['sub']]
        );
    });

    $project = Database::fetchOne('SELECT * FROM projects WHERE id = :id', [':id' => $id]);
    Response::created(projectShape($project));
});

$router->get('/api/projects/{id}', function ($args) {
    $me = Auth::requireAuth();
    $project = Database::fetchOne('SELECT * FROM projects WHERE id = :id', [':id' => $args['id']]);
    if ($project === null) {
        Response::notFound('Project not found');
    }
    requireMembership($me, $project['id']);

    $members = Database::fetchAll(
        "SELECT u.id, u.username, u.display_name, u.role AS global_role, pm.role AS project_role,
                u.clearance, u.org
           FROM project_members pm
           JOIN users u ON u.id = pm.user_id
          WHERE pm.project_id = :p
          ORDER BY u.display_name",
        [':p' => $args['id']]
    );

    $shape            = projectShape($project);
    $shape['members'] = array_map(fn ($m) => [
        'id'           => $m['id'],
        'username'     => $m['username'],
        'displayName'  => $m['display_name'],
        'role'         => $m['global_role'],
        'projectRole'  => $m['project_role'],
        'clearance'    => $m['clearance'],
        'org'          => $m['org'],
    ], $members);

    Response::ok($shape);
});

$router->patch('/api/projects/{id}', function ($args) {
    Auth::requireRole('admin');
    $body  = Response::readJsonBody();
    $allowed = ['name', 'description', 'color', 'icon', 'lead_id', 'statuses'];
    $sets   = [];
    $params = [':id' => $args['id']];
    foreach ($allowed as $col) {
        $key = $col === 'lead_id' ? 'leadId' : $col;
        if (array_key_exists($key, $body)) {
            $val = $body[$key];
            if ($col === 'statuses' && is_array($val)) {
                $val = json_encode($val);
            }
            $sets[] = "$col = :$col";
            $params[":$col"] = $val;
        }
    }
    if (!$sets) {
        Response::error('No updatable fields supplied', 422, 'VALIDATION');
    }
    Database::execute("UPDATE projects SET " . implode(', ', $sets) . " WHERE id = :id", $params);
    $project = Database::fetchOne('SELECT * FROM projects WHERE id = :id', [':id' => $args['id']]);
    Response::ok(projectShape($project));
});

$router->post('/api/projects/{id}/members', function ($args) {
    Auth::requireRole('admin');
    $body = Response::readJsonBody();
    $uid  = (string)($body['userId'] ?? '');
    $role = (string)($body['role'] ?? 'member');
    if ($uid === '') {
        Response::error('userId required', 422, 'VALIDATION');
    }
    Database::execute(
        "INSERT INTO project_members (project_id, user_id, role) VALUES (:p, :u, :r)
              ON CONFLICT (project_id, user_id) DO UPDATE SET role = EXCLUDED.role",
        [':p' => $args['id'], ':u' => $uid, ':r' => $role]
    );
    Response::ok(['ok' => true]);
});

$router->delete('/api/projects/{id}/members/{uid}', function ($args) {
    Auth::requireRole('admin');
    Database::execute(
        'DELETE FROM project_members WHERE project_id = :p AND user_id = :u',
        [':p' => $args['id'], ':u' => $args['uid']]
    );
    Response::ok(['ok' => true]);
});

// ── helpers ──────────────────────────────────────────────────────────
function requireMembership(array $me, string $projectId): void
{
    if (($me['role'] ?? '') === 'admin') {
        return;
    }
    $row = Database::fetchOne(
        'SELECT 1 FROM project_members WHERE project_id = :p AND user_id = :u',
        [':p' => $projectId, ':u' => $me['sub']]
    );
    if ($row === null) {
        Response::forbidden('Not a member of this project');
    }
}

function projectShape(array $row): array
{
    $statuses = $row['statuses'] ?? null;
    if (is_string($statuses)) {
        $statuses = json_decode($statuses, true) ?: [];
    }
    return [
        'id'          => $row['id'],
        'key'         => $row['key'],
        'name'        => $row['name'],
        'description' => $row['description'],
        'color'       => $row['color'],
        'icon'        => $row['icon'],
        'leadId'      => $row['lead_id'] ?? null,
        'statuses'    => $statuses ?: ['Backlog', 'To Do', 'In Progress', 'In Review', 'Blocked', 'Done'],
        'ticketCount' => isset($row['ticket_count']) ? (int)$row['ticket_count'] : null,
        'createdAt'   => $row['created_at'] ?? null,
    ];
}
