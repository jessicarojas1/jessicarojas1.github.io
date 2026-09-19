<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Documents module service (Annex H). Documents live in SharePoint (system of
 * record); this service manages the portal's metadata/reference rows and gates
 * access through the Authorize engine — including the ITAR/EAR US-person export
 * gate and company scoping. The portal never returns content the caller cannot see.
 *
 * Zone → required permission (all evaluated with export + company-scope context):
 */
final class Documents
{
    private const ZONE_PERM = [
        'project'              => 'document.view',
        'subcontractor_shared' => 'document.view',
        'company'              => 'document.view',
        'customer'             => 'document.view.customer',
        'contracts'            => 'document.view.contracts',
        'financial'            => 'finance.view',
    ];

    public const ZONES = ['project', 'subcontractor_shared', 'company', 'customer', 'contracts', 'financial'];

    /** Documents in a program the user may see, optionally filtered by zone. */
    public static function listForUser(array $user, int $programId, ?string $zone = null): array
    {
        $sql = 'SELECT * FROM document_ref WHERE program_id = :p';
        $params = ['p' => $programId];
        if ($zone !== null && in_array($zone, self::ZONES, true)) {
            $sql .= ' AND zone = :z';
            $params['z'] = $zone;
        }
        $sql .= ' ORDER BY COALESCE(updated_at, NOW()) DESC, id DESC';
        $out = [];
        foreach (Db::fetchAll($sql, $params) as $r) {
            if (self::canSee($user, $r, $programId)) {
                $out[] = self::hydrate($r);
            }
        }
        return $out;
    }

    /** Authorization for a single document row (defense-in-depth: used by open() too). */
    public static function canSee(array $user, array $row, int $programId): bool
    {
        $zone = (string) $row['zone'];
        $perm = self::ZONE_PERM[$zone] ?? 'document.view';
        $ctx = [
            'program_id'        => $programId,
            'export_controlled' => (bool) ($row['export_controlled'] ?? false),
        ];
        $scope = self::decodeArr($row['company_scope'] ?? null);
        if ($scope !== []) {
            $ctx['company_scope'] = $scope;
        }
        return Authorize::can($user, $perm, $ctx);
    }

    public static function get(int $id, int $programId): ?array
    {
        $r = Db::fetchOne('SELECT * FROM document_ref WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        return $r ?: null;
    }

    /** Register a document reference (metadata pointing at the SharePoint item). */
    public static function register(int $programId, array $data, ?int $actorId): int
    {
        $id = Db::insert('document_ref', [
            'program_id'        => $programId,
            'zone'              => in_array($data['zone'] ?? '', self::ZONES, true) ? $data['zone'] : 'project',
            'company_scope'     => $data['company_scope'] ?? [],
            'export_controlled' => !empty($data['export_controlled']),
            'cui_marked'        => !empty($data['cui_marked']),
            'sp_item_id'        => $data['sp_item_id'] ?? null,
            'web_url'           => $data['web_url'] ?? null,
            'title'             => $data['title'] ?? null,
            'updated_at'        => date('c'),
        ]);
        Audit::log('document.register', 'document#' . $id . ' zone=' . ($data['zone'] ?? 'project'), $programId, $actorId);
        return $id;
    }

    public static function update(int $id, int $programId, array $data, ?int $actorId): void
    {
        Db::update('document_ref', [
            'zone'              => in_array($data['zone'] ?? '', self::ZONES, true) ? $data['zone'] : 'project',
            'company_scope'     => $data['company_scope'] ?? [],
            'export_controlled' => !empty($data['export_controlled']),
            'cui_marked'        => !empty($data['cui_marked']),
            'web_url'           => $data['web_url'] ?? null,
            'title'             => $data['title'] ?? null,
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('document.edit', 'document#' . $id, $programId, $actorId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM document_ref WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('document.delete', 'document#' . $id, $programId, $actorId);
    }

    /**
     * Resolve the URL to open a document. Prefers a live Graph download URL when
     * Graph is configured and an item id is present; falls back to the stored
     * web URL. Caller MUST have authorized via canSee() first.
     */
    public static function resolveOpenUrl(array $row): ?string
    {
        if (!empty($row['sp_item_id']) && Graph::isConfigured()) {
            try {
                // Requires the drive/site context in a real deployment; kept minimal here.
                $item = Graph::get('/v1.0/shares/' . rawurlencode('u!' . rtrim(strtr(base64_encode((string) ($row['web_url'] ?? '')), '+/', '-_'), '=')) . '/driveItem');
                if (!empty($item['@microsoft.graph.downloadUrl'])) {
                    return (string) $item['@microsoft.graph.downloadUrl'];
                }
                if (!empty($item['webUrl'])) {
                    return (string) $item['webUrl'];
                }
            } catch (\Throwable $e) {
                error_log('[DOCS] Graph resolve failed: ' . $e->getMessage());
            }
        }
        return $row['web_url'] ?? null;
    }

    /** Published/visible docs for an API client — export-controlled excluded (no US-person context). */
    public static function listForApiClient(int $programId): array
    {
        $rows = Db::fetchAll(
            'SELECT * FROM document_ref WHERE program_id = :p AND export_controlled = FALSE ORDER BY id DESC',
            ['p' => $programId]
        );
        return array_map([self::class, 'hydrate'], $rows);
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
            'id'                => (int) $r['id'],
            'title'             => $r['title'],
            'zone'              => $r['zone'],
            'company_scope'     => self::decodeArr($r['company_scope'] ?? null),
            'export_controlled' => (bool) $r['export_controlled'],
            'cui_marked'        => (bool) $r['cui_marked'],
            'web_url'           => $r['web_url'],
            'updated_at'        => $r['updated_at'],
        ];
    }
}
