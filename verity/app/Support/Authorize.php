<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Authorization policy engine. Enforcement is server-side and MUST be applied
 * on every controller action and every row/column the matrix returns — UI
 * hiding (nav links, disabled buttons) is cosmetic only. See section 25 and
 * acceptance tests #9/#24 of the build directive.
 *
 * Role DEFAULTS (Roles::DEFAULTS) are combined with per-user EXPLICIT grants
 * and denials stored in `user_permission_grant` (layered on top; denials
 * always win). Two scoped families beyond plain role/grant membership are
 * enforced here because Verity has no program/company tenancy to lean on:
 *
 *  - Reporting-chain scope (`*.reports`, `matrix.view.supervisor`): the ctx
 *    must carry `subject_person_id`, verified against the signed-in user's
 *    own `person_id` via a recursive manager-chain query.
 *  - Application-ownership scope (`*.owned`): the ctx must carry
 *    `application_id`, verified against `application.system_owner_person_id`.
 *
 * The $user array shape (produced by Auth):
 *   [
 *     'id' => int, 'email' => string, 'name' => string, 'person_id' => ?int,
 *     'roles' => string[], 'grants' => ['grant'=>string[], 'deny'=>string[]],
 *   ]
 */
final class Authorize
{
    /** Effective granular permissions for a user. @return string[] */
    public static function effectivePermissions(array $user): array
    {
        $perms = array_fill_keys(Roles::permissionsFor($user['roles'] ?? []), true);
        foreach ($user['grants']['grant'] ?? [] as $p) {
            $perms[$p] = true;
        }
        foreach ($user['grants']['deny'] ?? [] as $p) {   // denials win
            unset($perms[$p]);
        }
        return array_keys($perms);
    }

    /**
     * Core check. $ctx may include: subject_person_id (int), application_id (int).
     */
    public static function can(array $user, string $permission, array $ctx = []): bool
    {
        $needed = Roles::expand($permission);

        // Explicit denials are checked FIRST, against the raw deny list —
        // before the wildcard short-circuit below. Checking them only via
        // effectivePermissions()'s unset($perms[$p]) is not enough: for a
        // role holding '*' (enterprise_admin), $perms only ever contains the
        // literal key '*', so unset($perms['settings.manage']) is a silent
        // no-op and the wildcard would grant everything regardless. This is
        // the one documented invariant of this engine ("denials always
        // win") — it must hold for every role, including the wildcard one.
        $denies = $user['grants']['deny'] ?? [];
        foreach ($needed as $p) {
            if (in_array($p, $denies, true)) {
                return false;
            }
        }

        $effective = self::effectivePermissions($user);
        if (in_array('*', $effective, true)) {
            return true;
        }

        foreach ($needed as $p) {
            if (!in_array($p, $effective, true)) {
                return false;
            }
        }

        // Scope enforcement for the "*.reports" family: the signed-in user
        // must actually supervise the subject (or be viewing their own row).
        if (self::isReportsScoped($permission) && array_key_exists('subject_person_id', $ctx)) {
            $subjectId = $ctx['subject_person_id'] !== null ? (int) $ctx['subject_person_id'] : null;
            $selfId = $user['person_id'] ?? null;
            if ($subjectId === null) {
                return true; // list-level check (e.g. "can see the reports workspace at all")
            }
            if ($selfId !== null && (int) $selfId === $subjectId) {
                return true;
            }
            if ($selfId === null || !self::isInReportingChain((int) $selfId, $subjectId)) {
                return false;
            }
        }

        // Scope enforcement for the "*.owned" family: the signed-in user must
        // be the recorded system owner of the application in question.
        if (self::isOwnedScoped($permission) && array_key_exists('application_id', $ctx)) {
            $appId = $ctx['application_id'] !== null ? (int) $ctx['application_id'] : null;
            if ($appId === null) {
                return true; // list-level check
            }
            $selfId = $user['person_id'] ?? null;
            if ($selfId === null || !self::ownsApplication((int) $selfId, $appId)) {
                return false;
            }
        }

        return true;
    }

    /** Enforce or emit 403. Also writes an audit denial. */
    public static function requirePermission(array $user, string $permission, array $ctx = []): void
    {
        if (!self::can($user, $permission, $ctx)) {
            Audit::denied('authz.deny.' . $permission, self::ctxTarget($ctx), $user['id'] ?? null);
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden";
            exit;
        }
    }

    /**
     * All person ids within the signed-in supervisor's reporting chain
     * (direct and indirect reports). Used to scope "Supervisor View" queries.
     * @return int[]
     */
    public static function reportsOf(int $supervisorPersonId): array
    {
        if (!Db::isConfigured()) {
            return [];
        }
        $rows = Db::fetchAll(
            'WITH RECURSIVE reports AS (
                SELECT id FROM person WHERE manager_person_id = :sup
                UNION ALL
                SELECT p.id FROM person p JOIN reports r ON p.manager_person_id = r.id
            )
            SELECT id FROM reports',
            ['sup' => $supervisorPersonId]
        );
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /** Is $subjectPersonId anywhere beneath $supervisorPersonId in the manager chain? */
    public static function isInReportingChain(int $supervisorPersonId, int $subjectPersonId): bool
    {
        if ($supervisorPersonId === $subjectPersonId) {
            return true;
        }
        if (!Db::isConfigured()) {
            return false;
        }
        $row = Db::fetchOne(
            'WITH RECURSIVE chain AS (
                SELECT id, manager_person_id FROM person WHERE id = :subject
                UNION ALL
                SELECT p.id, p.manager_person_id FROM person p JOIN chain c ON p.id = c.manager_person_id
            )
            SELECT EXISTS(SELECT 1 FROM chain WHERE id = :sup) AS in_chain',
            ['subject' => $subjectPersonId, 'sup' => $supervisorPersonId]
        );
        return $row !== null && (bool) $row['in_chain'];
    }

    public static function ownsApplication(int $personId, int $applicationId): bool
    {
        if (!Db::isConfigured()) {
            return false;
        }
        $row = Db::fetchOne(
            'SELECT 1 FROM application WHERE id = :app AND system_owner_person_id = :pid',
            ['app' => $applicationId, 'pid' => $personId]
        );
        return $row !== null;
    }

    private static function isReportsScoped(string $permission): bool
    {
        return str_ends_with($permission, '.reports') || $permission === 'matrix.view.supervisor';
    }

    private static function isOwnedScoped(string $permission): bool
    {
        return str_ends_with($permission, '.owned');
    }

    private static function ctxTarget(array $ctx): ?string
    {
        if (isset($ctx['subject_person_id'])) {
            return 'person#' . $ctx['subject_person_id'];
        }
        if (isset($ctx['application_id'])) {
            return 'application#' . $ctx['application_id'];
        }
        return null;
    }
}
