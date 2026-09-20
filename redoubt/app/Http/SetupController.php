<?php

declare(strict_types=1);

namespace Redoubt\Http;

use Redoubt\Support\Audit;
use Redoubt\Support\Auth;
use Redoubt\Support\Db;
use Redoubt\Support\Sample;
use Redoubt\Support\Security;
use Throwable;

/**
 * First-run setup — creates the first program + enterprise administrator with a
 * local password, so the portal is usable with only a database (no Entra/Graph).
 * Available ONLY while the database has no users (it self-disables afterward).
 */
final class SetupController
{
    /** GET /setup */
    public static function index(string $nonce): void
    {
        if (!Db::isConfigured()) {
            self::plain(503, "Set DATABASE_URL first (a PostgreSQL connection string), then reload /setup.");
            return;
        }
        if (self::userCount() > 0) {
            header('Location: /auth/login');
            return;
        }
        $error = isset($_GET['error']) ? (string) $_GET['error'] : '';
        $NONCE = $nonce;
        require dirname(__DIR__) . '/Views/app_setup.php';
    }

    /** POST /setup */
    public static function post(): void
    {
        if (!Db::isConfigured()) {
            self::plain(503, 'DATABASE_URL not set.');
            return;
        }
        // Ensure the schema exists (idempotent), then re-check the first-run guard.
        self::ensureSchema();
        if (self::userCount() > 0) {
            self::plain(403, 'Setup already completed.');
            return;
        }
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
            header('Location: /setup?error=' . rawurlencode('Session expired, try again.'));
            return;
        }
        $program = trim((string) ($_POST['program_name'] ?? ''));
        $name = trim((string) ($_POST['admin_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $password = (string) ($_POST['admin_password'] ?? '');
        if ($program === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            header('Location: /setup?error=' . rawurlencode('All fields required; password must be at least 8 characters.'));
            return;
        }

        try {
            $programId = Db::insert('program', ['name' => $program, 'status' => 'active']);
            $adminId = Db::insert('app_user', [
                'display_name'  => $name,
                'email'         => $email,
                'kind'          => 'internal',
                'is_us_person'  => true,
                'status'        => 'active',
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $roleId = (int) Db::fetchOne("SELECT id FROM role WHERE key = 'enterprise_admin'")['id'];
            Db::query(
                'INSERT INTO program_membership (program_id, user_id, role_id) VALUES (:p,:u,:r) ON CONFLICT DO NOTHING',
                ['p' => $programId, 'u' => $adminId, 'r' => $roleId]
            );
            Audit::log('setup.complete', 'program#' . $programId . ' admin#' . $adminId, $programId, $adminId);
            if (!empty($_POST['load_sample'])) {
                Sample::load($programId, $adminId);
            }
        } catch (Throwable $e) {
            error_log('[SETUP] ' . $e->getMessage());
            header('Location: /setup?error=' . rawurlencode('Setup failed: ' . $e->getMessage()));
            return;
        }
        header('Location: /auth/login?created=1');
    }

    private static function ensureSchema(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 1) . '/../database/schema.sql');
        if ($sql !== false) {
            try {
                Db::connection()->exec($sql);
            } catch (Throwable $e) {
                error_log('[SETUP] schema load: ' . $e->getMessage());
            }
        }
    }

    private static function userCount(): int
    {
        try {
            return (int) (Db::fetchOne('SELECT count(*) c FROM app_user')['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function plain(int $status, string $msg): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
}
