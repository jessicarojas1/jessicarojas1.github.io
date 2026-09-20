<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Auth;
use Redoubt\Support\Authorize;
use Redoubt\Support\Db;
use Redoubt\Support\Security;
use Redoubt\Support\Settings;

/**
 * Program Settings — Branding (logo URL / display name / accent) per the
 * mandatory Settings & Branding standard, plus program-level job-targeting
 * defaults. Requires branding.manage or program.config; persists to program_config.
 */
final class SettingsController
{
    /** GET /app/admin/settings */
    public static function index(string $nonce): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Db::isConfigured()) {
            self::plain(503, 'Settings require the database (DATABASE_URL).');
            return;
        }
        $programs = self::manageablePrograms($user);
        if ($programs === []) {
            self::plain(403, '403 Forbidden — you cannot manage settings in any program.');
            return;
        }
        $programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : (int) array_key_first($programs);
        if (!isset($programs[$programId])) {
            $programId = (int) array_key_first($programs);
        }

        $branding = Settings::branding($programId);
        $jobsDefaultProgramWide = Settings::jobsDefaultProgramWide($programId);
        $csrf = Security::csrfToken();
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_settings.php';
    }

    /** POST /app/admin/settings */
    public static function post(): void
    {
        Auth::requireAuth();
        $user = Auth::user();
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            self::plain(419, 'CSRF validation failed.');
            return;
        }
        $programId = (int) ($_POST['program_id'] ?? 0);
        if (!self::canManage($user, $programId)) {
            self::plain(403, '403 Forbidden.');
            return;
        }
        $actorId = $user['id'] ?? null;
        Settings::saveBranding($programId, [
            'logoUrl'     => (string) ($_POST['logoUrl'] ?? ''),
            'displayName' => (string) ($_POST['displayName'] ?? ''),
            'accent'      => (string) ($_POST['accent'] ?? ''),
        ], $actorId);
        Settings::saveJobs($programId, !empty($_POST['jobs_default_program_wide']), $actorId);

        header('Location: /app/admin/settings?program_id=' . $programId . '&saved=1');
    }

    private static function canManage(array $user, int $programId): bool
    {
        return Authorize::can($user, 'branding.manage', ['program_id' => $programId])
            || Authorize::can($user, 'program.config', ['program_id' => $programId]);
    }

    /** @return array<int,string> */
    private static function manageablePrograms(array $user): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            if (self::canManage($user, $pid)) {
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
