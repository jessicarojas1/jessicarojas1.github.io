<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Connector catalog (module 5/6). Each connector exposes a capability
 * MANIFEST describing which operations it actually supports — the directive
 * is explicit that a connector must never claim a capability without
 * confirming the source-system behavior. In this build pass the only
 * connector types that exist are 'manual' (no automated discovery at all)
 * and 'csv_import'/'entra_gcc_high_mock' as CATALOG ENTRIES — there is no
 * sync execution engine yet. See docs/GCC_HIGH_INTEGRATION.md,
 * OPEN_ITEMS.md.
 */
final class Connectors
{
    /** The full capability vocabulary (directive section 6). */
    public const CAPABILITY_KEYS = [
        'discover_accounts', 'discover_permissions', 'discover_roles', 'discover_groups',
        'read_account_status', 'read_entitlement_assignments', 'read_activity_metadata',
        'provision_access', 'modify_access', 'revoke_access', 'disable_accounts',
        'verify_source_system_changes',
    ];

    /** Honest default manifest per connector type — everything false unless proven. */
    public static function defaultManifest(string $connectorType): array
    {
        $manifest = array_fill_keys(self::CAPABILITY_KEYS, false);
        switch ($connectorType) {
            case 'entra_gcc_high_mock':
                // Mock only — represents what the real GCC High connector is
                // EXPECTED to support once built and credentialed (Phase 4);
                // no live API calls occur under this connector type today.
                $manifest['discover_accounts'] = true;
                $manifest['discover_roles'] = true;
                $manifest['discover_groups'] = true;
                $manifest['read_account_status'] = true;
                $manifest['read_entitlement_assignments'] = true;
                break;
            case 'csv_import':
                $manifest['discover_accounts'] = true;
                $manifest['read_entitlement_assignments'] = true;
                break;
            case 'manual':
            case 'rest_api_manual':
            default:
                // No automated capability until explicitly configured.
                break;
        }
        return $manifest;
    }

    public static function create(array $data, ?int $actorId): int
    {
        $data['capability_manifest'] = self::defaultManifest((string) ($data['connector_type'] ?? 'manual'));
        $id = Db::insert('connector', self::sanitize($data));
        Audit::log('connector.create', 'connector#' . $id, null, $data, null, $actorId);
        return $id;
    }

    public static function update(int $id, array $data, ?int $actorId): void
    {
        $before = self::get($id);
        Db::update('connector', self::sanitize($data), ['id' => $id]);
        Audit::log('connector.update', 'connector#' . $id, $before, $data, null, $actorId);
    }

    public static function get(int $id): ?array
    {
        return Db::fetchOne('SELECT * FROM connector WHERE id = :id', ['id' => $id]);
    }

    private static function sanitize(array $data): array
    {
        $allowed = [
            'application_id', 'connector_type', 'auth_method', 'credential_reference',
            'sync_frequency', 'default_reviewer_person_id', 'default_review_frequency',
            'remediation_mode', 'capability_manifest', 'connection_health',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
