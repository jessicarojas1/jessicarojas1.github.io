<?php

declare(strict_types=1);

namespace Verity\Http;

use Verity\Support\Audit;
use Verity\Support\Config;
use Verity\Support\Db;

/**
 * One-time remote bootstrap endpoint — applies database/schema.sql and runs
 * the synthetic seed script over plain HTTPS, for deployment targets with no
 * shell/SSH access (e.g. Render's free compute plan, which gates Shell and
 * One-Off Jobs behind a paid plan).
 *
 * Gated behind a SETUP_TOKEN environment variable compared in constant time;
 * if that variable is unset, this route always 404s — it does not exist
 * until an operator deliberately turns it on. Intentionally narrow and safe
 * to leave reachable even if the token ever leaked: schema application is
 * idempotent, and seeding only ever runs when the `person` table is
 * genuinely empty, so a stray/leaked token cannot re-seed or disturb real
 * data. Remove the SETUP_TOKEN env var after use to close the endpoint again
 * — see OPEN_ITEMS.md.
 */
final class SetupController
{
    public static function run(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        $token = Config::get('SETUP_TOKEN');
        $provided = (string) ($_GET['token'] ?? '');
        if ($token === null || $token === '' || $provided === '' || !hash_equals($token, $provided)) {
            http_response_code(404);
            echo '404 Not Found';
            return;
        }

        if (!Db::isConfigured()) {
            http_response_code(503);
            echo 'DATABASE_URL is not configured.';
            return;
        }

        $root = dirname(__DIR__, 2);

        $schemaSql = file_get_contents($root . '/database/schema.sql');
        if ($schemaSql === false) {
            http_response_code(500);
            echo 'Could not read database/schema.sql.';
            return;
        }
        Db::connection()->exec($schemaSql);
        echo "Schema applied (idempotent — safe to re-run).\n";

        $personCount = (int) Db::fetchValue('SELECT COUNT(*) FROM person');
        if ($personCount > 0) {
            echo "Already has $personCount people — seeding skipped (never re-seeds over existing data).\n";
        } else {
            // seed.php refuses to run when APP_ENV=production (by design —
            // see its own header comment); override it for this one
            // subprocess only, exactly as docs/DEPLOYMENT.md documents for
            // manual shell use. The subprocess gets a deliberately minimal,
            // explicit environment — not the parent's — so nothing besides
            // what's needed to connect and seed is exposed to it.
            $env = [
                'DATABASE_URL' => (string) getenv('DATABASE_URL'),
                'DB_SCHEMA' => (string) getenv('DB_SCHEMA'),
                'APP_ENV' => 'development',
            ];
            $process = proc_open(
                [PHP_BINARY, $root . '/database/seed.php', '--force'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                $env
            );
            if ($process === false) {
                http_response_code(500);
                echo 'Could not start the seed process.';
                return;
            }
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            echo "Seed output:\n$output\nExit code: $exitCode\n";
        }

        Audit::log('setup.run', 'bootstrap', null, ['person_count_before' => $personCount]);
    }
}
