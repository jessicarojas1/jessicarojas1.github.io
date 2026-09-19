<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Program Directory module service (Annex H). Program-scoped contacts with
 * visibility trimming (Audience tokens). Managers (contact.manage) see all;
 * other viewers see only contacts whose visibility includes them.
 */
final class Directory
{
    public static function listForUser(array $user, int $programId): array
    {
        $rows = Db::fetchAll(
            'SELECT pc.*, u.display_name, u.email, c.name AS company_name
               FROM program_contact pc
               LEFT JOIN app_user u ON u.id = pc.user_id
               LEFT JOIN company c ON c.id = pc.company_id
              WHERE pc.program_id = :p
              ORDER BY COALESCE(u.display_name, pc.freeform)',
            ['p' => $programId]
        );
        $canManage = Authorize::can($user, 'contact.manage', ['program_id' => $programId]);
        $out = [];
        foreach ($rows as $r) {
            if (!$canManage && !Audience::visible($user, Audience::decode($r['visibility'] ?? null), $programId)) {
                continue;
            }
            $out[] = self::hydrate($r);
        }
        return $out;
    }

    public static function get(int $id, int $programId): ?array
    {
        $r = Db::fetchOne('SELECT * FROM program_contact WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        return $r ?: null;
    }

    public static function create(int $programId, array $data, ?int $actorId): int
    {
        $id = Db::insert('program_contact', [
            'program_id' => $programId,
            'user_id'    => $data['user_id'] ?? null,
            'freeform'   => $data['freeform'] ?? null,
            'role_label' => $data['role_label'] ?? null,
            'company_id' => $data['company_id'] ?? null,
            'visibility' => $data['visibility'] ?? [],
        ]);
        Audit::log('contact.create', 'contact#' . $id, $programId, $actorId);
        return $id;
    }

    public static function update(int $id, int $programId, array $data, ?int $actorId): void
    {
        Db::update('program_contact', [
            'freeform'   => $data['freeform'] ?? null,
            'role_label' => $data['role_label'] ?? null,
            'company_id' => $data['company_id'] ?? null,
            'visibility' => $data['visibility'] ?? [],
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('contact.edit', 'contact#' . $id, $programId, $actorId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM program_contact WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('contact.delete', 'contact#' . $id, $programId, $actorId);
    }

    /** All contacts for an API client (program-scoped). */
    public static function listForProgram(int $programId): array
    {
        return array_map([self::class, 'hydrate'], Db::fetchAll(
            'SELECT pc.*, u.display_name, u.email, c.name AS company_name
               FROM program_contact pc
               LEFT JOIN app_user u ON u.id = pc.user_id
               LEFT JOIN company c ON c.id = pc.company_id
              WHERE pc.program_id = :p ORDER BY pc.id',
            ['p' => $programId]
        ));
    }

    private static function hydrate(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'name'       => $r['display_name'] ?? $r['freeform'] ?? '(unnamed)',
            'email'      => $r['email'] ?? null,
            'role_label' => $r['role_label'] ?? null,
            'company'    => $r['company_name'] ?? null,
            'visibility' => Audience::decode($r['visibility'] ?? null),
        ];
    }
}
