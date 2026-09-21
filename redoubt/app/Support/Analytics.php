<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Program analytics / health — derived from the append-only audit trail and
 * content tables. Everything is program-scoped; the caller must already hold
 * audit.view (checked by the controller). No external services; renders offline.
 */
final class Analytics
{
    /** @return array<string,mixed> */
    public static function forProgram(int $programId): array
    {
        return [
            'activity'  => self::activity($programId, 14),
            'actions'   => self::topActions($programId),
            'adoption'  => self::adoption($programId),
            'content'   => self::content($programId),
        ];
    }

    /** Events per day for the last $days days (zero-filled). @return array<int,array{date:string,count:int}> */
    public static function activity(int $programId, int $days): array
    {
        $rows = Db::fetchAll(
            "SELECT to_char(at::date, 'YYYY-MM-DD') d, count(*) c
               FROM audit_event
              WHERE program_id = :p AND at >= (CURRENT_DATE - (:days || ' days')::interval)
              GROUP BY 1",
            ['p' => $programId, 'days' => $days - 1]
        );
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['d']] = (int) $r['c'];
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out[] = ['date' => $d, 'count' => $byDay[$d] ?? 0];
        }
        return $out;
    }

    /** @return array<int,array{action:string,count:int}> */
    public static function topActions(int $programId): array
    {
        return array_map(
            static fn ($r) => ['action' => (string) $r['action'], 'count' => (int) $r['c']],
            Db::fetchAll(
                "SELECT action, count(*) c FROM audit_event
                  WHERE program_id = :p AND at >= now() - interval '30 days'
                  GROUP BY action ORDER BY c DESC LIMIT 8",
                ['p' => $programId]
            )
        );
    }

    /** @return array{members:int,active7:int,percent:int} */
    public static function adoption(int $programId): array
    {
        $members = (int) (Db::fetchOne(
            'SELECT count(DISTINCT user_id) c FROM program_membership WHERE program_id = :p',
            ['p' => $programId]
        )['c'] ?? 0);
        $active = (int) (Db::fetchOne(
            "SELECT count(DISTINCT actor_id) c FROM audit_event
              WHERE program_id = :p AND actor_id IS NOT NULL AND at >= now() - interval '7 days'",
            ['p' => $programId]
        )['c'] ?? 0);
        return [
            'members' => $members,
            'active7' => $active,
            'percent' => $members > 0 ? (int) round(100 * min($active, $members) / $members) : 0,
        ];
    }

    /** @return array<string,int> */
    public static function content(int $programId): array
    {
        $count = static fn (string $table): int => (int) (Db::fetchOne(
            "SELECT count(*) c FROM $table WHERE program_id = :p",
            ['p' => $programId]
        )['c'] ?? 0);
        return [
            'announcements' => $count('announcement'),
            'documents'     => $count('document_ref'),
            'task_orders'   => $count('task_order'),
            'jobs'          => $count('job_requisition'),
            'milestones'    => $count('milestone'),
            'audit_events'  => $count('audit_event'),
        ];
    }
}
