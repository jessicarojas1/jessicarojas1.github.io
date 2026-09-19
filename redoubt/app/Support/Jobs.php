<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Job Requisitions module service (Annex H). Program-scoped and audience-trimmed.
 * Non-editors see only posted (status='open') reqs within their window; editors
 * (job.edit) see all. Posting a req emits a signed webhook + audit.
 * Statuses: draft | open | filled | closed.
 */
final class Jobs
{
    public const STATUSES = ['draft', 'open', 'filled', 'closed'];

    public static function listForUser(array $user, int $programId): array
    {
        $rows = Db::fetchAll('SELECT * FROM job_requisition WHERE program_id = :p ORDER BY id DESC', ['p' => $programId]);
        $isEditor = Authorize::can($user, 'job.edit', ['program_id' => $programId]);
        $out = [];
        foreach ($rows as $r) {
            if (!$isEditor) {
                if (($r['status'] ?? '') !== 'open') {
                    continue;
                }
                $exp = $r['expire_at'] ? strtotime((string) $r['expire_at']) : null;
                if ($exp !== null && $exp <= time()) {
                    continue;
                }
                if (!Audience::visible($user, Audience::decode($r['audience'] ?? null), $programId)) {
                    continue;
                }
            }
            $out[] = self::hydrate($r);
        }
        return $out;
    }

    public static function get(int $id, int $programId): ?array
    {
        $r = Db::fetchOne('SELECT * FROM job_requisition WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        return $r ? self::hydrate($r) : null;
    }

    public static function create(int $programId, array $data, ?int $actorId): int
    {
        $id = Db::insert('job_requisition', [
            'program_id' => $programId,
            'title'      => $data['title'],
            'audience'   => $data['audience'] ?? [],
            'ats_url'    => $data['ats_url'] ?? null,
            'status'     => 'draft',
            'expire_at'  => $data['expire_at'] ?? null,
        ]);
        Audit::log('job.create', 'job#' . $id, $programId, $actorId);
        return $id;
    }

    public static function update(int $id, int $programId, array $data, ?int $actorId): void
    {
        Db::update('job_requisition', [
            'title'     => $data['title'],
            'audience'  => $data['audience'] ?? [],
            'ats_url'   => $data['ats_url'] ?? null,
            'expire_at' => $data['expire_at'] ?? null,
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('job.edit', 'job#' . $id, $programId, $actorId);
    }

    /** Post the requisition (draft -> open) and notify subscribers. */
    public static function publish(int $id, int $programId, ?int $actorId): void
    {
        Db::update('job_requisition', ['status' => 'open'], ['id' => $id, 'program_id' => $programId]);
        Audit::log('job.posted', 'job#' . $id, $programId, $actorId);
        $j = self::get($id, $programId);
        Webhooks::dispatch('job.posted', ['id' => $id, 'program_id' => $programId, 'title' => $j['title'] ?? null], $programId);
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

    /** Open reqs for an API client (program-scoped). */
    public static function listOpen(int $programId): array
    {
        return array_map([self::class, 'hydrate'],
            Db::fetchAll("SELECT * FROM job_requisition WHERE program_id = :p AND status = 'open' ORDER BY id DESC", ['p' => $programId]));
    }

    private static function hydrate(array $r): array
    {
        return [
            'id'        => (int) $r['id'],
            'title'     => $r['title'],
            'status'    => $r['status'],
            'audience'  => Audience::decode($r['audience'] ?? null),
            'ats_url'   => $r['ats_url'],
            'expire_at' => $r['expire_at'],
        ];
    }
}
