<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Module x action permission catalog backing the Admin IAM console
 * (two-pane user list + per-module accordion editor). Only modules actually
 * implemented in this build pass are listed — see the class doc on Roles.
 */
final class PermissionCatalog
{
    /**
     * @return array<string, array{label:string, icon:string, actions: array<string,string>}>
     */
    public static function modules(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'icon' => '📊',
                'actions' => [
                    'dashboard.view' => 'View the enterprise dashboard',
                ],
            ],
            'identity' => [
                'label' => 'Identity Directory',
                'icon' => '🧑',
                'actions' => [
                    'identity.view' => 'View all identities',
                    'identity.view.reports' => 'View identities within reporting chain',
                    'identity.manage.correlate' => 'Manage account correlation / linking',
                ],
            ],
            'account' => [
                'label' => 'Accounts',
                'icon' => '🔑',
                'actions' => [
                    'account.view' => 'View all system accounts',
                    'account.view.reports' => 'View accounts within reporting chain',
                    'account.view.unmatched' => 'View the Unmatched Accounts workspace',
                ],
            ],
            'entitlement' => [
                'label' => 'Entitlements',
                'icon' => '🧩',
                'actions' => [
                    'entitlement.view' => 'View all entitlement assignments',
                    'entitlement.view.reports' => 'View entitlements within reporting chain',
                    'entitlement.view.owned' => 'View entitlements for owned applications',
                ],
            ],
            'matrix' => [
                'label' => 'Enterprise Access Matrix',
                'icon' => '📊',
                'actions' => [
                    'matrix.view.enterprise' => 'Enterprise view (all people/apps)',
                    'matrix.view.supervisor' => 'Supervisor view (reporting chain)',
                    'matrix.view.application.owned' => 'Application view (owned applications)',
                    'matrix.view.privileged' => 'Privileged access view',
                    'matrix.view.exception' => 'Exception view (orphaned/stale/conflicting)',
                ],
            ],
            'application' => [
                'label' => 'Application Catalog',
                'icon' => '🏢',
                'actions' => [
                    'application.view' => 'View all applications',
                    'application.view.owned' => 'View owned applications',
                    'application.manage' => 'Create/edit applications',
                ],
            ],
            'connector' => [
                'label' => 'Integrations',
                'icon' => '🔌',
                'actions' => [
                    'connector.view' => 'View connector configuration & sync history',
                    'connector.manage' => 'Create/edit connector configuration',
                ],
            ],
            'campaign' => [
                'label' => 'Certification Campaigns',
                'icon' => '✅',
                'actions' => [
                    'campaign.view' => 'View all campaigns and their progress',
                    'campaign.manage' => 'Launch, complete, and cancel campaigns',
                    'campaign.review' => 'Act on review items assigned to you',
                ],
            ],
            'remediation' => [
                'label' => 'Remediation Tasks',
                'icon' => '🛠️',
                'actions' => [
                    'remediation.view' => 'View remediation tasks',
                    'remediation.manage' => 'Create, resolve, and dismiss remediation tasks',
                ],
            ],
            'dynamicfield' => [
                'label' => 'Dynamic Fields',
                'icon' => '🧬',
                'actions' => [
                    'dynamicfield.manage' => 'Create/edit/retire custom field definitions',
                ],
            ],
            'savedview' => [
                'label' => 'Saved Views',
                'icon' => '📁',
                'actions' => [
                    'savedview.manage.own' => 'Create/edit personal saved views',
                    'savedview.manage.shared' => 'Publish organization-wide shared views',
                ],
            ],
            'iam' => [
                'label' => 'Access & Security (IAM)',
                'icon' => '🛡️',
                'actions' => [
                    'iam.view' => 'View users, roles & permissions',
                    'iam.manage' => 'Manage users, roles & explicit grants',
                ],
            ],
            'settings' => [
                'label' => 'Platform Settings',
                'icon' => '⚙️',
                'actions' => [
                    'settings.manage' => 'Manage branding & platform settings',
                ],
            ],
            'audit' => [
                'label' => 'Audit',
                'icon' => '📜',
                'actions' => [
                    'audit.view' => 'View audit history',
                ],
            ],
        ];
    }

    /** @return string[] every granular permission key, flattened. */
    public static function allKeys(): array
    {
        $out = [];
        foreach (self::modules() as $m) {
            $out = array_merge($out, array_keys($m['actions']));
        }
        return $out;
    }

    public static function total(): int
    {
        return count(self::allKeys());
    }

    public static function label(string $permissionKey): string
    {
        foreach (self::modules() as $m) {
            if (isset($m['actions'][$permissionKey])) {
                return $m['actions'][$permissionKey];
            }
        }
        return $permissionKey;
    }
}
