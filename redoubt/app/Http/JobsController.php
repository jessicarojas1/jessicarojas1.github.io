<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Jobs;
use Redoubt\Support\Security;

/**
 * Job Requisitions module (Annex H). Program-scoped, audience-trimmed; every
 * action permission-checked; posting emits a signed webhook + audit (service).
 */
final class JobsController
{
    /** GET /app/jobs */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Jobs require the database (DATABASE_URL). See docs/DEPLOYMENT.md.');
            return;
        }
        $programs = self::programs($user, 'job.view');
        if ($programs === []) {
            self::plain(403, '403 Forbidden — no program where you may view jobs.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }
        Authorize::requirePermission($user, 'job.view', ['program_id' => $programId]);

        $items = Jobs::listForUser($user, $programId);
        $can = [
            'create'  => Authorize::can($user, 'job.create', ['program_id' => $programId]),
            'edit'    => Authorize::can($user, 'job.edit', ['program_id' => $programId]),
            'publish' => Authorize::can($user, 'job.publish', ['program_id' => $programId]),
        ];
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_jobs.php';
    }

    /** POST /app/jobs */
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
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $actorId = $user['id'] ?? null;

        switch ($action) {
            case 'create':
                Authorize::requirePermission($user, 'job.create', ['program_id' => $programId]);
                Jobs::create($programId, self::formData(), $actorId);
                break;
            case 'update':
                Authorize::requirePermission($user, 'job.edit', ['program_id' => $programId]);
                Jobs::update($id, $programId, self::formData(), $actorId);
                break;
            case 'publish':
                Authorize::requirePermission($user, 'job.publish', ['program_id' => $programId]);
                Jobs::publish($id, $programId, $actorId);
                break;
            case 'close':
                Authorize::requirePermission($user, 'job.edit', ['program_id' => $programId]);
                Jobs::close($id, $programId, $actorId);
                break;
            case 'delete':
                Authorize::requirePermission($user, 'job.edit', ['program_id' => $programId]);
                Jobs::delete($id, $programId, $actorId);
                break;
            default:
                self::plain(400, 'Unknown action.');
                return;
        }
        header('Location: /app/jobs?program_id=' . $programId);
    }

    /** @return array<string,mixed> */
    private static function formData(): array
    {
        $audience = [];
        foreach (['all', 'internal', 'customer'] as $tok) {
            if (!empty($_POST['aud_' . $tok])) {
                $audience[] = $tok;
            }
        }
        $expire = trim((string) ($_POST['expire_at'] ?? ''));
        return [
            'title'     => trim((string) ($_POST['title'] ?? '')),
            'audience'  => $audience,
            'ats_url'   => trim((string) ($_POST['ats_url'] ?? '')) ?: null,
            'expire_at' => $expire !== '' ? $expire : null,
        ];
    }

    /** @return array<int,string> */
    private static function programs(array $user, string $perm): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (Authorize::can($user, $perm, ['program_id' => $pid])) {
                $row = Db::fetchOne('SELECT name FROM program WHERE id = :id', ['id' => $pid]);
                $out[$pid] = $row['name'] ?? ('Program #' . $pid);
            }
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
