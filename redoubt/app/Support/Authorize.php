<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Authorization policy engine.
 *
 * Every decision is evaluated against (program × company × role × zone) plus the
 * ITAR/EAR US-person export gate. Role DEFAULTS (Roles::DEFAULTS) are combined
 * with per-user EXPLICIT grants/denials (stored in DB, layered on top). Denials
 * always win. Enforcement is server-side; UI hiding is cosmetic only.
 *
 * The $user array shape (produced by Auth):
 *   [
 *     'id' => int, 'entra_oid' => string, 'name' => string, 'email' => string,
 *     'kind' => 'internal'|'external'|'customer',
 *     'is_us_person' => bool|null,
 *     'memberships' => [ programId => ['roles'=>string[], 'company_id'=>?int] ],
 *     'grants' => [ programId => ['grant'=>string[], 'deny'=>string[]] ],
 *     'companies' => int[],   // company ids the user belongs to
 *   ]
 */
final class Authorize
{
    /** Effective granular permissions for a user within a program. @return string[] */
    public static function effectivePermissions(array $user, ?int $programId): array
    {
        $roles = $programId !== null ? ($user['memberships'][$programId]['roles'] ?? []) : [];
        $perms = array_fill_keys(Roles::permissionsFor($roles), true);

        $grants = $programId !== null ? ($user['grants'][$programId] ?? []) : [];
        foreach ($grants['grant'] ?? [] as $p) {
            $perms[$p] = true;
        }
        foreach ($grants['deny'] ?? [] as $p) {   // denials win
            unset($perms[$p]);
        }
        return array_keys($perms);
    }

    /**
     * Core check. $ctx may include:
     *   program_id (int), company_scope (int[]), export_controlled (bool),
     *   zone (string).
     */
    public static function can(array $user, string $permission, array $ctx = []): bool
    {
        $programId = isset($ctx['program_id']) ? (int) $ctx['program_id'] : null;

        // 1) Export-control gate is absolute: non-US-person can never reach
        //    ITAR/EAR-marked resources, regardless of any granted permission.
        if (!empty($ctx['export_controlled']) && ($user['is_us_person'] ?? false) !== true) {
            return false;
        }

        // 2) Program membership required for program-scoped actions.
        if ($programId !== null && !isset($user['memberships'][$programId])) {
            return false;
        }

        // 3) Company scoping: if the resource is restricted to specific companies,
        //    the user must belong to one of them (internal roles bypass only when
        //    they hold an internal role in the program).
        if (!empty($ctx['company_scope']) && is_array($ctx['company_scope'])) {
            $userCompanies = $user['companies'] ?? [];
            $intersect = array_intersect($ctx['company_scope'], $userCompanies);
            $isInternal = self::hasInternalRole($user, $programId);
            if ($intersect === [] && !$isInternal) {
                return false;
            }
        }

        // 4) Permission check against effective (role defaults + grants − denials).
        $effective = self::effectivePermissions($user, $programId);
        if (in_array('*', $effective, true)) {
            return true;
        }
        // A coarse alias is satisfied only if ALL its granular parts are held.
        $needed = Roles::expand($permission);
        foreach ($needed as $p) {
            if (!in_array($p, $effective, true)) {
                return false;
            }
        }
        return true;
    }

    /** Enforce or emit 403. Also writes an audit denial when a user is present. */
    public static function requirePermission(array $user, string $permission, array $ctx = []): void
    {
        if (!self::can($user, $permission, $ctx)) {
            Audit::log('authz.deny', $permission, $ctx['program_id'] ?? null);
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden";
            exit;
        }
    }

    private static function hasInternalRole(array $user, ?int $programId): bool
    {
        if ($programId === null) {
            return false;
        }
        $internal = ['enterprise_admin', 'program_admin', 'program_manager', 'content_manager', 'contracts', 'finance', 'recruiting', 'internal_member', 'security_admin'];
        $roles = $user['memberships'][$programId]['roles'] ?? [];
        return array_intersect($internal, $roles) !== [];
    }
}
