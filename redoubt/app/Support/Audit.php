<?php

declare(strict_types=1);

namespace Redoubt\Support;

use Throwable;

/**
 * Append-only audit logging. Records access, admin, publish, provision, and
 * revoke events. In production, grant the app DB role only INSERT/SELECT on
 * audit_event so records cannot be mutated or deleted.
 *
 * If the database is not configured (e.g., discovery mode), events are written to
 * the PHP error log rather than silently dropped.
 */
final class Audit
{
    public static function log(string $action, ?string $target = null, ?int $programId = null, ?int $actorId = null): void
    {
        $actorId ??= self::currentActorId();
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        if (!Db::isConfigured()) {
            error_log(sprintf('[AUDIT] action=%s target=%s program=%s actor=%s ip=%s',
                $action, $target ?? '-', $programId ?? '-', $actorId ?? '-', $ip ?? '-'));
            return;
        }

        try {
            Db::insert('audit_event', [
                'program_id' => $programId,
                'actor_id'   => $actorId,
                'action'     => $action,
                'target'     => $target,
                'ip'         => $ip,
            ]);
        } catch (Throwable $e) {
            // Never let auditing break the request, but make the failure visible.
            error_log('[AUDIT-FAIL] ' . $action . ' : ' . $e->getMessage());
        }
    }

    private static function currentActorId(): ?int
    {
        $u = Auth::user();
        return $u['id'] ?? null;
    }
}
