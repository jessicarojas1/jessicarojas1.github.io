<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Audit;
use Verity\Support\Auth;
use Verity\Support\Authorize;
use Verity\Support\PermissionCatalog;
use Verity\Support\People;
use Verity\Support\Roles;
use Verity\Support\Security;
use Verity\Support\Users;

/**
 * Admin IAM console — two-pane user list + per-module accordion editor, with
 * the role-default / explicit-grant / explicit-deny three-state model.
 */
final class IamController
{
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'iam.view');

        $canManage = Authorize::can($user, 'iam.manage');
        $people = $canManage ? People::list([], 500, 0) : [];

        $bootstrap = [
            'csrf' => Security::csrfToken(),
            'catalog' => PermissionCatalog::modules(),
            'roles' => array_map(static fn ($r) => ['key' => $r, 'label' => Roles::label($r)], Roles::all()),
            'total' => PermissionCatalog::total(),
            'canManage' => $canManage,
            'currentUserId' => (int) $user['id'],
            'minPasswordLength' => Auth::MIN_PASSWORD_LENGTH,
            'people' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'name' => $p['display_name']], $people),
            'endpoints' => [
                'users' => '/app/admin/iam/users',
                'user' => '/app/admin/iam/user',
                'save' => '/app/admin/iam/save',
                'createUser' => '/app/admin/iam/create-user',
                'updateDetails' => '/app/admin/iam/update-details',
                'resetPassword' => '/app/admin/iam/reset-password',
                'setStatus' => '/app/admin/iam/set-status',
            ],
        ];

        $title = 'Access & Security';
        $navActive = 'iam';
        $breadcrumbs = ['Access & Security' => null];
        $NONCE = $nonce;
        $appScript = '/assets/iam.js';
        require dirname(__DIR__) . '/Views/app_iam.php';
    }

    /** JSON: list of platform users for the left pane. */
    public static function users(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'iam.view');
        header('Content-Type: application/json; charset=utf-8');
        $users = array_map(static function ($u) {
            return [
                'id' => (int) $u['id'],
                'email' => $u['email'],
                'name' => $u['display_name'],
                'status' => $u['status'],
                'roles' => $u['roles'],
                'personName' => $u['person_name'],
            ];
        }, Users::list());
        echo Security::jsonForScript(['users' => $users]);
    }

    /** JSON: effective per-permission state for one user. */
    public static function userPerms(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'iam.view');
        $id = (int) ($_GET['id'] ?? 0);
        $target = Users::get($id);
        if ($target === null) {
            http_response_code(404);
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo Security::jsonForScript([
            'user' => [
                'id' => $id,
                'roles' => $target['roles'],
                'displayName' => $target['display_name'],
                'email' => $target['email'],
                'status' => $target['status'],
                'personId' => $target['person_id'] !== null ? (int) $target['person_id'] : null,
            ],
            'states' => Users::permissionStates($id),
        ]);
    }

    /** JSON POST: save role list + explicit grant/deny changes for one user. */
    public static function save(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'iam.manage');

        $raw = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true) ?: [];
        if (!Security::validateCsrf($body['_csrf'] ?? null)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
            return;
        }

        $targetId = (int) ($body['user_id'] ?? 0);
        if ($targetId <= 0 || Users::get($targetId) === null) {
            http_response_code(404);
            return;
        }

        if (isset($body['roles']) && is_array($body['roles'])) {
            Users::setRoles($targetId, $body['roles'], $user['id']);
        }
        $applied = 0;
        if (isset($body['changes']) && is_array($body['changes'])) {
            $applied = Users::saveGrants($targetId, $body['changes'], $user['id']);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'applied' => $applied, 'csrf' => Security::rotateCsrf()]);
    }

    public static function createUser(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'iam.manage');
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'Invalid request.';
            return;
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        $name = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $roles = isset($_POST['roles']) && is_array($_POST['roles']) ? $_POST['roles'] : [];
        $personId = (int) ($_POST['person_id'] ?? 0) ?: null;
        if ($email === '' || $name === '') {
            http_response_code(400);
            echo 'Email and name are required.';
            return;
        }
        $policyError = Auth::passwordPolicyError($password);
        if ($policyError !== null) {
            http_response_code(400);
            echo $policyError;
            return;
        }
        try {
            Users::create($email, $name, $password, $roles, $personId, $user['id']);
        } catch (\PDOException $e) {
            http_response_code(409);
            echo 'A user with that email already exists.';
            return;
        }
        header('Location: /app/admin/iam');
    }

    /** JSON POST: update display name / email / linked identity for an existing user. */
    public static function updateUserDetails(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'iam.manage');

        $raw = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true) ?: [];
        if (!Security::validateCsrf($body['_csrf'] ?? null)) {
            self::jsonError(400, 'Invalid CSRF token.');
            return;
        }
        $targetId = (int) ($body['user_id'] ?? 0);
        if ($targetId <= 0 || Users::get($targetId) === null) {
            http_response_code(404);
            return;
        }
        $displayName = trim((string) ($body['display_name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $personId = isset($body['person_id']) && $body['person_id'] !== '' ? (int) $body['person_id'] : null;
        if ($displayName === '' || $email === '') {
            self::jsonError(400, 'Display name and email are required.');
            return;
        }

        try {
            Users::updateDetails($targetId, $displayName, $email, $personId, (int) $user['id']);
        } catch (\PDOException $e) {
            self::jsonError(409, 'A user with that email already exists.');
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'csrf' => Security::rotateCsrf()]);
    }

    /** JSON POST: admin-set a user's password (no email delivery in this build — see OPEN_ITEMS.md). */
    public static function resetPassword(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'iam.manage');

        $raw = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true) ?: [];
        if (!Security::validateCsrf($body['_csrf'] ?? null)) {
            self::jsonError(400, 'Invalid CSRF token.');
            return;
        }
        $targetId = (int) ($body['user_id'] ?? 0);
        if ($targetId <= 0 || Users::get($targetId) === null) {
            http_response_code(404);
            return;
        }
        $newPassword = (string) ($body['new_password'] ?? '');
        $policyError = Auth::passwordPolicyError($newPassword);
        if ($policyError !== null) {
            self::jsonError(400, $policyError);
            return;
        }

        Auth::setPassword($targetId, $newPassword, (int) $user['id']);
        Audit::log('iam.password_reset', 'app_user#' . $targetId, null, null, null, (int) $user['id']);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'csrf' => Security::rotateCsrf()]);
    }

    /** JSON POST: activate/disable a user. */
    public static function setStatus(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        Authorize::requirePermission($user, 'iam.manage');

        $raw = file_get_contents('php://input') ?: '{}';
        $body = json_decode($raw, true) ?: [];
        if (!Security::validateCsrf($body['_csrf'] ?? null)) {
            self::jsonError(400, 'Invalid CSRF token.');
            return;
        }
        $targetId = (int) ($body['user_id'] ?? 0);
        $status = (string) ($body['status'] ?? '');
        if ($targetId <= 0 || Users::get($targetId) === null) {
            http_response_code(404);
            return;
        }
        if (!in_array($status, ['active', 'invited', 'disabled'], true)) {
            self::jsonError(400, 'Invalid status.');
            return;
        }
        if ($targetId === (int) $user['id'] && $status !== 'active') {
            self::jsonError(400, 'You cannot disable your own account.');
            return;
        }

        Users::setStatus($targetId, $status, (int) $user['id']);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'csrf' => Security::rotateCsrf()]);
    }

    private static function jsonError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message]);
    }
}
