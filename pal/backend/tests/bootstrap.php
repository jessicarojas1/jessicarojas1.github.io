<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Rebuild the test database from migrations once per run, so tests always exercise the
// real schema (constraints, triggers) rather than a hand-maintained copy.
if ('1' !== getenv('PAL_TEST_SKIP_DB_RESET')) {
    $url = (string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '');
    if (!str_contains($url, '_test')) {
        fwrite(\STDERR, "Refusing to reset a database whose name does not contain _test.\n");
        exit(1);
    }
    $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
    $connection = DriverManager::getConnection($params);
    $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
    $connection->executeStatement('CREATE SCHEMA public');
    $connection->close();

    passthru(sprintf('%s %s doctrine:migrations:migrate --no-interaction --env=test -q', escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__).'/bin/console')), $exitCode);
    if (0 !== $exitCode) {
        fwrite(\STDERR, "Test database migration failed.\n");
        exit(1);
    }
}
