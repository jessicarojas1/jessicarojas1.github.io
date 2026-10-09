<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Role -> granular-permission defaults, and coarse->granular aliases.
 *
 * Permissions are granular `module.action` strings. Coarse strings map to
 * arrays of granular strings via ALIASES so older/simpler callers keep
 * working without being able to silently grant more than intended.
 *
 * Role DEFAULTS live here; explicit per-user grants/denials (stored in DB,
 * `user_permission_grant`) are layered on top by Authorize — denials win.
 *
 * Scope: only permissions for modules actually implemented in this build pass
 * (Dashboard, Identity Directory, Access Inventory/Matrix, Application
 * Catalog read model, Dynamic Fields, Saved Views, Admin IAM,
 * Settings/Branding, Audit) are defined here. Campaign/workflow/remediation/
 * risk/SoD/reporting permissions will be added when those modules land — see
 * OPEN_ITEMS.md.
 *
 * Every role is granted `dashboard.view` by default (including
 * `enterprise_admin` via its wildcard) so the dashboard remains every
 * authenticated user's landing page — this is an intentional design choice,
 * not an oversight, and is still enforced through this same engine (not a
 * bypass) so an explicit per-user deny on `dashboard.view` works correctly.
 */
final class Roles
{
    /** @var array<string, string[]> role key => granular permission keys */
    public const DEFAULTS = [
        // Full administrative authority. Still subject to the independent
        // controls on audit-record mutation (there are none — audit is
        // insert/select only, even for this role) per section 25.
        'enterprise_admin' => ['*'],

        // Cybersecurity / compliance reviewer: broad read, governance config,
        // no account correlation changes.
        'security_admin' => [
            'dashboard.view',
            'identity.view', 'identity.manage.correlate',
            'account.view', 'account.view.unmatched',
            'entitlement.view',
            'matrix.view.enterprise', 'matrix.view.privileged', 'matrix.view.exception',
            'application.view', 'application.manage',
            'connector.view', 'connector.manage',
            'dynamicfield.manage',
            'savedview.manage.own', 'savedview.manage.shared',
            'iam.view', 'iam.manage',
            'audit.view',
            'settings.manage',
        ],

        // Line manager: scoped to their own reporting chain via Authorize's
        // reporting-chain check (ctx['subject_person_id']) — never a bypass.
        'supervisor' => [
            'dashboard.view',
            'identity.view.reports',
            'account.view.reports',
            'entitlement.view.reports',
            'matrix.view.supervisor',
            'savedview.manage.own',
        ],

        // Application/system owner: scoped to applications where they are
        // recorded as system_owner_person_id — see Authorize::ownsApplication().
        'system_owner' => [
            'dashboard.view',
            'application.view.owned',
            'matrix.view.application.owned',
            'entitlement.view.owned',
            'savedview.manage.own',
        ],

        // Read-only enterprise visibility for internal/external auditors.
        'auditor' => [
            'dashboard.view',
            'identity.view',
            'account.view',
            'entitlement.view',
            'matrix.view.enterprise',
            'audit.view',
            'savedview.manage.own',
        ],
    ];

    /** @var array<string, string[]> coarse alias => granular keys */
    public const ALIASES = [
        'identity.manage' => ['identity.manage.correlate'],
        'matrix.view'      => ['matrix.view.enterprise'],
        'savedview.manage' => ['savedview.manage.own'],
    ];

    /** Expand a coarse permission to granular keys (or return it unchanged). */
    public static function expand(string $permission): array
    {
        return self::ALIASES[$permission] ?? [$permission];
    }

    /**
     * Effective granular permissions for a set of role keys.
     * @param string[] $roleKeys
     * @return string[]
     */
    public static function permissionsFor(array $roleKeys): array
    {
        $perms = [];
        foreach ($roleKeys as $key) {
            foreach (self::DEFAULTS[$key] ?? [] as $p) {
                $perms[$p] = true;
            }
        }
        return array_keys($perms);
    }

    /** Human label for a role key, for the IAM console and user admin screens. */
    public static function label(string $roleKey): string
    {
        return match ($roleKey) {
            'enterprise_admin' => 'Enterprise Administrator',
            'security_admin'   => 'Security / Compliance Admin',
            'supervisor'        => 'Supervisor',
            'system_owner'      => 'System Owner',
            'auditor'           => 'Auditor',
            default             => $roleKey,
        };
    }

    /** @return string[] all known role keys, in display order. */
    public static function all(): array
    {
        return array_keys(self::DEFAULTS);
    }
}
