<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Role → granular-permission defaults, and coarse→granular aliases.
 *
 * Permissions are granular `module.action` strings (per the repo IAM standard),
 * e.g. `announcement.publish`, `taskorder.approve`, `access.review`. Coarse
 * strings (e.g. `announcement.write`) map to arrays of granular strings via
 * $aliases so older callers keep working.
 *
 * Role DEFAULTS live here; explicit per-user grants (stored in DB) are layered on
 * top by Authorize. This is the single source of truth for role capabilities.
 */
final class Roles
{
    /** @var array<string, string[]> role key => granular permission keys */
    public const DEFAULTS = [
        'enterprise_admin' => ['*'],
        'security_admin'   => ['access.view', 'access.grant', 'access.revoke', 'access.review', 'audit.view', 'export.gate.manage'],
        'program_admin'    => ['program.config', 'module.toggle', 'branding.manage', 'integration.config', 'audit.view'],
        'program_manager'  => [
            'announcement.view', 'announcement.create', 'announcement.edit', 'announcement.publish',
            'document.view', 'document.create', 'document.edit', 'document.view.customer', 'document.view.contracts',
            'taskorder.view', 'job.view', 'directory.view', 'audit.view',
            'access.view', 'access.request', 'access.grant', 'access.revoke', 'access.review',
        ],
        'content_manager'  => [
            'announcement.view', 'announcement.create', 'announcement.edit', 'announcement.publish',
            'document.view', 'document.create', 'document.edit',
            'quicklink.manage', 'faq.manage', 'milestone.manage', 'contact.manage',
            'job.view', 'job.create', 'job.edit', 'job.publish',
        ],
        'contracts'        => ['announcement.view', 'document.view', 'document.create', 'document.edit', 'document.view.contracts', 'taskorder.view', 'taskorder.create', 'taskorder.edit', 'taskorder.publish', 'taskorder.approve'],
        'finance'          => ['announcement.view', 'document.view', 'finance.view', 'taskorder.view'],
        'recruiting'       => ['announcement.view', 'job.view', 'job.create', 'job.edit', 'job.publish'],
        'internal_member'  => ['announcement.view', 'document.view', 'taskorder.view', 'job.view', 'directory.view'],
        'sub_admin'        => ['announcement.view', 'document.view', 'taskorder.view', 'job.view', 'directory.view', 'company.roster.manage', 'access.request'],
        'sub_member'       => ['announcement.view', 'document.view', 'taskorder.view', 'job.view', 'directory.view'],
        'customer_cor'     => ['announcement.view.customer', 'document.view.customer'],
    ];

    /** @var array<string, string[]> coarse alias => granular keys */
    public const ALIASES = [
        'announcement.write' => ['announcement.create', 'announcement.edit', 'announcement.publish'],
        'document.write'     => ['document.create', 'document.edit'],
        'taskorder.write'    => ['taskorder.create', 'taskorder.edit', 'taskorder.publish'],
        'job.write'          => ['job.create', 'job.edit', 'job.publish'],
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
}
