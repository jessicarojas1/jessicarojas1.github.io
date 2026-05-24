<?php
/**
 * APEX - /api/tickets/* + /api/projects/{id}/tickets routes.
 *
 * History entries are written on every meaningful mutation; notifications
 * fan out to assignee + watchers on status change and comments.
 */

declare(strict_types=1);

use Apex\Auth;
use Apex\Database;
use Apex\Response;

/** @var \Apex\Router $router */

$router->get('/api/projects/{id}/tickets', function ($args) {
    $me = Auth::requireAuth();
    requireMembership($me, $args['id']);

    $q       = $_GET;
    $clauses = ['project_id = :p'];
    $params  = [':p' => $args['id']];

    foreach (['status', 'priority', 'effort', 'type'] as $col) {
        if (!empty($q[$col])) {
            $clauses[] = "$col = :$col";
            $params[":$col"] = $q[$col];
        }
    }
    if (!empty($q['assignee'])) {
        $clauses[] = 'assignee_id = :assignee';
        $params[':assignee'] = $q['assignee'];
    }
    if (!empty($q['sprint'])) {
        $clauses[] = 'sprint_id = :sprint';
        $params[':sprint'] = $q['sprint'];
    }
    if (!empty($q['label'])) {
        $clauses[] = 'labels @> :label::jsonb';
        $params[':label'] = json_encode([$q['label']]);
    }
    if (!empty($q['search'])) {
        $clauses[] = '(title ILIKE :search OR description ILIKE :search OR id ILIKE :search)';
        $params[':search'] = '%' . $q['search'] . '%';
    }

    $where = implode(' AND ', $clauses);
    $rows = Database::fetchAll(
        "SELECT * FROM tickets WHERE $where ORDER BY backlog_order ASC, created_at DESC",
        $params
    );
    Response::list(array_map('ticketShape', $rows));
});

$router->post('/api/tickets', function () {
    $me   = Auth::requireRole('member');
    $body = Response::readJsonBody();

    $projectId = (string)($body['projectId'] ?? '');
    $title     = trim((string)($body['title'] ?? ''));
    if ($projectId === '' || $title === '') {
        Response::error('projectId and title are required', 422, 'VALIDATION');
    }

    $project = Database::fetchOne('SELECT key, statuses FROM projects WHERE id = :id', [':id' => $projectId]);
    if ($project === null) {
        Response::notFound('Project not found');
    }
    requireMembership($me, $projectId);

    // Generate sequential id like SEC-009
    $maxRow = Database::fetchOne(
        "SELECT COALESCE(MAX(NULLIF(regexp_replace(id, '\\D+', '', 'g'), '')::int), 0) AS n
           FROM tickets WHERE project_id = :p",
        [':p' => $projectId]
    );
    $n  = (int)($maxRow['n'] ?? 0) + 1;
    $id = $project['key'] . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);

    $fields = [
        'id'           => $id,
        'project_id'   => $projectId,
        'title'        => $title,
        'description'  => $body['description']  ?? '',
        'type'         => $body['type']         ?? 'task',
        'status'       => $body['status']       ?? 'Backlog',
        'priority'     => $body['priority']     ?? 'medium',
        'effort'       => $body['effort']       ?? 'moderate',
        'assignee_id'  => $body['assigneeId']   ?? null,
        'reporter_id'  => $body['reporterId']   ?? $me['sub'],
        'due_date'     => $body['dueDate']      ?? null,
        'epic_id'      => $body['epicId']       ?? null,
        'sprint_id'    => $body['sprintId']     ?? null,
        'story_points' => $body['storyPoints']  ?? null,
        'watchers'     => json_encode($body['watchers'] ?? []),
        'labels'       => json_encode($body['labels']   ?? []),
    ];

    $cols = implode(', ', array_keys($fields));
    $vals = implode(', ', array_map(fn ($k) => ":$k", array_keys($fields)));
    $params = [];
    foreach ($fields as $k => $v) {
        $params[":$k"] = $v;
    }
    Database::execute("INSERT INTO tickets ($cols) VALUES ($vals)", $params);

    logHistory($id, $me['sub'], 'created', null, null, null);
    notifyUser($fields['assignee_id'], $id, "You were assigned to $id: $title");

    $row = Database::fetchOne('SELECT * FROM tickets WHERE id = :id', [':id' => $id]);
    Response::created(ticketShape($row));
});

$router->get('/api/tickets/{id}', function ($args) {
    $me = Auth::requireAuth();
    $row = Database::fetchOne('SELECT * FROM tickets WHERE id = :id', [':id' => $args['id']]);
    if ($row === null) {
        Response::notFound('Ticket not found');
    }
    requireMembership($me, $row['project_id']);
    Response::ok(ticketShape($row));
});

$router->patch('/api/tickets/{id}', function ($args) {
    $me = Auth::requireRole('member');
    $existing = Database::fetchOne('SELECT * FROM tickets WHERE id = :id', [':id' => $args['id']]);
    if ($existing === null) {
        Response::notFound('Ticket not found');
    }
    requireMembership($me, $existing['project_id']);

    $body = Response::readJsonBody();
    $map = [
        'title'        => 'title',
        'description'  => 'description',
        'type'         => 'type',
        'status'       => 'status',
        'priority'     => 'priority',
        'effort'       => 'effort',
        'assigneeId'   => 'assignee_id',
        'reporterId'   => 'reporter_id',
        'dueDate'      => 'due_date',
        'epicId'       => 'epic_id',
        'sprintId'     => 'sprint_id',
        'storyPoints'  => 'story_points',
        'watchers'     => 'watchers',
        'labels'       => 'labels',
        'backlogOrder' => 'backlog_order',
    ];

    $sets   = ['updated_at = NOW()'];
    $params = [':id' => $args['id']];
    foreach ($map as $jsonKey => $col) {
        if (!array_key_exists($jsonKey, $body)) {
            continue;
        }
        $val = $body[$jsonKey];
        if (in_array($col, ['watchers', 'labels'], true) && is_array($val)) {
            $val = json_encode($val);
        }
        $sets[] = "$col = :$col";
        $params[":$col"] = $val;

        // Log history for scalar field changes (skip jsonb arrays).
        if (!in_array($col, ['watchers', 'labels'], true)) {
            $from = $existing[$col] ?? null;
            $to   = $val;
            if ((string)$from !== (string)$to) {
                logHistory($args['id'], $me['sub'], 'field_change', $col, (string)$from, (string)$to);
                if ($col === 'status') {
                    notifyWatchers($existing, "Status of " . $args['id'] . " changed: $from → $to", $me['sub']);
                }
                if ($col === 'assignee_id' && $to) {
                    notifyUser($to, $args['id'], 'You were assigned to ' . $args['id']);
                }
            }
        }
    }
    if (count($sets) === 1) {
        Response::error('No updatable fields supplied', 422, 'VALIDATION');
    }
    Database::execute("UPDATE tickets SET " . implode(', ', $sets) . " WHERE id = :id", $params);
    $row = Database::fetchOne('SELECT * FROM tickets WHERE id = :id', [':id' => $args['id']]);
    Response::ok(ticketShape($row));
});

$router->delete('/api/tickets/{id}', function ($args) {
    Auth::requireRole('admin');
    $row = Database::fetchOne('SELECT id FROM tickets WHERE id = :id', [':id' => $args['id']]);
    if ($row === null) {
        Response::notFound('Ticket not found');
    }
    Database::execute('DELETE FROM tickets WHERE id = :id', [':id' => $args['id']]);
    Response::ok(['ok' => true]);
});

$router->patch('/api/tickets/{id}/status', function ($args) {
    $me = Auth::requireRole('member');
    $body = Response::readJsonBody();
    $to   = trim((string)($body['status'] ?? ''));
    if ($to === '') {
        Response::error('status is required', 422, 'VALIDATION');
    }
    $existing = Database::fetchOne('SELECT * FROM tickets WHERE id = :id', [':id' => $args['id']]);
    if ($existing === null) {
        Response::notFound('Ticket not found');
    }
    requireMembership($me, $existing['project_id']);

    if ($existing['status'] === $to) {
        Response::ok(ticketShape($existing));
    }

    Database::execute(
        'UPDATE tickets SET status = :s, updated_at = NOW() WHERE id = :id',
        [':s' => $to, ':id' => $args['id']]
    );
    logHistory($args['id'], $me['sub'], 'status_change', 'status', (string)$existing['status'], $to);
    notifyWatchers($existing, "Status of {$args['id']} changed: {$existing['status']} → $to", $me['sub']);

    $row = Database::fetchOne('SELECT * FROM tickets WHERE id = :id', [':id' => $args['id']]);
    Response::ok(ticketShape($row));
});

$router->patch('/api/tickets/{id}/watch', function ($args) {
    $me = Auth::requireAuth();
    $existing = Database::fetchOne('SELECT * FROM tickets WHERE id = :id', [':id' => $args['id']]);
    if ($existing === null) {
        Response::notFound('Ticket not found');
    }
    requireMembership($me, $existing['project_id']);

    $watchers = json_decode((string)$existing['watchers'], true) ?: [];
    $uid = $me['sub'];
    $idx = array_search($uid, $watchers, true);
    if ($idx === false) {
        $watchers[] = $uid;
        $watching = true;
    } else {
        array_splice($watchers, (int)$idx, 1);
        $watching = false;
    }
    Database::execute(
        'UPDATE tickets SET watchers = :w WHERE id = :id',
        [':w' => json_encode(array_values($watchers)), ':id' => $args['id']]
    );
    Response::ok(['watching' => $watching, 'watchers' => $watchers]);
});

// ── shared helpers (also used by comments.php / sprints.php) ─────────
if (!function_exists('logHistory')) {
    function logHistory(string $ticketId, ?string $userId, string $event, ?string $field, ?string $from, ?string $to): void
    {
        Database::execute(
            "INSERT INTO history (id, ticket_id, user_id, event, field, from_val, to_val)
                  VALUES (:id, :t, :u, :e, :f, :fv, :tv)",
            [
                ':id' => Database::newId('hist'),
                ':t'  => $ticketId,
                ':u'  => $userId,
                ':e'  => $event,
                ':f'  => $field,
                ':fv' => $from,
                ':tv' => $to,
            ]
        );
    }
}

if (!function_exists('notifyUser')) {
    function notifyUser(?string $userId, string $ticketId, string $message): void
    {
        if (!$userId) {
            return;
        }
        Database::execute(
            "INSERT INTO notifications (id, user_id, ticket_id, message) VALUES (:id, :u, :t, :m)",
            [
                ':id' => Database::newId('ntf'),
                ':u'  => $userId,
                ':t'  => $ticketId,
                ':m'  => $message,
            ]
        );
    }
}

if (!function_exists('notifyWatchers')) {
    function notifyWatchers(array $ticket, string $message, ?string $excludeUserId = null): void
    {
        $watchers = json_decode((string)($ticket['watchers'] ?? '[]'), true) ?: [];
        $recipients = $watchers;
        if (!empty($ticket['assignee_id'])) {
            $recipients[] = $ticket['assignee_id'];
        }
        $recipients = array_unique(array_filter($recipients, fn ($u) => $u && $u !== $excludeUserId));
        foreach ($recipients as $uid) {
            notifyUser($uid, $ticket['id'], $message);
        }
    }
}

function ticketShape(array $row): array
{
    return [
        'id'           => $row['id'],
        'projectId'    => $row['project_id'],
        'title'        => $row['title'],
        'description'  => $row['description'],
        'type'         => $row['type'],
        'status'       => $row['status'],
        'priority'     => $row['priority'],
        'effort'       => $row['effort'],
        'assigneeId'   => $row['assignee_id'],
        'reporterId'   => $row['reporter_id'],
        'dueDate'      => $row['due_date'],
        'epicId'       => $row['epic_id'],
        'sprintId'     => $row['sprint_id'],
        'storyPoints'  => $row['story_points'] !== null ? (int)$row['story_points'] : null,
        'watchers'     => json_decode((string)$row['watchers'], true) ?: [],
        'labels'       => json_decode((string)$row['labels'],   true) ?: [],
        'backlogOrder' => isset($row['backlog_order']) ? (int)$row['backlog_order'] : 0,
        'createdAt'    => $row['created_at'],
        'updatedAt'    => $row['updated_at'],
    ];
}
