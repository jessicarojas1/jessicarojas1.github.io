<?php
/**
 * APEX - Apply schema.sql if the `users` table does not yet exist.
 * Called automatically by bin/start.sh on every container start.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Response.php';

// Load .env if present (local dev).
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        if (!getenv($k)) putenv("$k=$v");
    }
}

try {
    $pdo = Apex\Database::pdo();
    $row = $pdo->query("SELECT to_regclass('public.users') AS t")->fetchColumn();
    if ($row === null) {
        echo "[migrate] Applying schema…\n";
        $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
        $pdo->exec($sql);
        echo "[migrate] Done.\n";
    } else {
        echo "[migrate] Schema already present — skipping full apply.\n";
    }

    // Idempotently ensure the operational auth tables exist even on an
    // already-populated DB. These are append-only / denylist tables that are
    // never dropped or reseeded, so it is always safe to (re)ensure them.
    // This is the forward-only slice for auth-event audit, login throttling,
    // and JWT revocation (see OPEN_ITEMS.md).
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS auth_events (
          id         VARCHAR(30)  PRIMARY KEY,
          identity   VARCHAR(255),
          user_id    VARCHAR(10),
          event      VARCHAR(30)  NOT NULL,
          ip         VARCHAR(64),
          user_agent VARCHAR(500),
          created_at TIMESTAMPTZ  DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_auth_events_identity ON auth_events(identity, created_at);
        CREATE INDEX IF NOT EXISTS idx_auth_events_ip       ON auth_events(ip, created_at);

        CREATE TABLE IF NOT EXISTS revoked_tokens (
          jti        VARCHAR(64)  PRIMARY KEY,
          user_id    VARCHAR(10),
          expires_at TIMESTAMPTZ  NOT NULL,
          revoked_at TIMESTAMPTZ  DEFAULT NOW()
        );
        CREATE INDEX IF NOT EXISTS idx_revoked_tokens_exp ON revoked_tokens(expires_at);
        SQL
    );
    echo "[migrate] Auth tables ensured.\n";
} catch (Throwable $e) {
    echo "[migrate] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
