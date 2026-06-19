<?php
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
                // Log detail server-side; throw a catchable, operator-safe error.
                // Never die() mid-output — that bypasses callers' try/catch and the
                // front controller's exception handler, and emits a raw JSON fragment.
                // Callers that can degrade (e.g. Security::validatePasswordPolicy)
                // catch this; the front controller renders a clean error otherwise.
                error_log('DB connection failed: ' . $e->getMessage());
                throw new RuntimeException('Database unavailable', 503, $e);
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

    /**
     * Bind the current connection to a tenant by setting the `aegis.tenant_id`
     * session GUC that Row-Level Security policies filter on (see
     * MULTI_TENANCY.md / database/tenancy/rls_template.sql). Call once per request
     * after authentication. Uses set_config (parameterized — never string
     * interpolation) so the value can't be an injection vector. Currently inert
     * until per-table RLS is enabled in the tenancy rollout.
     */
    public static function setTenant(int $tenantId): void {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('tenant id must be a positive integer');
        }
        self::query("SELECT set_config('aegis.tenant_id', ?, false)", [(string)$tenantId]);
    }

    /** The tenant bound to the current connection, or null when unset. */
    public static function currentTenant(): ?int {
        $row = self::fetchOne("SELECT current_setting('aegis.tenant_id', true) AS t");
        $t = $row['t'] ?? '';
        return ($t === '' || $t === null) ? null : (int)$t;
    }

    /** Clear the tenant binding on the current connection. */
    public static function clearTenant(): void {
        self::query("SELECT set_config('aegis.tenant_id', '', false)");
    }

    public static function tableExists(string $table): bool {
        $row = self::fetchOne(
            "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_name = ? AND table_schema = 'aegis'",
            [$table]
        );
        return (int)($row['cnt'] ?? 0) > 0;
    }
}
