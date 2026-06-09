<?php
/**
 * Database connection configuration for the PALADIN platform.
 * Accepts either a single DATABASE_URL (Render/Heroku/Azure style) or
 * discrete DB_* environment variables. PostgreSQL only.
 */
function getDatabaseConfig(): array {
    $url = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';

    if ($url) {
        $parts = parse_url($url);
        return [
            'host'     => $parts['host'] ?? 'localhost',
            'port'     => $parts['port'] ?? 5432,
            'dbname'   => ltrim($parts['path'] ?? '/paladin', '/'),
            'user'     => $parts['user'] ?? 'paladin',
            'password' => $parts['pass'] ?? '',
        ];
    }

    return [
        'host'     => $_ENV['DB_HOST'] ?? 'localhost',
        'port'     => $_ENV['DB_PORT'] ?? 5432,
        'dbname'   => $_ENV['DB_NAME'] ?? 'paladin',
        'user'     => $_ENV['DB_USER'] ?? 'paladin',
        'password' => $_ENV['DB_PASS'] ?? '',
    ];
}

function getDSN(): string {
    $cfg = getDatabaseConfig();
    return "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};options='--search_path=paladin,public'";
}
