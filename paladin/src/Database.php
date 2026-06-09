<?php
/**
 * Thin PDO wrapper for the PALADIN platform.
 * Provides parameterized helpers only — never concatenate user input into SQL.
 */
class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            require_once __DIR__ . '/../config/database.php';
            $cfg = getDatabaseConfig();
            $dsn = getDSN();
            try {
                self::$instance = new PDO($dsn, $cfg['user'], $cfg['password'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                error_log('DB connection failed: ' . $e->getMessage());
                http_response_code(503);
                die(json_encode(['error' => 'Database unavailable']));
            }
        }
        return self::$instance;
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchOne(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $q = fn(string $id) => '"' . str_replace('"', '', $id) . '"';
        $cols = implode(', ', array_map($q, array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt = self::query("INSERT INTO {$q($table)} ({$cols}) VALUES ({$placeholders}) RETURNING id", array_values($data));
        return (int) $stmt->fetchColumn();
    }

    /**
     * Update helper — automatically appends updated_at = NOW().
     * Never include updated_at in the $data array.
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $q = fn(string $id) => '"' . str_replace('"', '', $id) . '"';
        $sets = implode(', ', array_map(fn($k) => "{$q($k)} = ?", array_keys($data)));
        $stmt = self::query("UPDATE {$q($table)} SET {$sets}, updated_at = NOW() WHERE {$where}", [...array_values($data), ...$whereParams]);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): string {
        return self::getInstance()->lastInsertId();
    }

    public static function beginTransaction(): void { self::getInstance()->beginTransaction(); }
    public static function commit(): void          { self::getInstance()->commit(); }
    public static function rollback(): void        { self::getInstance()->rollBack(); }

    public static function tableExists(string $table): bool {
        $row = self::fetchOne(
            "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_name = ? AND table_schema = 'paladin'",
            [$table]
        );
        return (int)($row['cnt'] ?? 0) > 0;
    }
}
