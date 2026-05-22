<?php
function getDatabaseConfig(): array {
    $url = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?? '';

    if ($url) {
        $parts = parse_url($url);
        return [
            'host'     => $parts['host'] ?? 'localhost',
            'port'     => $parts['port'] ?? 5432,
            'dbname'   => ltrim($parts['path'] ?? '/aegis', '/'),
            'user'     => $parts['user'] ?? 'aegis',
            'password' => $parts['pass'] ?? '',
        ];
    }

    return [
        'host'     => $_ENV['DB_HOST'] ?? 'localhost',
        'port'     => $_ENV['DB_PORT'] ?? 5432,
        'dbname'   => $_ENV['DB_NAME'] ?? 'aegis',
        'user'     => $_ENV['DB_USER'] ?? 'aegis',
        'password' => $_ENV['DB_PASS'] ?? '',
    ];
}

function getDSN(): string {
    $cfg = getDatabaseConfig();
    return "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};options='--search_path=aegis,public'";
}
