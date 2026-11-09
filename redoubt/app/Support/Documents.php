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

    /** Max portal upload size. Large artifacts belong in SharePoint (link instead). */
    public const MAX_UPLOAD_BYTES = 26214400; // 25 MB

    /**
     * Upload allowlist: extension => acceptable detected MIME types. Detection is
     * by file content (finfo), not the client-supplied type. OOXML/visio/drawio
     * are zip or xml containers, so those detected types are accepted for them.
     */
    public const ALLOWED_UPLOAD = [
        'png'    => ['image/png'],
        'jpg'    => ['image/jpeg'],
        'jpeg'   => ['image/jpeg'],
        'gif'    => ['image/gif'],
        'webp'   => ['image/webp'],
        'svg'    => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'],
        'pdf'    => ['application/pdf'],
        'txt'    => ['text/plain'],
        'csv'    => ['text/plain', 'text/csv', 'application/csv'],
        'drawio' => ['text/xml', 'application/xml', 'text/plain'],
        'vsdx'   => ['application/zip', 'application/octet-stream', 'application/vnd.ms-visio.drawing'],
        'docx'   => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx'   => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx'   => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    /**
     * Validate and store an uploaded file (a $_FILES[...] entry). Enforces the size
     * cap, an extension allowlist, and a content-sniffed MIME that must match the
     * extension; stores it under a RANDOM filename (never the original) outside the
     * web root. Returns metadata to persist. Throws on any validation failure.
     *
     * @param array<string,mixed> $file
     * @return array{stored_path:string,original_name:string,mime_type:string,size_bytes:int}
     */
    public static function storeUpload(array $file): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
                ? 'The file is larger than the server allows.' : 'File upload failed (code ' . $err . ').');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new \RuntimeException('No uploaded file was received.');
        }
        $size = (int) ($file['size'] ?? @filesize($tmp) ?: 0);
        if ($size <= 0) {
            throw new \RuntimeException('The uploaded file is empty.');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('The file exceeds the ' . (int) (self::MAX_UPLOAD_BYTES / 1048576) . ' MB limit. Link it from SharePoint instead.');
        }
        $orig = trim((string) ($file['name'] ?? 'file'));
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_UPLOAD[$ext])) {
            throw new \RuntimeException('File type ".' . $ext . '" is not permitted.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: 'application/octet-stream');
        if (!in_array($mime, self::ALLOWED_UPLOAD[$ext], true)) {
            throw new \RuntimeException('The file contents (' . $mime . ') do not match a .' . $ext . ' file.');
        }
        $dir = Config::uploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('The upload directory could not be created.');
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $stored;
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
        if (!$moved) {
            throw new \RuntimeException('The uploaded file could not be stored.');
        }
        @chmod($dest, 0640);
        return [
            'stored_path'   => $stored,            // basename only; resolved against uploadDir on read
            'original_name' => $orig !== '' ? $orig : ('file.' . $ext),
            'mime_type'     => $mime,
            'size_bytes'    => $size,
        ];
    }

    /**
     * Absolute path of a document's stored upload, or null if it has none / the
     * file is missing. The stored name is forced to a basename so a tampered DB
     * value can never traverse outside the upload directory.
     */
    public static function storedFilePath(array $row): ?string
    {
        $name = (string) ($row['stored_path'] ?? '');
        if ($name === '') {
            return null;
        }
        $name = basename($name);
        $path = Config::uploadDir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

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
            'stored_path'       => $data['stored_path'] ?? null,
            'original_name'     => $data['original_name'] ?? null,
            'mime_type'         => $data['mime_type'] ?? null,
            'size_bytes'        => $data['size_bytes'] ?? null,
            'updated_at'        => date('c'),
        ]);
        Audit::log('document.register', 'document#' . $id . ' zone=' . ($data['zone'] ?? 'project') . (!empty($data['stored_path']) ? ' upload' : ''), $programId, $actorId);
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
        $row = self::get($id, $programId);
        if ($row !== null) {
            $path = self::storedFilePath($row);
            if ($path !== null) {
                @unlink($path); // best-effort cleanup of the portal-stored blob
            }
        }
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
            'has_file'          => !empty($r['stored_path']),
            'original_name'     => $r['original_name'] ?? null,
            'mime_type'         => $r['mime_type'] ?? null,
            'size_bytes'        => isset($r['size_bytes']) && $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null,
            'updated_at'        => $r['updated_at'],
        ];
    }
}
