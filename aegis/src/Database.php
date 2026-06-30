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
        $data = self::applyTenantStamp($table, $data);
        $q = fn(string $id) => '"' . str_replace('"', '', $id) . '"';
        $cols = implode(', ', array_map($q, array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt = self::query("INSERT INTO {$q($table)} ({$cols}) VALUES ({$placeholders}) RETURNING id", array_values($data));
        return (int) $stmt->fetchColumn();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $q = fn(string $id) => '"' . str_replace('"', '', $id) . '"';
        $sets = implode(', ', array_map(fn($k) => "{$q($k)} = ?", array_keys($data)));
        // Auto-stamp updated_at — but defensively skip it if the caller already
        // provided one, otherwise Postgres rejects the duplicate SET assignment.
        $autoTs = array_key_exists('updated_at', $data) ? '' : ', updated_at = NOW()';
        $stmt = self::query("UPDATE {$q($table)} SET {$sets}{$autoTs} WHERE {$where}", [...array_values($data), ...$whereParams]);
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
     * interpolation) so the value can't be an injection vector. The per-table
     * tenant_isolation policies (migration 028) read this GUC; with it unset the
     * policies are permissive, so this stays inert for single-tenant deployments.
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

    /**
     * Tenant-owned tables that carry a tenant_id column and are auto-stamped on
     * INSERT. Two tiers, all subject to the tenant_isolation RLS policy:
     *   - Primary entities (migration 027).
     *   - Child/detail + link tables hanging off those entities (migration 029).
     * Keep in sync with migrations 027/029 and schema.sql — the integration test
     * asserts this list matches the tables that physically carry tenant_id.
     */
    private const TENANT_TABLES = [
        // Primary entities (migration 027)
        'users','risks','policies','audits','audit_findings','compliance_packages',
        'compliance_objectives','control_implementations','incidents','issues',
        'vendors','assets','threats','poam_items','kris','documents','bcp_plans',
        'privacy_records','awareness_programs',
        'grc_projects','cui_inventory','odp_entries','ssp_plans','questionnaires',
        // Child / detail / link tables (migration 029)
        'audit_schedules','audit_items','finding_updates',
        'policy_versions','policy_mappings','policy_reviews','policy_attestations',
        'policy_attestation_campaigns',
        'risk_score_history','risk_control_links','risk_related_links','risk_treatments',
        'risk_acceptances','risk_bowtie_causes','risk_bowtie_consequences',
        'risk_bowtie_barriers','risk_scenarios','risk_reviews','risk_review_items',
        'risk_exceptions','treatment_plans','treatment_milestones',
        'incident_updates','incident_sla_events','issue_updates',
        'vendor_assessments','vendor_contracts','vendor_certifications',
        'asset_risk_links','threat_risk_links',
        'poam_milestones','kri_values','document_versions',
        'bcp_plan_sections','bcp_exercises',
        'data_subject_requests','awareness_assignments',
        'ssp_packages','ssp_control_statements',
        'questionnaire_questions','questionnaire_assignments','questionnaire_responses',
        'questionnaire_answers',
        'grc_project_tasks','grc_project_links',
        'control_mappings','control_tests','raci_assignments','shared_responsibility',
        'evidence','evidence_files','evidence_downloads',
        'finding_risk_links',
    ];

    /** The tenant-owned tables (write-path stamping + RLS coverage). */
    public static function tenantTables(): array {
        return self::TENANT_TABLES;
    }

    /** Application-level tenant context for write-path stamping (PHP-side, no DB). */
    private static ?int $tenantContext = null;

    /** Set the tenant whose id is stamped onto new rows in tenant-owned tables. */
    public static function useTenant(?int $tenantId): void {
        self::$tenantContext = ($tenantId !== null && $tenantId >= 1) ? $tenantId : null;
    }

    /** The current write-path tenant context, or null. */
    public static function tenantContext(): ?int {
        return self::$tenantContext;
    }

    /**
     * Auto-stamp tenant_id on inserts into tenant-owned tables. Pure (no DB) and
     * conservative: only stamps when a tenant context is set, the table is
     * tenant-owned, and the caller didn't already provide tenant_id. When no
     * context is set the row falls back to the column DEFAULT (tenant 1), so this
     * is safe even before the request lifecycle binds a tenant. Unit-tested.
     */
    public static function applyTenantStamp(string $table, array $data): array {
        if (self::$tenantContext !== null
            && in_array($table, self::TENANT_TABLES, true)
            && !array_key_exists('tenant_id', $data)) {
            $data['tenant_id'] = self::$tenantContext;
        }
        return $data;
    }

    public static function tableExists(string $table): bool {
        $row = self::fetchOne(
            "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_name = ? AND table_schema = 'aegis'",
            [$table]
        );
        return (int)($row['cnt'] ?? 0) > 0;
    }
}
