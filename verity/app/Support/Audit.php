<?php

declare(strict_types=1);

namespace Verity\Support;

use Throwable;

/**
 * Append-only governance audit trail. Every material governance activity
 * (section 18 of the Verity build directive) must produce a traceable record:
 * authentication, administrative actions, correlation/link changes, review
 * decisions, remediation actions, and policy changes.
 *
 * Corrections must never overwrite a prior record — callers log an additional
 * event rather than mutating one. In production, grant the app's DB role only
 * INSERT/SELECT on audit_event (no UPDATE/DELETE) so this is enforced at the
 * database layer too, not just by convention.
 *
 * If the database is not configured, events are written to the PHP error log
 * rather than silently dropped.
 */
final class Audit
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function log(
        string $action,
        ?string $target = null,
        ?array $before = null,
        ?array $after = null,
        ?string $justification = null,
        ?int $actorId = null
    ): void {
        $actorId ??= self::currentActorId();
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $correlationId = self::correlationId();

        if (!Db::isConfigured()) {
            error_log(sprintf(
                '[AUDIT] action=%s target=%s actor=%s ip=%s correlation=%s',
                $action,
                $target ?? '-',
                $actorId ?? '-',
                $ip ?? '-',
                $correlationId
            ));
            return;
        }

        try {
            Db::insert('audit_event', [
                'actor_id'       => $actorId,
                'action'         => $action,
                'target'         => $target,
                'before_value'   => $before,
                'after_value'    => $after,
                'justification'  => $justification,
                'correlation_id' => $correlationId,
                'ip'             => $ip,
                'result'         => 'success',
            ]);
        } catch (Throwable $e) {
            // Never let auditing break the request, but make the failure visible.
            error_log('[AUDIT-FAIL] ' . $action . ' : ' . $e->getMessage());
        }
    }

    public static function denied(string $action, ?string $target = null, ?int $actorId = null): void
    {
        $actorId ??= self::currentActorId();
        if (!Db::isConfigured()) {
            error_log(sprintf('[AUDIT-DENY] action=%s target=%s actor=%s', $action, $target ?? '-', $actorId ?? '-'));
            return;
        }
        try {
            Db::insert('audit_event', [
                'actor_id'       => $actorId,
                'action'         => $action,
                'target'         => $target,
                'correlation_id' => self::correlationId(),
                'ip'             => $_SERVER['REMOTE_ADDR'] ?? null,
                'result'         => 'denied',
            ]);
        } catch (Throwable $e) {
            error_log('[AUDIT-FAIL] ' . $action . ' : ' . $e->getMessage());
        }
    }

    /** Stable per-request correlation id so related audit rows can be grouped. */
    private static function correlationId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = bin2hex(random_bytes(8));
        }
        return $id;
    }

    private static function currentActorId(): ?int
    {
        $u = Auth::user();
        return $u['id'] ?? null;
    }
}
