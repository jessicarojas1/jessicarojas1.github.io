<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Job Requisitions module service (Annex H). Program-scoped with three targeting
 * modes that combine: program-wide, by company, and by contract (task order).
 * Non-editors see only posted (open), unexpired reqs they are targeted by;
 * editors (job.edit) see all. Posting emits a signed webhook + audit.
 *
 * Targeting fields on a row:
 *   audience         JSONB  all/internal/customer/role:<key>
 *   company_scope    JSONB  company ids
 *   task_order_scope JSONB  task_order ids (a user is targeted if their company
 *                           is attached to one of those task orders)
 * Program-wide = audience contains 'all', OR all three are empty.
 *
 * Optional $filter for listForUser: ['scope'=>'program'|'targeted',
 *   'company_id'=>int, 'task_order_id'=>int].
 */
final class Jobs
{
    public const STATUSES = ['draft', 'open', 'filled', 'closed'];

    private const INTERNAL_ROLES = [
        'enterprise_admin', 'program_admin', 'program_manager', 'content_manager',
        'contracts', 'finance', 'recruiting', 'internal_member', 'security_admin',
    ];

    public static function listForUser(array $user, int $programId, array $filter = []): array
    {
        $rows = Db::fetchAll('SELECT * FROM job_requisition WHERE program_id = :p ORDER BY id DESC', ['p' => $programId]);
        $isEditor = Authorize::can($user, 'job.edit', ['program_id' => $programId]);
        $toMap = self::taskOrderCompanyMap($programId);
        $out = [];
        foreach ($rows as $r) {
            $aud = self::arr($r['audience'] ?? null);
            $co  = self::intArr($r['company_scope'] ?? null);
            $to  = self::intArr($r['task_order_scope'] ?? null);
            $programWide = in_array('all', $aud, true) || ($aud === [] && $co === [] && $to === []);

            if (!$isEditor) {
                if (($r['status'] ?? '') !== 'open') {
                    continue;
                }
                $exp = $r['expire_at'] ? strtotime((string) $r['expire_at']) : null;
                if ($exp !== null && $exp <= time()) {
                    continue;
                }
                if (!self::visibleTo($user, $programId, $programWide, $aud, $co, $to, $toMap)) {
                    continue;
                }
            }

            // Filters (applied on top of visibility)
            if (($filter['scope'] ?? '') === 'program' && !$programWide) {
                continue;
            }
            if (($filter['scope'] ?? '') === 'targeted' && $programWide) {
                continue;
            }
            if (!empty($filter['task_order_id']) && !in_array((int) $filter['task_order_id'], $to, true)) {
                continue;
            }
            if (!empty($filter['company_id'])) {
                $cid = (int) $filter['company_id'];
                $viaTo = false;
                foreach ($to as $toId) {
                    if (in_array($cid, $toMap[$toId] ?? [], true)) {
                        $viaTo = true;
                        break;
                    }
                }
                if (!in_array($cid, $co, true) && !$viaTo) {
                    continue;
                }
            }

            $out[] = self::hydrate($r, $programWide, $co, $to, $toMap);
        }
        return $out;
    }

    /** Server-side targeting decision for a non-editor viewer. */
    private static function visibleTo(array $user, int $pid, bool $programWide, array $aud, array $co, array $to, array $toMap): bool
    {
        if ($programWide) {
            return true;
        }
        $roles = $user['memberships'][$pid]['roles'] ?? [];
        $companyId = $user['memberships'][$pid]['company_id'] ?? null;
        $isInternal = array_intersect(self::INTERNAL_ROLES, $roles) !== [];
        if ($isInternal) {
            return true;
        }
        if (in_array('internal', $aud, true) && $isInternal) {
            return true;
        }
        if (in_array('customer', $aud, true) && (($user['kind'] ?? '') === 'customer' || in_array('customer_cor', $roles, true))) {
            return true;
        }
        foreach ($roles as $rk) {
            if (in_array('role:' . $rk, $aud, true)) {
                return true;
            }
        }
        if ($companyId !== null) {
            if (in_array((int) $companyId, $co, true)) {
                return true;   // targeted directly to the user's company
            }
            foreach ($to as $toId) {           // targeted via a contract the company is on
                if (in_array((int) $companyId, $toMap[$toId] ?? [], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function get(int $id, int $programId): ?array
    {
        $r = Db::fetchOne('SELECT * FROM job_requisition WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        if ($r === null) {
            return null;
        }
        $toMap = self::taskOrderCompanyMap($programId);
        $aud = self::arr($r['audience'] ?? null);
        $co = self::intArr($r['company_scope'] ?? null);
        $to = self::intArr($r['task_order_scope'] ?? null);
        $pw = in_array('all', $aud, true) || ($aud === [] && $co === [] && $to === []);
        return self::hydrate($r, $pw, $co, $to, $toMap);
    }

    public static function create(int $programId, array $data, ?int $actorId): int
    {
        $id = Db::insert('job_requisition', [
            'program_id'       => $programId,
            'title'            => $data['title'],
            'audience'         => $data['audience'] ?? [],
            'company_scope'    => $data['company_scope'] ?? [],
            'task_order_scope' => $data['task_order_scope'] ?? [],
            'ats_url'          => $data['ats_url'] ?? null,
            'status'           => 'draft',
            'expire_at'        => $data['expire_at'] ?? null,
        ]);
        Audit::log('job.create', 'job#' . $id, $programId, $actorId);
        return $id;
    }

    public static function update(int $id, int $programId, array $data, ?int $actorId): void
    {
        Db::update('job_requisition', [
            'title'            => $data['title'],
            'audience'         => $data['audience'] ?? [],
            'company_scope'    => $data['company_scope'] ?? [],
            'task_order_scope' => $data['task_order_scope'] ?? [],
            'ats_url'          => $data['ats_url'] ?? null,
            'expire_at'        => $data['expire_at'] ?? null,
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('job.edit', 'job#' . $id, $programId, $actorId);
    }

    public static function publish(int $id, int $programId, ?int $actorId): void
    {
        Db::update('job_requisition', ['status' => 'open'], ['id' => $id, 'program_id' => $programId]);
        Audit::log('job.posted', 'job#' . $id, $programId, $actorId);
        $j = self::get($id, $programId) ?? [];
        Webhooks::dispatch('job.posted', ['id' => $id, 'program_id' => $programId, 'title' => $j['title'] ?? null], $programId);
        $toMap = self::taskOrderCompanyMap($programId);
        Notifications::fanOut(
            $programId, $actorId, 'job.posted',
            (string) ($j['title'] ?? 'Job posting'), '/app/jobs?program_id=' . $programId,
            static fn (array $u): bool => self::visibleTo(
                $u, $programId, (bool) ($j['program_wide'] ?? true),
                $j['audience'] ?? [], $j['company_scope'] ?? [], $j['task_order_scope'] ?? [], $toMap
            )
        );
    }

    public static function close(int $id, int $programId, ?int $actorId): void
    {
        Db::update('job_requisition', ['status' => 'closed'], ['id' => $id, 'program_id' => $programId]);
        Audit::log('job.close', 'job#' . $id, $programId, $actorId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM job_requisition WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('job.delete', 'job#' . $id, $programId, $actorId);
    }

    /** Open reqs for an API client (program-scoped; integration sees all postings). */
    public static function listOpen(int $programId): array
    {
        $toMap = self::taskOrderCompanyMap($programId);
        return array_map(function (array $r) use ($toMap) {
            $aud = self::arr($r['audience'] ?? null);
            $co = self::intArr($r['company_scope'] ?? null);
            $to = self::intArr($r['task_order_scope'] ?? null);
            $pw = in_array('all', $aud, true) || ($aud === [] && $co === [] && $to === []);
            return self::hydrate($r, $pw, $co, $to, $toMap);
        }, Db::fetchAll("SELECT * FROM job_requisition WHERE program_id = :p AND status = 'open' ORDER BY id DESC", ['p' => $programId]));
    }

    /** task_order id => [company ids attached]. */
    private static function taskOrderCompanyMap(int $programId): array
    {
        $map = [];
        foreach (Db::fetchAll('SELECT id, company_scope FROM task_order WHERE program_id = :p', ['p' => $programId]) as $r) {
            $map[(int) $r['id']] = self::intArr($r['company_scope'] ?? null);
        }
        return $map;
    }

    private static function arr(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    /** @return int[] */
    private static function intArr(mixed $v): array
    {
        return array_values(array_map('intval', array_filter(self::arr($v), static fn ($x) => is_numeric($x))));
    }

    private static function hydrate(array $r, bool $programWide, array $co, array $to, array $toMap): array
    {
        return [
            'id'               => (int) $r['id'],
            'title'            => $r['title'],
            'status'           => $r['status'],
            'audience'         => self::arr($r['audience'] ?? null),
            'company_scope'    => $co,
            'task_order_scope' => $to,
            'program_wide'     => $programWide,
            'ats_url'          => $r['ats_url'],
            'expire_at'        => $r['expire_at'],
        ];
    }
}
