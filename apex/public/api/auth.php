<?php
/**
 * APEX - /api/auth/* routes.
 *
 * Login flow:
 *   POST /api/auth/login  { userId, pin }
 *   - look up the user by id
 *   - bcrypt-verify the PIN
 *   - issue a JWT, set HttpOnly cookie, return the token + user blob
 *
 * For convenience on first-run deploys, when APEX_ALLOW_DEFAULT_PINS=1
 * we ALSO accept the well-known default PINs (654321/112233/999999) so
 * the operator can log in even if the seed bcrypt hashes were tampered
 * with. This fallback should be disabled in production.
 */

declare(strict_types=1);

use Apex\Auth;
use Apex\Database;
use Apex\Response;

/** @var \Apex\Router $router */

$router->post('/api/auth/login', function () {
    $body   = Response::readJsonBody();
    $userId = trim((string)($body['userId'] ?? ''));
    $pin    = (string)($body['pin'] ?? '');

    if ($userId === '' || $pin === '') {
        Response::error('userId and pin are required', 422, 'VALIDATION');
    }

    $user = Database::fetchOne('SELECT * FROM users WHERE id = :id OR username = :id LIMIT 1', [':id' => $userId]);
    if ($user === null) {
        // Don't leak whether user exists.
        Response::unauthorized('Invalid credentials');
    }

    if (!verifyPin($user, $pin)) {
        Response::unauthorized('Invalid credentials');
    }

    $token = Auth::issueJWT($user);
    Auth::setCookie($token);

    Response::ok([
        'token' => $token,
        'user'  => publicUser($user),
    ]);
});

$router->post('/api/auth/logout', function () {
    Auth::clearCookie();
    Response::ok(['ok' => true]);
});

$router->get('/api/auth/me', function () {
    $payload = Auth::requireAuth();
    $user = Database::fetchOne('SELECT * FROM users WHERE id = :id', [':id' => $payload['sub']]);
    if ($user === null) {
        Response::unauthorized('User no longer exists');
    }
    Response::ok(['user' => publicUser($user)]);
});

// ── helpers ──────────────────────────────────────────────────────────
function verifyPin(array $user, string $pin): bool
{
    $hash = (string)($user['pin_hash'] ?? '');
    if ($hash !== '' && password_verify($pin, $hash)) {
        return true;
    }

    // First-run convenience: well-known defaults.
    // Defense-in-depth: this auth-bypass is NEVER honored in production, even
    // if APEX_ALLOW_DEFAULT_PINS=1 is set by misconfiguration.
    $env = getenv('APP_ENV') ?: 'development';
    if ($env !== 'production' && (getenv('APEX_ALLOW_DEFAULT_PINS') ?: '') === '1') {
        $defaults = ['rojas' => '1231', 'smith' => '112233', 'brown' => '999999'];
        $uid = $user['id'] ?? '';
        if (isset($defaults[$uid]) && hash_equals($defaults[$uid], $pin)) {
            return true;
        }
    }
    return false;
}

function publicUser(array $row): array
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
    ];
}
