<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Leadership portfolio roll-up: a bird's-eye view across every program the user
 * belongs to. Lightweight per-program counts; pending-access is shown only where
 * the user may view access.
 */
final class Portfolio
{
    /** @return array<int,array<string,mixed>> one summary row per program */
    public static function forUser(array $user): array
    {
        $out = [];
        foreach (array_keys($user['memberships'] ?? []) as $pid) {
            $pid = (int) $pid;
            $prog = Db::fetchOne('SELECT name, customer, contract_number, status FROM program WHERE id = :id', ['id' => $pid]);
            if ($prog === null) {
                continue;
            }
            $c = static fn (string $sql): int => (int) (Db::fetchOne($sql, ['p' => $pid])['c'] ?? 0);
            $out[] = [
                'id'          => $pid,
                'name'        => $prog['name'],
                'customer'    => $prog['customer'],
                'contract'    => $prog['contract_number'],
                'status'      => $prog['status'],
                'members'     => $c('SELECT count(DISTINCT user_id) c FROM program_membership WHERE program_id = :p'),
                'open_jobs'   => $c("SELECT count(*) c FROM job_requisition WHERE program_id = :p AND status = 'open'"),
                'milestones'  => $c('SELECT count(*) c FROM milestone WHERE program_id = :p AND due_date >= CURRENT_DATE'),
                'documents'   => $c('SELECT count(*) c FROM document_ref WHERE program_id = :p'),
                'activity7'   => $c("SELECT count(*) c FROM audit_event WHERE program_id = :p AND at >= now() - interval '7 days'"),
                'pending'     => Authorize::can($user, 'access.view', ['program_id' => $pid])
                    ? $c("SELECT count(*) c FROM access_request WHERE program_id = :p AND status = 'pending'")
                    : null,
            ];
        }
        usort($out, static fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    /** @return array<string,int> portfolio totals */
    public static function totals(array $rows): array
    {
        $t = ['programs' => count($rows), 'members' => 0, 'open_jobs' => 0, 'milestones' => 0, 'pending' => 0];
        foreach ($rows as $r) {
            $t['members']   += (int) $r['members'];
            $t['open_jobs'] += (int) $r['open_jobs'];
            $t['milestones'] += (int) $r['milestones'];
            $t['pending']   += (int) ($r['pending'] ?? 0);
        }
        return $t;
    }
}
