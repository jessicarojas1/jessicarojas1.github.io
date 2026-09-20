<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\AccessRequests;
use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Security;
use Throwable;

/**
 * Onboarding / offboarding console — the sponsored access lifecycle.
 * Sponsors (access.request) submit requests; approvers (access.grant) approve/deny
 * (which provisions the account + memberships); access.revoke offboards.
 */
final class AccessController
{
    /** GET /app/admin/access */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Onboarding requires the database (DATABASE_URL).');
            return;
        }
        $programs = self::programs($user);
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no onboarding access in any program.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }

        $can = [
            'request' => Authorize::can($user, 'access.request', ['program_id' => $programId]),
            'grant'   => Authorize::can($user, 'access.grant', ['program_id' => $programId]),
            'revoke'  => Authorize::can($user, 'access.revoke', ['program_id' => $programId]),
            'view'    => Authorize::can($user, 'access.view', ['program_id' => $programId]),
        ];
        $pending = ($can['grant'] || $can['view']) ? AccessRequests::listForProgram($programId, 'pending') : [];
        $roster  = ($can['revoke'] || $can['view']) ? AccessRequests::roster($programId) : [];
        $companies = self::companies($programId);
        $roles = self::assignableRoles();
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_access.php';
    }

    /** POST /app/admin/access */
    public static function post(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::plain(419, 'CSRF validation failed.');
            return;
        }
        $programId = (int) ($_POST['program_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        $actorId = $user['id'] ?? null;

        try {
            switch ($action) {
                case 'request':
                    Authorize::requirePermission($user, 'access.request', ['program_id' => $programId]);
                    $company = trim((string) ($_POST['company_id'] ?? ''));
                    AccessRequests::create($programId, [
                        'email'          => (string) ($_POST['email'] ?? ''),
                        'display_name'   => trim((string) ($_POST['display_name'] ?? '')) ?: null,
                        'company_id'     => ctype_digit($company) ? (int) $company : null,
                        'requested_role' => (string) ($_POST['requested_role'] ?? ''),
                        'kind'           => (string) ($_POST['kind'] ?? 'external'),
                        'is_us_person'   => isset($_POST['is_us_person']) ? ($_POST['is_us_person'] === '1') : null,
                        'justification'  => trim((string) ($_POST['justification'] ?? '')) ?: null,
                    ], $actorId);
                    break;
                case 'approve':
                    Authorize::requirePermission($user, 'access.grant', ['program_id' => $programId]);
                    AccessRequests::approve((int) ($_POST['id'] ?? 0), $programId, $actorId);
                    break;
                case 'deny':
                    Authorize::requirePermission($user, 'access.grant', ['program_id' => $programId]);
                    AccessRequests::deny((int) ($_POST['id'] ?? 0), $programId, $actorId);
                    break;
                case 'offboard':
                    Authorize::requirePermission($user, 'access.revoke', ['program_id' => $programId]);
                    AccessRequests::offboard($programId, (int) ($_POST['user_id'] ?? 0), $actorId);
                    break;
                case 'set_password':
                    Authorize::requirePermission($user, 'access.grant', ['program_id' => $programId]);
                    $pw = (string) ($_POST['password'] ?? '');
                    if (strlen($pw) < 8) {
                        self::plain(400, 'Password must be at least 8 characters.');
                        return;
                    }
                    \Redoubt\Support\Auth::setPassword((int) ($_POST['user_id'] ?? 0), $pw, $actorId);
                    break;
                default:
                    self::plain(400, 'Unknown action.');
                    return;
            }
        } catch (Throwable $e) {
            self::plain(400, 'Action failed: ' . $e->getMessage());
            return;
        }
        header('Location: /app/admin/access?program_id=' . $programId);
    }

    /** Roles that may be requested (exclude the privileged admin roles). @return array<string,string> */
    private static function assignableRoles(): array
    {
        $out = [];
        foreach (Db::fetchAll('SELECT key, name FROM role ORDER BY name') as $r) {
            if (in_array($r['key'], ['enterprise_admin', 'program_admin', 'security_admin'], true)) {
                continue;
            }
            $out[(string) $r['key']] = (string) $r['name'];
        }
        return $out;
    }

    /** Programs where the user may view or request access. @return array<int,string> */
    private static function programs(array $user): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (Authorize::can($user, 'access.view', ['program_id' => $pid]) || Authorize::can($user, 'access.request', ['program_id' => $pid])) {
                $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                $out[$pid] = $row['name'] ?? ('Program #' . $pid);
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function companies(int $programId): array
    {
        $out = [];
        foreach (Db::fetchAll(
            'SELECT DISTINCT c.id, c.name FROM company c JOIN program_membership pm ON pm.company_id = c.id WHERE pm.program_id = :p ORDER BY c.name',
            ['p' => $programId]
        ) as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    private static function plain(int $status, string $msg): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
}
