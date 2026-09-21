<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Read side of the append-only audit trail (compliance viewer). Program-scoped;
 * the controller enforces audit.view. Supports an action filter and paging.
 */
final class AuditLog
{
    public const PAGE = 50;

    /** @return array<int,array<string,mixed>> */
    public static function forProgram(int $programId, ?string $action, int $offset): array
    {
        $sql = 'SELECT ae.id, ae.action, ae.target, host(ae.ip) AS ip, to_char(ae.at, \'YYYY-MM-DD HH24:MI:SS\') AS at,
                       COALESCE(u.display_name, u.email) AS actor
                  FROM audit_event ae
                  LEFT JOIN app_user u ON u.id = ae.actor_id
                 WHERE ae.program_id = :p';
        $params = ['p' => $programId];
        if ($action !== null && $action !== '') {
            $sql .= ' AND ae.action = :a';
            $params['a'] = $action;
        }
        $sql .= ' ORDER BY ae.at DESC, ae.id DESC LIMIT ' . self::PAGE . ' OFFSET :o';
        $params['o'] = max(0, $offset);
        return Db::fetchAll($sql, $params);
    }

    public static function count(int $programId, ?string $action): int
    {
        $sql = 'SELECT count(*) c FROM audit_event WHERE program_id = :p';
        $params = ['p' => $programId];
        if ($action !== null && $action !== '') {
            $sql .= ' AND action = :a';
            $params['a'] = $action;
        }
        return (int) (Db::fetchOne($sql, $params)['c'] ?? 0);
    }

    /** Distinct action types seen in this program (for the filter). @return string[] */
    public static function actions(int $programId): array
    {
        return array_map(
            static fn ($r) => (string) $r['action'],
            Db::fetchAll('SELECT DISTINCT action FROM audit_event WHERE program_id = :p ORDER BY action', ['p' => $programId])
        );
    }
}
