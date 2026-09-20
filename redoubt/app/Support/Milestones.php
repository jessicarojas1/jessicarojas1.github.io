<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Milestones / Key Dates module (Annex H). Program-scoped calendar of CDRLs,
 * deliverables, and events. Visible to any program member (milestone.view);
 * managed with milestone.manage. Feeds the home dashboard's "Upcoming".
 */
final class Milestones
{
    public const TYPES = ['CDRL', 'deliverable', 'event'];

    /** @return array<int,array<string,mixed>> all milestones for a program (soonest first) */
    public static function listForProgram(int $programId): array
    {
        return array_map([self::class, 'hydrate'], Db::fetchAll(
            'SELECT * FROM milestone WHERE program_id = :p ORDER BY due_date NULLS LAST, id',
            ['p' => $programId]
        ));
    }

    /** @return array<int,array<string,mixed>> upcoming (today onward), soonest first */
    public static function upcoming(int $programId, int $limit = 5): array
    {
        return array_map([self::class, 'hydrate'], Db::fetchAll(
            'SELECT * FROM milestone WHERE program_id = :p AND due_date >= CURRENT_DATE ORDER BY due_date ASC LIMIT :l',
            ['p' => $programId, 'l' => $limit]
        ));
    }

    public static function create(int $programId, array $d, ?int $actorId): int
    {
        $id = Db::insert('milestone', [
            'program_id' => $programId,
            'title'      => (string) ($d['title'] ?? ''),
            'due_date'   => $d['due_date'] ?: null,
            'type'       => in_array($d['type'] ?? '', self::TYPES, true) ? $d['type'] : 'event',
        ]);
        Audit::log('milestone.create', 'milestone#' . $id, $programId, $actorId);
        return $id;
    }

    public static function update(int $id, int $programId, array $d, ?int $actorId): void
    {
        Db::update('milestone', [
            'title'    => (string) ($d['title'] ?? ''),
            'due_date' => $d['due_date'] ?: null,
            'type'     => in_array($d['type'] ?? '', self::TYPES, true) ? $d['type'] : 'event',
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('milestone.edit', 'milestone#' . $id, $programId, $actorId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM milestone WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('milestone.delete', 'milestone#' . $id, $programId, $actorId);
    }

    private static function hydrate(array $r): array
    {
        $due = $r['due_date'] ? strtotime((string) $r['due_date']) : null;
        return [
            'id'         => (int) $r['id'],
            'title'      => $r['title'],
            'due_date'   => $r['due_date'],
            'type'       => $r['type'],
            'days_out'   => $due !== null ? (int) floor(($due - strtotime('today')) / 86400) : null,
            'is_past'    => $due !== null && $due < strtotime('today'),
        ];
    }
}
