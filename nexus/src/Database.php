<?php
/**
 * NEXUS - PDO singleton wrapping PostgreSQL access.
 * Reads DATABASE_URL (Render-style) and converts it to a PDO PgSQL DSN.
 */

declare(strict_types=1);

namespace Nexus;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $url = getenv('DATABASE_URL') ?: '';
        if ($url === '') {
            throw new RuntimeException('DATABASE_URL is not set');
        }

        // Accept either a postgres:// URL or a raw PDO DSN.
        if (str_starts_with($url, 'postgres://') || str_starts_with($url, 'postgresql://')) {
            $parts = parse_url($url);
            if ($parts === false) {
                throw new RuntimeException('Invalid DATABASE_URL');
            }
            $host = $parts['host'] ?? 'localhost';
            $port = $parts['port'] ?? 5432;
            $db   = ltrim($parts['path'] ?? '/postgres', '/');
            $user = $parts['user'] ?? '';
            $pass = isset($parts['pass']) ? urldecode($parts['pass']) : '';

            // Honour ?sslmode= if present.
            $sslmode = 'prefer';
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['sslmode'])) {
                    $sslmode = $q['sslmode'];
                }
            }

            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
                $host, $port, $db, $sslmode
            );
        } else {
            $dsn  = $url;
            $user = getenv('DATABASE_USER') ?: null;
            $pass = getenv('DATABASE_PASS') ?: null;
        }

        try {
            self::$pdo = new PDO($dsn, $user ?: null, $pass ?: null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('DB connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Generate a short prefixed id ("tkt_abc123def4").
     */
    public static function newId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}
