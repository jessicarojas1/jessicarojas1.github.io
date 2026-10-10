<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * CSV import connector sync execution — the first real connector sync
 * engine in this build (every other connector type is a catalog/config
 * entry only; see Connectors.php). Strictly bounded by
 * Connectors::defaultManifest('csv_import'): `discover_accounts` and
 * `read_entitlement_assignments` only.
 *
 * This is an ADD/UPDATE-ONLY importer, by design, not an oversight: a row
 * already in the database that's simply absent from THIS run's file is
 * left exactly as it is — never disabled, never unassigned. csv_import's
 * manifest does not claim `disable_accounts`/`revoke_access`/
 * `modify_access`, and this engine must never do something its own
 * manifest says it can't — a connector claiming one capability while
 * silently exercising another is exactly the dishonesty
 * Connectors::defaultManifest()'s doc comment warns against. If a future
 * "full reconciliation" (CSV-is-the-complete-truth, disable what's
 * missing) mode is ever wanted, that is a new, explicit, separately
 * manifested capability — not a quiet change to this method.
 *
 * Idempotent by construction: every write is an `INSERT ... ON CONFLICT`
 * keyed on each table's existing UNIQUE constraint, so re-running the exact
 * same file twice updates the same rows rather than duplicating them.
 */
final class CsvImport
{
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    public const MAX_DATA_ROWS = 50000;
    public const REQUIRED_HEADER = 'external_account_id';
    public const ALLOWED_ACCOUNT_TYPES = ['standard', 'privileged', 'service', 'shared'];
    public const ALLOWED_STATUSES = ['enabled', 'disabled'];
    /** Expected header columns, for the UI hint and docs — only external_account_id is required. */
    public const EXPECTED_COLUMNS = ['external_account_id', 'username', 'account_type', 'status', 'entitlements'];

    /**
     * @return array{imported_accounts:int, imported_entitlements:int, failure_count:int, error_summary:?string, status:string}
     */
    public static function run(int $connectorId, string $csvContents, ?int $actorId): array
    {
        $connector = Connectors::get($connectorId);
        if ($connector === null || $connector['connector_type'] !== 'csv_import') {
            throw new \RuntimeException('Connector is not a CSV import connector.');
        }
        $applicationId = (int) $connector['application_id'];
        $jobId = Db::insert('connector_sync_job', [
            'connector_id' => $connectorId, 'status' => 'running', 'triggered_by' => 'manual',
        ]);

        // Strip a leading UTF-8 BOM — common in CSVs exported from Excel,
        // and otherwise corrupts the first header column's name silently.
        $csvContents = preg_replace('/^\xEF\xBB\xBF/', '', $csvContents) ?? $csvContents;
        $lines = preg_split('/\r\n|\r|\n/', $csvContents);

        $header = null;
        $importedAccounts = 0;
        $importedEntitlements = 0;
        $failures = 0;
        $errors = [];
        $dataRowNum = 0;
        $lineNum = 0;
        $truncated = false;

        foreach ($lines as $line) {
            $lineNum++;
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line, ',', '"', '\\');

            if ($header === null) {
                $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $fields);
                if (!in_array(self::REQUIRED_HEADER, $header, true)) {
                    $summary = 'Header row is missing the required "external_account_id" column.';
                    self::finishJob($jobId, 'failed', 0, 0, 1, $summary);
                    Audit::log('connector.sync', 'connector#' . $connectorId, null, ['status' => 'failed', 'error' => $summary], null, $actorId);
                    return ['imported_accounts' => 0, 'imported_entitlements' => 0, 'failure_count' => 1, 'error_summary' => $summary, 'status' => 'failed'];
                }
                continue;
            }

            $dataRowNum++;
            if ($dataRowNum > self::MAX_DATA_ROWS) {
                $truncated = true;
                break;
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = $fields[$i] ?? null;
            }

            try {
                $result = self::importRow($applicationId, $connectorId, $row);
                $importedAccounts++;
                $importedEntitlements += $result['entitlements_assigned'];
            } catch (\InvalidArgumentException $e) {
                $failures++;
                if (count($errors) < 20) {
                    $errors[] = "Line {$lineNum}: " . $e->getMessage();
                }
            }
        }

        if ($truncated) {
            $failures++;
            $errors[] = 'File exceeds the ' . number_format(self::MAX_DATA_ROWS) . '-row limit — stopped after that many rows; split the file and import the rest separately.';
        }

        $status = $failures === 0 ? 'succeeded' : ($importedAccounts > 0 ? 'partial' : 'failed');
        $errorSummary = $errors === [] ? null : implode(' | ', $errors) . ($failures > count($errors) ? ' | +' . ($failures - count($errors)) . ' more row(s) failed' : '');

        self::finishJob($jobId, $status, $importedAccounts, $importedEntitlements, $failures, $errorSummary);
        Connectors::update($connectorId, [
            'connection_health' => $status === 'succeeded' ? 'healthy' : ($status === 'failed' ? 'failed' : 'degraded'),
        ], $actorId);
        Audit::log('connector.sync', 'connector#' . $connectorId, null, [
            'status' => $status, 'imported_accounts' => $importedAccounts,
            'imported_entitlements' => $importedEntitlements, 'failure_count' => $failures,
        ], null, $actorId);

        return [
            'imported_accounts' => $importedAccounts, 'imported_entitlements' => $importedEntitlements,
            'failure_count' => $failures, 'error_summary' => $errorSummary, 'status' => $status,
        ];
    }

    /** @return array{account_id:int, entitlements_assigned:int} */
    private static function importRow(int $applicationId, int $connectorId, array $row): array
    {
        $externalId = trim((string) ($row['external_account_id'] ?? ''));
        if ($externalId === '') {
            throw new \InvalidArgumentException('external_account_id is required.');
        }
        $username = trim((string) ($row['username'] ?? '')) ?: null;
        $accountType = trim((string) ($row['account_type'] ?? '')) ?: 'standard';
        if (!in_array($accountType, self::ALLOWED_ACCOUNT_TYPES, true)) {
            throw new \InvalidArgumentException('invalid account_type "' . $accountType . '" — allowed: ' . implode(', ', self::ALLOWED_ACCOUNT_TYPES));
        }
        $status = trim((string) ($row['status'] ?? '')) ?: 'enabled';
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException('invalid status "' . $status . '" — allowed: ' . implode(', ', self::ALLOWED_STATUSES));
        }

        $accountRow = Db::fetchOne(
            'INSERT INTO system_account (application_id, connector_id, external_account_id, username, account_type, status, source, last_synced_at)
             VALUES (:aid, :cid, :ext, :uname, :atype, :status, \'csv_import\', NOW())
             ON CONFLICT (application_id, external_account_id) DO UPDATE SET
                connector_id = EXCLUDED.connector_id, username = EXCLUDED.username,
                account_type = EXCLUDED.account_type, status = EXCLUDED.status,
                last_synced_at = NOW(), updated_at = NOW()
             RETURNING id',
            ['aid' => $applicationId, 'cid' => $connectorId, 'ext' => $externalId, 'uname' => $username, 'atype' => $accountType, 'status' => $status]
        );
        $accountId = (int) $accountRow['id'];

        $assigned = 0;
        $entitlementNames = trim((string) ($row['entitlements'] ?? ''));
        if ($entitlementNames !== '') {
            foreach (array_filter(array_map('trim', explode('|', $entitlementNames)), static fn ($n) => $n !== '') as $name) {
                $entRow = Db::fetchOne(
                    'INSERT INTO entitlement (application_id, name, entitlement_type, source)
                     VALUES (:aid, :name, \'role\', \'connector\')
                     ON CONFLICT (application_id, name, entitlement_type) DO UPDATE SET updated_at = NOW()
                     RETURNING id',
                    ['aid' => $applicationId, 'name' => $name]
                );
                // Never overwrites an existing assignment's source/dates —
                // a prior manual grant of the same account+entitlement is
                // left exactly as it was found.
                Db::query(
                    'INSERT INTO entitlement_assignment (system_account_id, entitlement_id, assignment_type, source)
                     VALUES (:acc, :ent, \'direct\', \'connector\')
                     ON CONFLICT (system_account_id, entitlement_id, assignment_type) DO NOTHING',
                    ['acc' => $accountId, 'ent' => (int) $entRow['id']]
                );
                $assigned++;
            }
        }

        return ['account_id' => $accountId, 'entitlements_assigned' => $assigned];
    }

    private static function finishJob(int $jobId, string $status, int $accounts, int $entitlements, int $failures, ?string $errorSummary): void
    {
        Db::query(
            'UPDATE connector_sync_job SET completed_at = NOW(), status = :status,
                imported_accounts = :accounts, imported_entitlements = :entitlements,
                failure_count = :failures, error_summary = :summary
             WHERE id = :id',
            ['id' => $jobId, 'status' => $status, 'accounts' => $accounts, 'entitlements' => $entitlements, 'failures' => $failures, 'summary' => $errorSummary]
        );
    }
}
