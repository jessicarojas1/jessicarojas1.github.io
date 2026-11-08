<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Inbound integration connectors — registrations of external systems of record
 * (ATS, Finance, Contracts, Teams, SharePoint/Graph). Config is stored as JSON;
 * secrets live in the secret manager and are referenced by name (secret_ref),
 * never stored here. Program-scoped; managed under integration.config.
 */
final class Connectors
{
    public const KINDS = [
        'ats'        => 'ATS / Recruiting',
        'finance'    => 'Finance',
        'contracts'  => 'Contracts',
        'teams'      => 'Microsoft Teams',
        'sharepoint' => 'SharePoint / Graph',
        'other'      => 'Other',
    ];

    public static function create(int $programId, string $kind, array $config, ?string $secretRef, ?int $actorId): int
    {
        $kind = isset(self::KINDS[$kind]) ? $kind : 'other';
        $id = Db::insert('integration_connector', [
            'program_id' => $programId,
            'kind'       => $kind,
            'config'     => $config,
            'secret_ref' => $secretRef !== '' ? $secretRef : null,
            'active'     => true,
        ]);
        Audit::log('connector.create', $kind . '#' . $id, $programId, $actorId);
        return $id;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listForProgram(int $programId): array
    {
        return array_map(static function (array $r): array {
            return [
                'id'         => (int) $r['id'],
                'kind'       => $r['kind'],
                'config'     => is_string($r['config']) ? (json_decode($r['config'], true) ?: []) : ($r['config'] ?? []),
                'secret_ref' => $r['secret_ref'],
                'active'     => (bool) $r['active'],
            ];
        }, Db::fetchAll('SELECT id, kind, config, secret_ref, active FROM integration_connector WHERE program_id = :p ORDER BY kind, id', ['p' => $programId]));
    }

    public static function setActive(int $id, int $programId, bool $active, ?int $actorId): void
    {
        Db::query('UPDATE integration_connector SET active = :a WHERE id = :id AND program_id = :p', ['a' => $active ? 'true' : 'false', 'id' => $id, 'p' => $programId]);
        Audit::log('connector.' . ($active ? 'enable' : 'disable'), 'connector#' . $id, $programId, $actorId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM integration_connector WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('connector.delete', 'connector#' . $id, $programId, $actorId);
    }
}
