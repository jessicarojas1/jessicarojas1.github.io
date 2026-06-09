<?php
return [
    'name'        => $_ENV['APP_NAME'] ?? 'PALADIN',
    'env'         => $_ENV['APP_ENV'] ?? 'production',
    'url'         => $_ENV['APP_URL'] ?? '',
    'jwt_secret'  => $_ENV['JWT_SECRET'] ?? '',
    'session_lifetime' => 3600 * 8,
    'csrf_lifetime'    => 3600 * 2,
    'rate_limit'  => [
        'login_attempts'  => 5,
        'window_seconds'  => 300,
        'lockout_seconds' => 900,
    ],
    'password'    => [
        'min_length'      => 12,
        'require_upper'   => true,
        'require_number'  => true,
        'require_special' => true,
    ],
    'api' => [
        'version' => 'v1',
        'rate_limit_per_minute' => 60,
    ],
];
