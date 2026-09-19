<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Task Orders module service (Annex H). Program-scoped and company-scoped:
 * subcontractors see only task orders scoped to their company (or unscoped);
 * internal roles see all. Approving emits an audit event and a signed webhook.
 *
 * company_scope (JSONB): array of company ids, or empty/['all'] for all companies.
 * Statuses: draft | active | awarded | closed.
 */
final class TaskOrders
{
    public const STATUSES = ['draft', 'active', 'awarded', 'closed'];

    public static function listForUser(array $user, int $programId): array
    {
        $rows = Db::fetchAll(
            'SELECT * FROM task_order WHERE program_id = :p ORDER BY id DESC',
            ['p' => $programId]
        );
        $out = [];
        foreach ($rows as $r) {
            if (self::canSee($user, $r, $programId)) {
                $out[] = self::hydrate($r);
            }
        }
        return $out;
    }

    public static function canSee(array $user, array $row, int $programId): bool
    {
        $ctx = ['program_id' => $programId];
        $scope = self::decodeArr($row['company_scope'] ?? null);
        $scope = array_values(array_filter($scope, static fn ($v) => $v !== 'all'));
        if ($scope !== []) {
            $ctx['company_scope'] = $scope;
        }
        return Authorize::can($user, 'taskorder.view', $ctx);
    }

    public static function get(int $id, int $programId): ?array
    {
        $r = Db::fetchOne('SELECT * FROM task_order WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        return $r ? self::hydrate($r) : null;
    }

    public static function create(int $programId, array $data, ?int $actorId): int
    {
        $id = Db::insert('task_order', [
            'program_id'    => $programId,
            'number'        => $data['number'],
            'title'         => $data['title'] ?? null,
            'status'        => in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'draft',
            'company_scope' => $data['company_scope'] ?? [],
            'sp_link'       => $data['sp_link'] ?? null,
        ]);
        Audit::log('taskorder.create', 'taskorder#' . $id, $programId, $actorId);
        return $id;
    }

    public static function update(int $id, int $programId, array $data, ?int $actorId): void
    {
        Db::update('task_order', [
            'number'        => $data['number'],
            'title'         => $data['title'] ?? null,
            'status'        => in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'draft',
            'company_scope' => $data['company_scope'] ?? [],
            'sp_link'       => $data['sp_link'] ?? null,
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('taskorder.edit', 'taskorder#' . $id, $programId, $actorId);
    }

    /** Mark a task order awarded and notify subscribers. */
    public static function approve(int $id, int $programId, ?int $actorId): void
    {
        Db::update('task_order', ['status' => 'awarded'], ['id' => $id, 'program_id' => $programId]);
        Audit::log('taskorder.approve', 'taskorder#' . $id, $programId, $actorId);
        $to = self::get($id, $programId);
        Webhooks::dispatch('taskorder.awarded', [
            'id' => $id, 'program_id' => $programId,
            'number' => $to['number'] ?? null, 'title' => $to['title'] ?? null,
        ], $programId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM task_order WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('taskorder.delete', 'taskorder#' . $id, $programId, $actorId);
    }

    /** Task orders for an API client (program-scoped). */
    public static function listForProgram(int $programId): array
    {
        return array_map([self::class, 'hydrate'],
            Db::fetchAll('SELECT * FROM task_order WHERE program_id = :p ORDER BY id DESC', ['p' => $programId]));
    }

    private static function decodeArr(mixed $v): array
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

    private static function hydrate(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'number'        => $r['number'],
            'title'         => $r['title'],
            'status'        => $r['status'],
            'company_scope' => self::decodeArr($r['company_scope'] ?? null),
            'sp_link'       => $r['sp_link'],
        ];
    }
}
