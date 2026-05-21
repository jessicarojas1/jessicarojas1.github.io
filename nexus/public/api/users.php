<?php
/**
 * NEXUS - User administration endpoints (admin only).
 *
 * GET    /api/users           list all users
 * GET    /api/users/{id}      get one user
 * POST   /api/users           create user
 * PATCH  /api/users/{id}      update display name, role, clearance, org
 * PATCH  /api/users/{id}/pin  change PIN (admin can change anyone's; member can change own)
 * DELETE /api/users/{id}      deactivate / delete user (admin only, cannot delete self)
 */

declare(strict_types=1);

use Nexus\Auth;
use Nexus\Database;
use Nexus\Response;

/** @var \Nexus\Router $router */

$router->get('/api/users', function () {
    Auth::requireRole('admin');
    $rows = Database::fetchAll(
        'SELECT id, username, display_name, first_name, last_name, role, clearance, org, created_at
           FROM users ORDER BY role DESC, last_name'
    );
    Response::list(array_map('userShape', $rows));
});

$router->get('/api/users/{id}', function ($args) {
    Auth::requireRole('admin');
    $row = Database::fetchOne(
        'SELECT id, username, display_name, first_name, last_name, role, clearance, org, created_at
           FROM users WHERE id = :id',
        [':id' => $args['id']]
    );
    if ($row === null) Response::notFound('User not found');
    Response::ok(userShape($row));
});

$router->post('/api/users', function () {
    Auth::requireRole('admin');
    $body = Response::readJsonBody();

    $id        = trim((string)($body['id']        ?? ''));
    $firstName = trim((string)($body['firstName'] ?? ''));
    $lastName  = trim((string)($body['lastName']  ?? ''));
    $role      = (string)($body['role']      ?? 'member');
    $clearance = (string)($body['clearance'] ?? 'UNCLASSIFIED');
    $org       = (string)($body['org']       ?? '');
    $pin       = (string)($body['pin']       ?? '');

    if ($id === '' || $firstName === '' || $lastName === '' || $pin === '') {
        Response::error('id, firstName, lastName, and pin are required', 422, 'VALIDATION');
    }
    if (!preg_match('/^\d{4,8}$/', $pin)) {
        Response::error('PIN must be 4–8 digits', 422, 'VALIDATION');
    }
    if (!in_array($role, ['admin', 'member', 'viewer'], true)) {
        Response::error('role must be admin, member, or viewer', 422, 'VALIDATION');
    }

    $displayName = "$firstName $lastName";
    $username    = strtolower($lastName);
    $hash        = password_hash($pin, PASSWORD_BCRYPT);

    Database::execute(
        "INSERT INTO users (id, username, display_name, first_name, last_name, role, clearance, org, pin_hash)
              VALUES (:id, :un, :dn, :fn, :ln, :role, :cl, :org, :hash)",
        [
            ':id'   => $id,
            ':un'   => $username,
            ':dn'   => $displayName,
            ':fn'   => $firstName,
            ':ln'   => $lastName,
            ':role' => $role,
            ':cl'   => $clearance,
            ':org'  => $org,
            ':hash' => $hash,
        ]
    );

    $row = Database::fetchOne('SELECT id, username, display_name, first_name, last_name, role, clearance, org, created_at FROM users WHERE id = :id', [':id' => $id]);
    Response::created(userShape($row));
});

$router->patch('/api/users/{id}', function ($args) {
    Auth::requireRole('admin');
    $body    = Response::readJsonBody();
    $allowed = ['display_name' => 'displayName', 'first_name' => 'firstName', 'last_name' => 'lastName', 'role' => 'role', 'clearance' => 'clearance', 'org' => 'org'];
    $sets    = [];
    $params  = [':id' => $args['id']];

    foreach ($allowed as $col => $jsonKey) {
        if (array_key_exists($jsonKey, $body)) {
            $sets[] = "$col = :$col";
            $params[":$col"] = $body[$jsonKey];
        }
    }
    if (!$sets) Response::error('No updatable fields supplied', 422, 'VALIDATION');

    Database::execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    $row = Database::fetchOne('SELECT id, username, display_name, first_name, last_name, role, clearance, org, created_at FROM users WHERE id = :id', [':id' => $args['id']]);
    Response::ok(userShape($row));
});

$router->patch('/api/users/{id}/pin', function ($args) {
    $me = Auth::requireRole('member');

    // Members can only change their own PIN; admins can change anyone's.
    if ($me['role'] !== 'admin' && $me['sub'] !== $args['id']) {
        Response::forbidden('You can only change your own PIN');
    }

    $body = Response::readJsonBody();
    $pin  = (string)($body['pin'] ?? '');
    if (!preg_match('/^\d{4,8}$/', $pin)) {
        Response::error('PIN must be 4–8 digits', 422, 'VALIDATION');
    }

    $hash = password_hash($pin, PASSWORD_BCRYPT);
    $updated = Database::execute('UPDATE users SET pin_hash = :h WHERE id = :id', [':h' => $hash, ':id' => $args['id']]);
    if ($updated === 0) Response::notFound('User not found');

    Response::ok(['ok' => true]);
});

$router->delete('/api/users/{id}', function ($args) {
    $me = Auth::requireRole('admin');
    if ($me['sub'] === $args['id']) {
        Response::error('You cannot delete your own account', 400, 'SELF_DELETE');
    }
    $deleted = Database::execute('DELETE FROM users WHERE id = :id', [':id' => $args['id']]);
    if ($deleted === 0) Response::notFound('User not found');
    Response::ok(['ok' => true]);
});

function userShape(array $row): array
{
    return [
        'id'          => $row['id'],
        'username'    => $row['username'],
        'displayName' => $row['display_name'],
        'firstName'   => $row['first_name'],
        'lastName'    => $row['last_name'],
        'role'        => $row['role'],
        'clearance'   => $row['clearance'],
        'org'         => $row['org'],
        'createdAt'   => $row['created_at'] ?? null,
    ];
}
