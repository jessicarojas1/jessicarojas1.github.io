<?php

declare(strict_types=1);

namespace Redoubt\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Thin PostgreSQL access layer over PDO. Parameterized queries only — never
 * concatenate user input into SQL.
 *
 * Db::update() automatically appends `updated_at = NOW()` (repo rule) — callers
 * must NOT include updated_at in the data array.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function isConfigured(): bool
    {
        return Config::databaseUrl() !== null;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $url = Config::databaseUrl();
        if ($url === null) {
            throw new RuntimeException('DATABASE_URL is not configured.');
        }
        $p = parse_url($url);
        if ($p === false || !isset($p['host'])) {
            throw new RuntimeException('DATABASE_URL is malformed.');
        }
        $host = $p['host'];
        $port = $p['port'] ?? 5432;
        $db   = ltrim($p['path'] ?? '', '/');
        $user = isset($p['user']) ? rawurldecode($p['user']) : '';
        $pass = isset($p['pass']) ? rawurldecode($p['pass']) : '';

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, (int) $port, $db);
        // sslmode is honored via the query string of DATABASE_URL if present.
        if (!empty($p['query'])) {
            parse_str($p['query'], $q);
            if (!empty($q['sslmode'])) {
                $dsn .= ';sslmode=' . $q['sslmode'];
            }
        }

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Do not leak credentials/DSN in the message.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
        return self::$pdo;
    }

    /** @param array<string,mixed> $params */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Insert a row and return its id (assumes an identity `id` column).
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $place = array_map(static fn ($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING id',
            self::ident($table),
            implode(', ', array_map([self::class, 'ident'], $cols)),
            implode(', ', $place)
        );
        $stmt = self::query($sql, self::bindable($data));
        return (int) $stmt->fetchColumn();
    }

    /**
     * Update rows matching $where. Appends `updated_at = NOW()` automatically.
     * @param array<string,mixed> $data  columns to set (do NOT include updated_at)
     * @param array<string,mixed> $where equality conditions (AND-ed)
     */
    public static function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }
        $set = array_map(static fn ($c) => self::ident($c) . ' = :set_' . $c, array_keys($data));
        if (self::hasColumn($table, 'updated_at')) {   // repo convention; skipped if the table lacks it
            $set[] = 'updated_at = NOW()';
        }
        $cond = array_map(static fn ($c) => self::ident($c) . ' = :where_' . $c, array_keys($where));

        $params = [];
        foreach (self::bindable($data) as $k => $v) {
            $params['set_' . ltrim($k, ':')] = $v;
        }
        foreach (self::bindable($where) as $k => $v) {
            $params['where_' . ltrim($k, ':')] = $v;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            self::ident($table),
            implode(', ', $set),
            implode(' AND ', $cond)
        );
        return self::query($sql, $params)->rowCount();
    }

    /** @var array<string,array<string,bool>> table => set of column names */
    private static array $columnCache = [];

    /** Whether $table has $column (cached; current schema only). */
    private static function hasColumn(string $table, string $column): bool
    {
        if (!isset(self::$columnCache[$table])) {
            $stmt = self::connection()->prepare(
                'SELECT column_name FROM information_schema.columns
                  WHERE table_schema = current_schema() AND table_name = :t'
            );
            $stmt->execute(['t' => $table]);
            self::$columnCache[$table] = array_fill_keys($stmt->fetchAll(\PDO::FETCH_COLUMN), true);
        }
        return isset(self::$columnCache[$table][$column]);
    }

    /** Quote an identifier (table/column) — allowlist-validated, not user-derived. */
    private static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException('Invalid SQL identifier: ' . $name);
        }
        return '"' . $name . '"';
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function bindable(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            // Encode arrays/objects as JSON for JSONB columns.
            $out[$k] = (is_array($v) || is_object($v))
                ? json_encode($v, JSON_THROW_ON_ERROR)
                : $v;
        }
        return $out;
    }
}
