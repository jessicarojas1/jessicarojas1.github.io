<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Admin CRUD for API clients (machine access to /api/v1). The secret is shown
 * exactly once at creation; only its SHA-256 hash is stored. Program-scoped.
 */
final class ApiClients
{
    /** Scopes an API client can hold (read endpoints + wildcard). */
    public const SCOPES = [
        '*'                 => 'All read endpoints',
        'announcement.view' => 'Announcements',
        'document.view'     => 'Documents (export-controlled always excluded)',
        'taskorder.view'    => 'Task Orders',
        'job.view'          => 'Jobs',
        'directory.view'    => 'Directory',
        'milestone.view'    => 'Milestones',
    ];

    /** Create a client; returns the one-time plaintext token. */
    public static function create(int $programId, string $name, array $scopes, ?int $actorId): string
    {
        [$plain, $ref, $hash] = ApiKey::generate();
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), $scopes));
        Db::insert('api_client', [
            'client_ref' => $ref,
            'name'       => $name !== '' ? $name : 'API client',
            'program_id' => $programId,
            'key_hash'   => $hash,
            'scopes'     => $scopes,
            'active'     => true,
        ]);
        Audit::log('apiclient.create', 'client ' . $ref . ' [' . implode(',', $scopes) . ']', $programId, $actorId);
        return $plain;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listForProgram(int $programId): array
    {
        return array_map(static function (array $r): array {
            return [
                'id'         => (int) $r['id'],
                'client_ref' => $r['client_ref'],
                'name'       => $r['name'],
                'scopes'     => is_string($r['scopes']) ? (json_decode($r['scopes'], true) ?: []) : ($r['scopes'] ?? []),
                'active'     => (bool) $r['active'],
                'created'    => $r['created'],
                'last_used'  => $r['last_used'],
            ];
        }, Db::fetchAll(
            "SELECT id, client_ref, name, scopes, active,
                    to_char(created_at,'YYYY-MM-DD') created,
                    to_char(last_used_at,'YYYY-MM-DD HH24:MI') last_used
               FROM api_client WHERE program_id = :p ORDER BY created_at DESC",
            ['p' => $programId]
        ));
    }

    public static function revoke(int $id, int $programId, ?int $actorId): void
    {
        Db::query('UPDATE api_client SET active = FALSE WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('apiclient.revoke', 'client#' . $id, $programId, $actorId);
    }
}
